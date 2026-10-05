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

namespace local_coursegen\local\template;

use cm_info;
use course_modinfo;
use section_info;

/**
 * Reads the sections of a course and the activities of each one, as the template editor lists them.
 *
 * Every activity is listed, hidden or not, and a label is one more activity. An activity that is being deleted
 * is left out, and so are the sections that a plugin owns (a section that only holds another activity).
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_structure {
    /**
     * Whether a course can be the base of a template: it exists and it is not the front page.
     *
     * @param int $courseid Course id, for example 42.
     * @return bool
     */
    public static function is_usable_course(int $courseid): bool {
        global $DB;

        if ($courseid <= SITEID) {
            return false;
        }

        return $DB->record_exists('course', ['id' => $courseid]);
    }

    /**
     * The sections of a course with their activities.
     *
     * @param int $courseid Course id, for example 42.
     * @return array[] Each section has number, name, visible and activities.
     */
    public static function for_course(int $courseid): array {
        $modinfo = get_fast_modinfo($courseid);
        $sectionsinfo = $modinfo->get_section_info_all();
        $sections = [];
        foreach ($sectionsinfo as $sectioninfo) {
            if (self::is_owned_by_a_plugin($sectioninfo)) {
                continue;
            }
            $sections[] = self::describe_section($modinfo, $sectioninfo);
        }

        return $sections;
    }

    /**
     * The ids of every activity of a list of sections.
     *
     * @param array[] $sections Sections as returned by for_course.
     * @return int[]
     */
    public static function cmids_of(array $sections): array {
        $cmids = [];
        foreach ($sections as $section) {
            $sectioncmids = array_column($section['activities'], 'cmid');
            $cmids = array_merge($cmids, $sectioncmids);
        }

        return $cmids;
    }

    /**
     * Whether a section belongs to a plugin instead of being a section of the course.
     *
     * @param section_info $sectioninfo Section to check.
     * @return bool
     */
    private static function is_owned_by_a_plugin(section_info $sectioninfo): bool {
        if (!method_exists($sectioninfo, 'is_delegated')) {
            return false;
        }

        return (bool) $sectioninfo->is_delegated();
    }

    /**
     * Describe a section and its activities.
     *
     * @param course_modinfo $modinfo Information of the course.
     * @param section_info $sectioninfo Section to describe.
     * @return array
     */
    private static function describe_section(course_modinfo $modinfo, section_info $sectioninfo): array {
        $course = $modinfo->get_course();
        $name = get_section_name($course, $sectioninfo);
        $sectioncmids = $modinfo->sections[$sectioninfo->section] ?? [];
        $activities = self::describe_activities($modinfo, $sectioncmids);

        return [
            'number' => (int) $sectioninfo->section,
            'name' => $name,
            'visible' => (bool) $sectioninfo->visible,
            'activities' => $activities,
        ];
    }

    /**
     * Describe the activities of a section in their order.
     *
     * @param course_modinfo $modinfo Information of the course.
     * @param int[] $cmids Ids of the activities of the section.
     * @return array[]
     */
    private static function describe_activities(course_modinfo $modinfo, array $cmids): array {
        $activities = [];
        foreach ($cmids as $cmid) {
            $cm = $modinfo->get_cm($cmid);
            if ($cm->deletioninprogress) {
                continue;
            }
            $activities[] = self::describe_activity($cm);
        }

        return $activities;
    }

    /**
     * Describe an activity.
     *
     * @param cm_info $cm Activity to describe.
     * @return array
     */
    private static function describe_activity(cm_info $cm): array {
        $icon = $cm->get_icon_url();
        $iconurl = $icon->out(false);
        $typename = $cm->get_module_type_name();
        $isstealth = $cm->visible && !$cm->visibleoncoursepage;

        return [
            'cmid' => (int) $cm->id,
            'name' => (string) $cm->name,
            'modname' => (string) $cm->modname,
            'typename' => (string) $typename,
            'iconurl' => $iconurl,
            'visible' => (bool) $cm->visible,
            'stealth' => (bool) $isstealth,
        ];
    }
}
