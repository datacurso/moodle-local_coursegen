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
 * External API for creating courses with AI assistance.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\create_course_service;
use moodle_exception;
use context_system;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External API for creating courses with AI assistance.
 */
class create_course extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'recordid' => new external_value(PARAM_INT, 'Course planning session record ID'),
            'fullname' => new external_value(PARAM_TEXT, 'Override course fullname', VALUE_OPTIONAL, ''),
            'shortname' => new external_value(PARAM_TEXT, 'Override course shortname', VALUE_OPTIONAL, ''),
            'category' => new external_value(PARAM_INT, 'Override course category ID', VALUE_OPTIONAL, 0),
        ]);
    }

    /**
     * Create a course from an AI planning session.
     *
     * All business logic (coursedata parsing, category resolution, permission
     * checks, API dispatch) is handled by the service.
     *
     * @param int $recordid Session record ID in local_coursegen_course_sessions
     * @return array Result of the course content application
     * @throws moodle_exception
     */
    public static function execute($recordid, $fullname = '', $shortname = '', $category = 0) {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'recordid' => $recordid,
            'fullname' => $fullname,
            'shortname' => $shortname,
            'category' => $category,
        ]);

        $context = context_system::instance();
        self::validate_context($context);

        $recordid = (int)$params['recordid'];

        // Load session (validates ownership).
        $session = course_session_service::get_user_session($recordid, $USER->id);

        // Fetch the AI-generated result data from the Datacurso API.
        $apiservice = new ai_course_api_service();
        $result = $apiservice->get_course_result((string)$session->get('session_id'));
        $resultdata = $result['result'] ?? [];

        // Build overrides from user-provided params (non-empty values only).
        $overrides = [];
        $fullname = trim((string)$params['fullname']);
        if ($fullname !== '') {
            $overrides['fullname'] = $fullname;
        }
        $shortname = trim((string)$params['shortname']);
        if ($shortname !== '') {
            $overrides['shortname'] = $shortname;
        }
        $category = (int)$params['category'];
        if ($category > 0) {
            $overrides['category'] = $category;
        }

        return create_course_service::create_course($session, $resultdata, $overrides);
    }

    /**
     * Returns description of method return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'courseid' => new external_value(PARAM_INT, 'Created course ID'),
            'shortname' => new external_value(PARAM_TEXT, 'Course shortname'),
            'fullname' => new external_value(PARAM_TEXT, 'Course fullname'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
            'courseurl' => new external_value(PARAM_URL, 'Course URL', VALUE_OPTIONAL),
            'partial' => new external_value(PARAM_BOOL, 'Whether course content was only partially applied', VALUE_DEFAULT, false),
            'haswarnings' => new external_value(
                PARAM_BOOL,
                'Whether activity creation warnings were detected',
                VALUE_DEFAULT,
                false
            ),
            'warningscount' => new external_value(PARAM_INT, 'Count of skipped activity creations', VALUE_DEFAULT, 0),
        ]);
    }
}
