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

namespace local_coursegen\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursegen\local\template\template_access;
use local_coursegen\local\template\template_service;
use local_coursegen\output\sections_config;
use moodle_exception;

/**
 * Render the "Course sections" review for the selected base course.
 *
 * @package    local_coursegen
 * @category   external
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course_preview extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course whose structure is shown, for example 42'),
            'templateid' => new external_value(
                PARAM_INT,
                'Existing template whose saved choices preselect the review, or 0 for none',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Render the course sections review.
     *
     * @param int $courseid Course whose structure is shown.
     * @param int $templateid Existing template id, 0 for a new template.
     * @return array
     * @throws moodle_exception When the course cannot be the base of a template.
     */
    public static function execute(int $courseid, int $templateid = 0): array {
        global $PAGE;

        $definition = self::execute_parameters();
        $params = self::validate_parameters($definition, [
            'courseid' => $courseid,
            'templateid' => $templateid,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        template_access::require_manage();
        template_access::require_course_visible($params['courseid']);

        $service = new template_service();
        $loaded = $service->load_for_edit($params['templateid'], $params['courseid']);
        if (!$loaded['courseusable']) {
            throw new moodle_exception('error_template_course_invalid', 'local_coursegen');
        }

        $course = get_course($params['courseid']);
        $coursecontext = \context_course::instance($course->id);
        $PAGE->set_context($coursecontext);
        $PAGE->set_course($course);

        // The same server-side render the first page load uses: one rendering
        // path, so what an AJAX course switch shows can never drift from it.
        $html = sections_config::render($loaded['sections'], (int) $course->id);
        $fullname = format_string($course->fullname);

        return [
            'html' => $html,
            'courseid' => (int) $course->id,
            'fullname' => $fullname,
            'shortname' => $course->shortname,
        ];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'html' => new external_value(PARAM_RAW, 'Rendered sections review'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'fullname' => new external_value(PARAM_TEXT, 'Course full name'),
            'shortname' => new external_value(PARAM_TEXT, 'Course short name'),
        ]);
    }
}
