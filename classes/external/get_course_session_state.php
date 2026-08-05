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
 * External API to retrieve resumable state for an AI course session.
 *
 * @package    local_coursegen
 * @category   external
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_coursegen\local\models\course_session;
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\service\course_session_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External API to retrieve resumable state for an AI course session.
 */
class get_course_session_state extends external_api {
    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'recordid' => new external_value(PARAM_INT, 'ID from local_coursegen_course_sessions'),
        ]);
    }

    /**
     * Get resumable state for a session owned by the current user.
     *
     * @param int $recordid Session record ID.
     * @return array
     */
    public static function execute(int $recordid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'recordid' => $recordid,
        ]);

        $context = context_system::instance();
        self::validate_context($context);

        $session = course_session_service::get_user_session((int)$params['recordid'], (int)$USER->id);
        $sessionid = (string)$session->get('session_id');
        if ($sessionid === '') {
            throw new \moodle_exception('error_no_session_found', 'local_coursegen');
        }

        $apiservice = new ai_course_api_service();
        $snapshot = $apiservice->get_course_state($sessionid);
        $streamingurl = $apiservice->get_course_streaming_url($sessionid);

        $coursedata = json_decode((string)$session->get('coursedata'), true);
        if (!is_array($coursedata)) {
            $coursedata = [];
        }

        return [
            'success' => true,
            'recordid' => (int)$session->get('id'),
            'sessionid' => $sessionid,
            'streamingurl' => (string)$streamingurl,
            'sessionstatus' => (int)$session->get('status'),
            'courseid' => (int)($session->get('courseid') ?? 0),
            'iscreated' => (int)$session->get('status') === course_session::STATUS_CREATED,
            'coursedatajson' => json_encode($coursedata, JSON_UNESCAPED_UNICODE),
            'snapshotjson' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'recordid' => new external_value(PARAM_INT, 'Session record id'),
            'sessionid' => new external_value(PARAM_TEXT, 'External thread/session id'),
            'streamingurl' => new external_value(PARAM_URL, 'Course stream URL'),
            'sessionstatus' => new external_value(PARAM_INT, 'Local session status'),
            'courseid' => new external_value(PARAM_INT, 'Created course id if available'),
            'iscreated' => new external_value(PARAM_BOOL, 'Whether local session is marked as created'),
            'coursedatajson' => new external_value(PARAM_RAW, 'Serialized local course data'),
            'snapshotjson' => new external_value(PARAM_RAW, 'Serialized backend state snapshot'),
        ]);
    }
}
