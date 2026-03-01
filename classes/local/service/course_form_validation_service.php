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

use stdClass;
use local_coursegen\local\service\process_course_form_service;

/**
 * Service for validating the course_edit_form data, including AI-related fields.
 *
 * This service encapsulates the logic required to rebuild the minimal course
 * and category context, instantiate the standard course_edit_form and execute
 * its validation, returning a normalised array of errors.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_form_validation_service {
    /**
     * Validate the course_edit_form payload and return the result.
     *
     * @param array $data Parsed payload (equivalent to $_POST from course_edit_form).
     * @return array Array with keys 'ok' (bool) and 'errors' (list of field/msg pairs).
     */
    public static function validate(array $data): array {
        global $CFG;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/edit_form.php');

        // Build the base context for the course_edit_form using shared helpers.
        $category = process_course_form_service::get_target_category($data);
        $course = process_course_form_service::get_course($data, $category);
        $editoroptions = process_course_form_service::build_editor_options();

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
        $errorslist = self::normalise_errors($errorsassoc);

        return [
            'ok' => empty($errorslist),
            'errors' => $errorslist,
        ];
    }

    /**
     * Normalise the associative list of errors returned by the form into
     * a list of simple field/msg pairs.
     *
     * @param array $errorsassoc Associative array field => message.
     * @return array
     */
    private static function normalise_errors(array $errorsassoc): array {
        $errorslist = [];

        if (!empty($errorsassoc)) {
            foreach ($errorsassoc as $field => $msg) {
                $errorslist[] = [
                    'field' => (string)$field,
                    'msg' => (string)$msg,
                ];
            }
        }

        return $errorslist;
    }
}
