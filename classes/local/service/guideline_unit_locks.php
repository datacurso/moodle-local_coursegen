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

/**
 * Locks a unit until the tracked activities of the previous unit are completed.
 *
 * The AI service sets ``unlock_requires_uids`` on a unit section entry: the uids of the activities that must be
 * complete to open it. They become a completion condition per created course module on the section's
 * availability, in Moodle's availability JSON; the locked section stays visible (showc) but restricted.
 * Activities that were not created or do not track completion are left out of the condition, so a lock can
 * never wait for something that cannot be completed. An entry without the key is untouched.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class guideline_unit_locks {
    /** The key of a section entry that lists the activities its lock waits for. */
    public const KEY = 'unlock_requires_uids';

    /**
     * Write the availability of every locked unit.
     *
     * @param int $courseid The new course.
     * @param array $sectionsinfo The result's sections_info.
     * @param array $activities The result's generated activities.
     * @param array $generatedcms Activity key (generated_activity_key) => created cmid.
     * @return void
     */
    public static function apply(int $courseid, array $sectionsinfo, array $activities, array $generatedcms): void {
        $locked = self::locked_entries($sectionsinfo);
        if (!$locked) {
            return;
        }
        $cmids = guideline_cm_map::by_uid($activities, $generatedcms);
        foreach ($locked as $entry) {
            self::lock_section($courseid, (int) $entry['section'], (array) $entry[self::KEY], $cmids);
        }
        rebuild_course_cache($courseid, true);
    }

    /**
     * The section entries that ask for a lock.
     *
     * @param array $sectionsinfo
     * @return array[]
     */
    private static function locked_entries(array $sectionsinfo): array {
        return array_values(array_filter($sectionsinfo, static function ($entry): bool {
            return !empty($entry[self::KEY]) && is_array($entry[self::KEY]);
        }));
    }

    /**
     * Restrict one section to the completion of its required activities.
     *
     * @param int $courseid
     * @param int $number The section number.
     * @param string[] $uids The activities that must be complete.
     * @param int[] $cmids Activity uid => course module id.
     * @return void
     */
    private static function lock_section(int $courseid, int $number, array $uids, array $cmids): void {
        global $DB;
        $conditions = self::conditions($uids, $cmids);
        $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $number]);
        if (!$conditions || !$section) {
            debugging('local_coursegen: unit lock of section ' . $number . ' skipped, nothing to wait for.');
            return;
        }
        $json = json_encode([
            'op' => '&',
            'c' => $conditions,
            'showc' => array_fill(0, count($conditions), true),
        ]);
        course_update_section($courseid, $section, ['availability' => $json]);
    }

    /**
     * The completion conditions of the activities that exist and track completion.
     *
     * @param string[] $uids
     * @param int[] $cmids Activity uid => course module id.
     * @return array[]
     */
    private static function conditions(array $uids, array $cmids): array {
        global $DB;
        $conditions = [];
        foreach ($uids as $uid) {
            $cmid = $cmids[(string) $uid] ?? null;
            if ($cmid === null) {
                debugging('local_coursegen: unit lock skips activity ' . $uid . ', it was not created.');
                continue;
            }
            if ((int) $DB->get_field('course_modules', 'completion', ['id' => $cmid]) === COMPLETION_TRACKING_NONE) {
                debugging('local_coursegen: unit lock skips course module ' . $cmid . ', it does not track completion.');
                continue;
            }
            $conditions[] = ['type' => 'completion', 'cm' => $cmid, 'e' => COMPLETION_COMPLETE];
        }
        return $conditions;
    }
}
