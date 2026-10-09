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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Copies one activity of the template course into the course being made, with Moodle's own backup and restore.
 *
 * The copy keeps everything the activity is made of: its rows, its files, its settings and its completion.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_activity_duplicator {
    /**
     * Duplicate one activity into the target course, in the matching section.
     *
     * @param \cm_info $cm Source activity, from the base course.
     * @param \stdClass $targetcourse
     * @param int $sectionid Target section id (not its number).
     * @return int|null The cmid of the copy, or null when the copy failed.
     */
    public static function duplicate_into($cm, $targetcourse, int $sectionid): ?int {
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
            // The function duplicate_module() resolves its $sectionid with
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
