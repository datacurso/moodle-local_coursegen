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
 * Render a course preview using the native course format renderer.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

/**
 * External function to render a course preview with its native format.
 */
class get_course_preview extends external_api {

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * Render the course content using the native format renderer in read-only mode.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        global $PAGE;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:managetemplates', $context);

        $course = get_course($params['courseid']);
        $coursecontext = \context_course::instance($course->id);

        $PAGE->set_context($coursecontext);
        $PAGE->set_course($course);

        $format = course_get_format($course);
        $renderer = $format->get_renderer($PAGE);

        $outputclass = $format->get_output_classname('content');
        $widget = new $outputclass($format);

        $html = $renderer->render($widget);

        $modinfo = get_fast_modinfo($course);
        $sections = $modinfo->get_section_info_all();
        $numsections = count($sections) - 1;
        $numactivities = 0;
        foreach ($sections as $section) {
            if (!empty($modinfo->sections[$section->section])) {
                $numactivities += count($modinfo->sections[$section->section]);
            }
        }

        return [
            'html' => $html,
            'courseid' => $course->id,
            'fullname' => format_string($course->fullname),
            'shortname' => $course->shortname,
            'format' => $course->format,
            'numsections' => $numsections,
            'numactivities' => $numactivities,
        ];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'html' => new external_value(PARAM_RAW, 'Rendered course HTML'),
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'fullname' => new external_value(PARAM_TEXT, 'Course full name'),
            'shortname' => new external_value(PARAM_TEXT, 'Course short name'),
            'format' => new external_value(PARAM_ALPHANUMEXT, 'Course format'),
            'numsections' => new external_value(PARAM_INT, 'Number of sections'),
            'numactivities' => new external_value(PARAM_INT, 'Number of activities'),
        ]);
    }
}
