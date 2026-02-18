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
 * External function to validate AI-related fields in the course edit form.
 *
 * @package    local_coursegen
 * @category   external
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use context_system;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/edit_form.php');

/**
 * Web service to validate AI-related fields for the course edit form.
 */
class validate_course_form extends external_api {
    /**
     * Defines the parameters accepted by the web service.
     *
     * @return external_function_parameters Parameters definition.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'payload' => new external_value(
                PARAM_RAW,
                'Serialized query string containing the entire form payload'
            ),
        ]);
    }

    /**
     * Executes the server-side validation for the AI-related fields.
     *
     * @param string $payload Serialized form payload.
     * @return array The validation result and error list.
     */
    public static function execute(string $payload): array {
        global $DB, $CFG;

        self::validate_parameters(self::execute_parameters(), [
            'payload' => $payload,
        ]);

        self::validate_context(context_system::instance());

        $data = [];
        parse_str($payload, $data);

        // Rebuild minimal $course and $category similar to course/edit.php.
        $course = null;
        $category = null;

        $courseid = isset($data['id']) ? (int)$data['id'] : 0;
        $categoryid = isset($data['category']) ? (int)$data['category'] : 0;

        if ($courseid) {
            // Existing course: load full record so the form and custom fields have all data.
            $course = get_course($courseid);
            $category = $DB->get_record('course_categories', ['id' => $course->category], '*', MUST_EXIST);
        } else {
            // New course: resolve target category and build a minimal course object.
            if ($categoryid) {
                $category = $DB->get_record('course_categories', ['id' => $categoryid], '*', MUST_EXIST);
            } else {
                $category = \core_course_category::get_default();
            }

            $course = new \stdClass();
            $course->id = 0;
            $course->category = $category->id;
        }

        $editoroptions = [
            'maxfiles' => 0,
            'maxbytes' => $CFG->maxbytes,
            'trusttext' => false,
            'noclean' => true,
        ];

        $args = [
            'course' => $course,
            'category' => $category,
            'editoroptions' => $editoroptions,
            'returnto' => 0,
            'returnurl' => '',
        ];

        $mform = new \course_edit_form(null, $args);

        // Use the form's own validation logic.
        $errorsassoc = $mform->validation($data, []);

        $errorslist = [];
        if (!empty($errorsassoc)) {
            foreach ($errorsassoc as $field => $msg) {
                $errorslist[] = [
                    'field' => (string) $field,
                    'msg' => (string) $msg,
                ];
            }
        }

        return [
            'ok' => empty($errorslist),
            'errors' => $errorslist,
        ];
    }

    /**
     * Defines the return structure for the web service response.
     *
     * @return external_single_structure Return structure definition.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Indicates whether the validation passed'),
            'errors' => new external_multiple_structure(
                new external_single_structure([
                    'field' => new external_value(PARAM_ALPHANUMEXT, 'Field name with a validation error'),
                    'msg' => new external_value(PARAM_TEXT, 'Validation error message'),
                ]),
                'List of field-specific validation errors',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }
}
