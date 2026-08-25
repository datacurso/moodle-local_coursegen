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
 * External API for retrieving all course templates.
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
use local_coursegen\local\models\template;
use context_system;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External API for fetching all course templates.
 */
class get_templates extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([]);
    }

    /**
     * Get all course templates with base course info.
     *
     * @return array List of templates.
     */
    public static function execute() {
        global $DB;

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:managetemplates', $context);

        $templates = template::get_records([], 'timemodified', 'DESC');
        $result = [];
        foreach ($templates as $tpl) {
            $course = $DB->get_record('course', ['id' => $tpl->get('courseid')], 'id, fullname', IGNORE_MISSING);
            $result[] = [
                'id'             => (int) $tpl->get('id'),
                'name'           => $tpl->get('name'),
                'description'    => $tpl->get('description') ?? '',
                'courseid'       => (int) $tpl->get('courseid'),
                'coursefullname' => $course ? format_string($course->fullname) : '',
                'timecreated'    => (int) $tpl->get('timecreated'),
                'timemodified'   => (int) $tpl->get('timemodified'),
            ];
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
                'id'             => new external_value(PARAM_INT,  'Template ID'),
                'name'           => new external_value(PARAM_TEXT, 'Template name'),
                'description'    => new external_value(PARAM_RAW,  'Template description'),
                'courseid'       => new external_value(PARAM_INT,  'Base course ID'),
                'coursefullname' => new external_value(PARAM_TEXT, 'Base course name'),
                'timecreated'    => new external_value(PARAM_INT,  'Time created'),
                'timemodified'   => new external_value(PARAM_INT,  'Time modified'),
            ])
        );
    }
}
