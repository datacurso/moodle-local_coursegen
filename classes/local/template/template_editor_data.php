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

use stdClass;

/**
 * Puts what is saved for a template on top of the current structure of its course.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_editor_data {
    /**
     * Add the saved action and instruction to every activity. An activity without a saved row is kept.
     *
     * @param array[] $sections Sections as returned by course_structure.
     * @param stdClass[] $saved Saved rows keyed by course module id.
     * @return array[]
     */
    public static function apply_saved_items(array $sections, array $saved): array {
        $applied = [];
        foreach ($sections as $section) {
            $section['activities'] = self::apply_to_activities($section['activities'], $saved);
            $applied[] = $section;
        }

        return $applied;
    }

    /**
     * Add the saved action and instruction to the activities of one section.
     *
     * @param array[] $activities Activities of the section.
     * @param stdClass[] $saved Saved rows keyed by course module id.
     * @return array[]
     */
    private static function apply_to_activities(array $activities, array $saved): array {
        $applied = [];
        foreach ($activities as $activity) {
            $activity['action'] = template_actions::KEEP;
            $activity['instruction'] = '';
            if (isset($saved[$activity['cmid']])) {
                $row = $saved[$activity['cmid']];
                $activity['action'] = (string) $row->action;
                $activity['instruction'] = (string) $row->instruction;
            }
            $applied[] = $activity;
        }

        return $applied;
    }
}
