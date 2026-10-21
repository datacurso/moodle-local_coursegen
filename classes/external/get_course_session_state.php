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
use local_coursegen\local\service\course_import_service;
use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\creation_permissions;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External API to retrieve resumable state for an AI course session.
 *
 * No audit event is emitted: this is a read-only resume/poll call that changes
 * no state and transfers nothing outwards, and one event per poll would flood
 * {logstore_standard_log} without adding audit value.
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

        // Owning the session is not enough: resuming exposes planning data and
        // backend state, so require the same capabilities as start_course_planning.
        creation_permissions::require_anywhere();

        $sessionid = (string)$session->get('session_id');
        if ($sessionid === '') {
            throw new \moodle_exception('error_no_session_found', 'local_coursegen');
        }

        $apiservice = static::get_api_service();
        $snapshot = $apiservice->get_course_state($sessionid);
        $streamingurl = $apiservice->get_course_streaming_url($sessionid);

        $coursedata = json_decode((string)$session->get('coursedata'), true);
        if (!is_array($coursedata)) {
            $coursedata = [];
        }

        $import = self::describe_import($coursedata);

        return $import + [
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
     * Expose the import bookkeeping kept in the coursedata JSON bag.
     *
     * @param array $coursedata Decoded session course data.
     * @return array importstate, importmessage, copycourseid and copycourseurl (done only).
     */
    private static function describe_import(array $coursedata): array {
        $import = isset($coursedata['import']) && is_array($coursedata['import']) ? $coursedata['import'] : [];
        $messages = [
            course_import_service::STATE_QUEUED => 'import_queued',
            course_import_service::STATE_RUNNING => 'import_running',
            course_import_service::STATE_DONE => 'import_completed',
            course_import_service::STATE_FAILED => 'import_failed',
        ];
        $state = (string)($import['state'] ?? '');
        if (!isset($messages[$state])) {
            return ['importstate' => '', 'importmessage' => '', 'copycourseid' => 0];
        }

        $copycourseid = (int)($import['copycourseid'] ?? 0);
        $result = [
            'importstate' => $state,
            'importmessage' => get_string($messages[$state], 'local_coursegen'),
            'copycourseid' => $copycourseid,
        ];
        if ($state === course_import_service::STATE_DONE && $copycourseid > 0) {
            $result['copycourseurl'] = (new \moodle_url('/course/view.php', ['id' => $copycourseid]))->out(false);
        }

        return $result;
    }

    /**
     * Build the AI course API service used by this endpoint.
     *
     * Extracted as a protected factory so PHPUnit tests can override it
     * through a testable subclass (late static binding).
     *
     * @return ai_course_api_service
     */
    protected static function get_api_service(): ai_course_api_service {
        return new ai_course_api_service();
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
            'importstate' => new external_value(
                PARAM_ALPHA,
                'Import into existing course: empty, queued, running, done or failed',
                VALUE_DEFAULT,
                ''
            ),
            'copycourseid' => new external_value(PARAM_INT, 'Course that replaced the target once imported', VALUE_DEFAULT, 0),
            'copycourseurl' => new external_value(PARAM_URL, 'URL of the copy course, present when done', VALUE_OPTIONAL),
            'importmessage' => new external_value(PARAM_TEXT, 'Localised message for the import state', VALUE_DEFAULT, ''),
        ]);
    }
}
