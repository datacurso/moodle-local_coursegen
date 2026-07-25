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
 * External API for executing AI course planning sessions.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use aiprovider_datacurso\httpclient\ai_course_api;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_course;
use moodle_exception;

/**
 * External API for executing AI course planning sessions.
 */
class plan_course_execute extends external_api {
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
     * Execute AI course planning session.
     *
     * @param int $courseid Course ID
     * @return array Result of the execution
     * @throws moodle_exception
     */
    public static function execute($courseid) {
        global $CFG, $DB, $USER;

        try {
            $params = self::validate_parameters(self::execute_parameters(), [
                'courseid' => $courseid,
            ]);

            $courseid = $params['courseid'];

            // Validate context and permissions.
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $context = context_course::instance($courseid);
            self::validate_context($context);
            // Check if user has permission to edit the course.
            require_capability('moodle/course:update', $context);
            require_capability('local/coursegen:createcoursewithai', $context);

            if (!get_config('local_coursegen', 'enable_course_ai')) {
                throw new \moodle_exception('nopermissions', 'error', '', 'AI course creation is disabled');
            }

            // Validate that a session exists for this course and user.
            $session = $DB->get_record('local_coursegen_course_sessions', [
                'courseid' => $courseid,
                'userid' => $USER->id,
            ]);

            if (!$session) {
                return [
                    'success' => false,
                    'message' => get_string('error_no_session_found', 'local_coursegen', $courseid),
                    'data' => null,
                ];
            }

            $lang = $DB->get_field('local_coursegen_course_context', 'lang', ['courseid' => $courseid]);

            // Prepare the request data.
            $requestdata = [
                'session_id' => $session->session_id,
            ];

            if (!empty($lang)) {
                $requestdata['lang'] = $lang;
            }

            $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
            $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;

            $client = new ai_course_api(null, $baseurl, $baseurleu);
            $result = $client->request('POST', '/course/execute', $requestdata);

            // Build streaming URL and return success response with API status.
            $streamingurl = $client->get_streaming_url_for_session($session->session_id);
            return [
                'success' => true,
                'message' => $result['message'],
                'data' => [
                    'status' => $result['status'],
                    'streamingurl' => $streamingurl,
                ],
            ];
        } catch (\Exception $e) {
            debugging("Unexpected error while executing course planning: " . $e->getMessage(), DEBUG_DEVELOPER, $e->getTrace());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * Returns description of method return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
            'data' => new external_single_structure([
                'status' => new external_value(PARAM_TEXT, 'API status response', VALUE_OPTIONAL),
                'streamingurl' => new external_value(PARAM_URL, 'Streaming URL to reconnect', VALUE_OPTIONAL),
            ], 'Api response data', VALUE_OPTIONAL, null, NULL_ALLOWED),
        ]);
    }
}
