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
 * External function to store raw course edit form data for AI processing.
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
use external_single_structure;
use external_value;
use local_coursegen\local\service\process_course_form_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/edit_form.php');

/**
 * Web service that stores the full course form payload into local_coursegen_course_sessions.
 */
class process_course_form extends external_api {

    /**
     * Defines the parameters accepted by the web service.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'formdata' => new external_value(
                PARAM_RAW,
                'Url-encoded form data string'
            ),
        ]);
    }

    /**
     * Process the AJAX submission of the course_edit_form.
     *
     * On success, stores the validated form data as JSON in local_coursegen_course_sessions
     * and returns submitted=true with JSON-encoded result. Otherwise, returns
     * submitted=false without rendering HTML/JS.
     *
     * @param string $formdatastr Url-encoded form data.
     * @return array
     */
    public static function execute(string $formdatastr): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'formdata' => $formdatastr,
        ]);

        $context = context_system::instance();
        self::validate_context($context);

        $formdata = [];
        parse_str($params['formdata'], $formdata);

        return process_course_form_service::process($formdata);
    }

    /**
     * Defines the return structure for the web service response.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'submitted' => new external_value(PARAM_BOOL, 'If form was submitted and validated'),
            'data' => new external_single_structure([
                'message' => new external_value(PARAM_TEXT, 'Informational message about processing'),
                'recordid' => new external_value(PARAM_INT, 'ID of the stored local_coursegen_course_sessions record'),
                'redirecturl' => new external_value(PARAM_URL, 'URL where the client should be redirected'),
            ], 'Processing result data', VALUE_OPTIONAL),
        ]);
    }
}
