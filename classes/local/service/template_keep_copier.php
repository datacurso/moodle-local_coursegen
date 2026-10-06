<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_coursegen\local\service;

use local_coursegen\local\models\template;
use local_coursegen\local\template\template_actions;
use local_coursegen\local\template\template_repository;

/**
 * Copies a template's "keep" activities into the generated course.
 *
 * These activities already exist, fully configured, in the base course:
 * rebuilding them from a JSON payload would mean re-implementing an exporter
 * for every Moodle module type and would still lose their files, so they are
 * duplicated with Moodle's own backup/restore-backed duplicate_module()
 * instead - the same mechanism the course page's own "Duplicate" uses.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_keep_copier {
    /**
     * Copy every kept activity of one template into a target course.
     *
     * @param int $templateid
     * @param int $targetcourseid
     * @param array $createdcmids Filled with base course cmid => cmid of its copy in the
     *     target course, for every activity that was copied.
     * @return array Names of the activities that could not be copied.
     */
    public static function copy_into(
        int $templateid,
        int $targetcourseid,
        array &$createdcmids = []
    ): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $template = template::get_record(['id' => $templateid]);
        if (!$template) {
            return [];
        }

        $sourcecourseid = $template->get('courseid');
        if (!self::course_exists($sourcecourseid)) {
            // The template's own base course is gone: get_course() would
            // throw, and by the time this runs the target course already
            // has every AI-generated activity in it, so that has to survive
            // intact rather than be lost to an exception over activities
            // there is nothing left to copy.
            return [];
        }
        $sourcecourse = get_course($sourcecourseid);
        $targetcourse = get_course($targetcourseid);
        $modinfo = get_fast_modinfo($sourcecourse);
        $keepcmids = self::kept_cmids($templateid, $modinfo);
        $targetsectionids = self::target_section_ids($targetcourse);

        $failures = self::copy_kept_activities($keepcmids, $modinfo, $targetcourse, $targetsectionids, $createdcmids);

        rebuild_course_cache($targetcourseid, true);
        return $failures;
    }

    /**
     * Whether a course record still exists for this id.
     *
     * get_course() throws when it does not, which is too blunt for a course
     * the professor is free to delete out from under a pending run.
     *
     * @param int $courseid
     * @return bool
     */
    private static function course_exists(int $courseid): bool {
        global $DB;
        return $DB->record_exists('course', ['id' => $courseid]);
    }

    /**
     * Every section of the target course, keyed by its own number.
     *
     * duplicate_module() takes the target section's id, not its number, so
     * this is what turns a source activity's section number into the id the
     * copy has to land in.
     *
     * @param \stdClass $targetcourse
     * @return array Section number => section id.
     */
    private static function target_section_ids($targetcourse): array {
        $targetmodinfo = get_fast_modinfo($targetcourse);
        $sections = $targetmodinfo->get_section_info_all();

        $targetsectionids = [];
        foreach ($sections as $section) {
            $sectionnumber = $section->section;
            $sectionnumber = (int) $sectionnumber;
            $sectionid = $section->id;
            $sectionid = (int) $sectionid;
            $targetsectionids[$sectionnumber] = $sectionid;
        }
        return $targetsectionids;
    }

    /**
     * Copy every kept cmid into its matching section.
     *
     * $keepcmids comes from kept_cmids() reading this same $modinfo, so
     * every cmid here is already one of $modinfo's own cms - a target
     * section it cannot resolve is reported as a failure for that one
     * activity, not a reason to fail the whole course.
     *
     * @param int[] $keepcmids From kept_cmids(), reading this same $modinfo.
     * @param \course_modinfo $modinfo The base course's own modinfo.
     * @param \stdClass $targetcourse
     * @param array $targetsectionids Section number => section id, from target_section_ids().
     * @param array $createdcmids Filled with base course cmid => cmid of its copy.
     * @return array Names of the activities that could not be copied.
     */
    private static function copy_kept_activities(
        array $keepcmids,
        $modinfo,
        $targetcourse,
        array $targetsectionids,
        array &$createdcmids
    ): array {
        $cms = $modinfo->get_cms();

        $failures = [];
        foreach ($keepcmids as $cmid) {
            $cm = $cms[$cmid];
            $sectionnumber = $cm->sectionnum;
            $sectionnumber = (int) $sectionnumber;
            $sectionid = $targetsectionids[$sectionnumber] ?? null;
            $createdcmid = null;
            if ($sectionid !== null) {
                $createdcmid = self::copy_one($cm, $targetcourse, $sectionid);
            }
            if ($createdcmid === null) {
                $failures[] = $cm->name;
                continue;
            }
            $createdcmids[(int) $cm->id] = $createdcmid;
        }
        return $failures;
    }

    /**
     * The cmids the AI does not modify, in course order.
     *
     * An activity with no saved row defaults to keep: the AI never touches what the admin did not mark.
     *
     * @param int $templateid
     * @param \course_modinfo $modinfo
     * @return int[]
     */
    private static function kept_cmids(int $templateid, $modinfo): array {
        $repository = new template_repository();
        $items = $repository->items_of($templateid);
        $cms = $modinfo->get_cms();

        $cmids = [];
        foreach ($cms as $cm) {
            $action = template_actions::KEEP;
            if (isset($items[$cm->id])) {
                $action = $items[$cm->id]->action;
            }
            if ($action === template_actions::KEEP) {
                $cmids[] = (int) $cm->id;
            }
        }
        return $cmids;
    }

    /**
     * Duplicate one activity into the target course, in the matching section.
     *
     * @param \cm_info $cm Source activity, from the base course.
     * @param \stdClass $targetcourse
     * @param int $sectionid Target section id (not its number).
     * @return int|null The cmid of the copy, or null when the copy failed.
     */
    private static function copy_one($cm, $targetcourse, int $sectionid): ?int {
        $targetmodinfo = get_fast_modinfo($targetcourse);
        $targetcms = $targetmodinfo->get_cms();
        $before = array_keys($targetcms);

        // Moodle's own duplication path prints HTML straight to the output
        // buffer on its way through (course/lib.php echoes a notification when
        // it cannot tidy the source section, among others). Harmless on the
        // CLI, fatal for a webservice: that markup lands in front of the JSON
        // body and the caller fails with "Unexpected token '<'". Anything the
        // copy prints is captured here and logged instead.
        ob_start();
        $placed = null;
        $coursefailed = false;
        try {
            $placed = duplicate_module($targetcourse, $cm, $sectionid, false);
        } catch (\Throwable $exception) {
            // duplicate_module() resolves its $sectionid with
            // ['id' => $sectionid, 'course' => $cm->course] - the SOURCE
            // course - so a target-course section id never matches a record
            // and its own moveto_module() call fails on a false one. That
            // happens AFTER the backup/restore, so the activity itself is
            // already copied, with its files and configuration intact; only
            // its placement and the creation event are missing, and both are
            // completed below. A copy that genuinely failed leaves no new
            // module behind and is reported as a failure there.
            $coursefailed = true;
        } finally {
            $buffered = ob_get_clean();
            $buffered = (string) $buffered;
            $printed = trim($buffered);
            if ($printed !== '') {
                debugging(
                    'local_coursegen: output swallowed while copying cmid ' . $cm->id . ': ' . $printed,
                    DEBUG_DEVELOPER
                );
            }
        }

        if (!$coursefailed) {
            if ($placed === null) {
                return null;
            }
            return (int) $placed->id;
        }

        return self::recover_misplaced_copy($cm, $targetcourse, $sectionid, $before);
    }

    /**
     * Find and place the copy duplicate_module() made but could not move,
     * after it failed resolving the target section against the wrong course.
     *
     * @param \cm_info $cm Source activity, from the base course.
     * @param \stdClass $targetcourse
     * @param int $sectionid Target section id (not its number).
     * @param int[] $before Target course cmids, from just before the copy.
     * @return int|null The cmid of the copy, or null when it could not be found.
     */
    private static function recover_misplaced_copy($cm, $targetcourse, int $sectionid, array $before): ?int {
        global $DB;

        $targetcourseid = $targetcourse->id;
        rebuild_course_cache($targetcourseid, true);
        $targetmodinfo = get_fast_modinfo($targetcourse);
        $targetcms = $targetmodinfo->get_cms();
        $aftercmids = array_keys($targetcms);
        $newcmids = array_diff($aftercmids, $before);
        $newcmids = array_values($newcmids);
        if (count($newcmids) !== 1) {
            debugging(
                'local_coursegen: could not locate the copy of kept activity ' . $cm->id,
                DEBUG_DEVELOPER
            );
            return null;
        }

        $newcmid = $newcmids[0];
        $newcm = $targetcms[$newcmid];
        $newcmsection = $newcm->section;
        $newcmsection = (int) $newcmsection;
        if ($newcmsection !== $sectionid) {
            $section = $DB->get_record(
                'course_sections',
                ['id' => $sectionid, 'course' => $targetcourseid],
                '*',
                MUST_EXIST
            );
            moveto_module($newcm, $section);
        }

        \core\event\course_module_created::create_from_cm($newcm)->trigger();
        return (int) $newcmid;
    }
}
