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

/**
 * External API for fetching a course's section and activity structure.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use context_system;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External API for reading the sections and activities of a given course.
 */
class get_course_structure extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * Return the sections and activities of a course.
     *
     * @param int $courseid Course ID.
     * @return array Sections with nested activities.
     */
    public static function execute($courseid) {
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:managetemplates', $context);

        $course  = get_course($params['courseid']);
        $modinfo = get_fast_modinfo($course);
        $result  = [];

        foreach ($modinfo->get_section_info_all() as $section) {
            $sectiondata = [
                'id'         => (int) $section->id,
                'num'        => (int) $section->section,
                'name'       => get_section_name($course, $section),
                'activities' => [],
            ];

            if (!empty($modinfo->sections[$section->section])) {
                foreach ($modinfo->sections[$section->section] as $cmid) {
                    $cm = $modinfo->cms[$cmid];
                    if (!$cm->uservisible) {
                        continue;
                    }
                    $sectiondata['activities'][] = [
                        'id'      => (int) $cm->id,
                        'name'    => format_string($cm->name),
                        'modname' => $cm->modname,
                    ];
                }
            }

            $result[] = $sectiondata;
        }

        return $result;
    }

    /**
     * Returns description of method return value.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns() {
        return new external_multiple_structure(
            new external_single_structure([
                'id'   => new external_value(PARAM_INT,  'Section ID'),
                'num'  => new external_value(PARAM_INT,  'Section number'),
                'name' => new external_value(PARAM_TEXT, 'Section name'),
                'activities' => new external_multiple_structure(
                    new external_single_structure([
                        'id'      => new external_value(PARAM_INT,          'Course module ID'),
                        'name'    => new external_value(PARAM_TEXT,         'Activity name'),
                        'modname' => new external_value(PARAM_ALPHANUMEXT,  'Module type name'),
                    ])
                ),
            ])
        );
    }
}
