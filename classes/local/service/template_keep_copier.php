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
use local_coursegen\local\models\template_activity;

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
     * Each copy lands at the current end of its section; the mold order is
     * restored afterwards by template_layout_service.
     *
     * @param int $templateid
     * @param int $targetcourseid
     * @return array<int,int> Source cmid => new cmid for every activity that was copied;
     *     the ones that could not be copied are reported through debugging().
     */
    public static function copy_into(int $templateid, int $targetcourseid): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $template = template::get_record(['id' => $templateid]);
        if (!$template) {
            return [];
        }

        $sourcecourse = get_course($template->get('courseid'));
        $targetcourse = get_course($targetcourseid);
        $modinfo = get_fast_modinfo($sourcecourse);
        $keepcmids = self::kept_cmids($templateid, $modinfo);

        // duplicate_module() takes the TARGET section's id, not its number.
        $targetsectionids = [];
        foreach (get_fast_modinfo($targetcourse)->get_section_info_all() as $section) {
            $targetsectionids[(int) $section->section] = (int) $section->id;
        }

        $copies = [];
        foreach ($keepcmids as $cmid) {
            $cm = $modinfo->get_cm($cmid);
            $sectionid = $targetsectionids[(int) $cm->sectionnum] ?? null;
            $newcmid = $sectionid === null ? null : self::copy_one($cm, $targetcourse, $sectionid);
            if ($newcmid === null) {
                debugging('local_coursegen: kept activity "' . $cm->name . '" (cmid ' . $cm->id . ') was not copied.');
                continue;
            }
            $copies[(int) $cm->id] = $newcmid;
        }

        rebuild_course_cache($targetcourseid, true);
        return $copies;
    }

    /**
     * The cmids saved as "keep" for this template, in course order.
     *
     * An activity with no saved row defaults to keep - same default the
     * professor-facing structure uses.
     *
     * @param int $templateid
     * @param \course_modinfo $modinfo
     * @return int[]
     */
    private static function kept_cmids(int $templateid, $modinfo): array {
        $actions = [];
        foreach (template_activity::get_records(['templateid' => $templateid]) as $activity) {
            $actions[(int) $activity->get('cmid')] = $activity->get('action');
        }

        $cmids = [];
        foreach ($modinfo->get_cms() as $cm) {
            $action = $actions[$cm->id] ?? 'keep';
            if ($action === 'keep' || $action === 'reference') {
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
     * @return int|null The new cmid, or null when the copy failed.
     */
    private static function copy_one($cm, $targetcourse, int $sectionid): ?int {
        global $DB;

        $before = array_keys(get_fast_modinfo($targetcourse)->get_cms());

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
            $printed = trim((string) ob_get_clean());
            if ($printed !== '') {
                debugging(
                    'local_coursegen: output swallowed while copying cmid ' . $cm->id . ': ' . $printed,
                    DEBUG_DEVELOPER
                );
            }
        }

        if (!$coursefailed) {
            return $placed === null ? null : (int) $placed->id;
        }

        rebuild_course_cache($targetcourse->id, true);
        $newcmids = array_values(array_diff(array_keys(get_fast_modinfo($targetcourse)->get_cms()), $before));
        if (count($newcmids) !== 1) {
            debugging(
                'local_coursegen: could not locate the copy of kept activity ' . $cm->id,
                DEBUG_DEVELOPER
            );
            return null;
        }

        $newcm = get_fast_modinfo($targetcourse)->get_cm((int) $newcmids[0]);
        if ((int) $newcm->section !== $sectionid) {
            $section = $DB->get_record(
                'course_sections',
                ['id' => $sectionid, 'course' => $targetcourse->id],
                '*',
                MUST_EXIST
            );
            moveto_module($newcm, $section);
        }

        \core\event\course_module_created::create_from_cm($newcm)->trigger();
        return (int) $newcm->id;
    }
}
