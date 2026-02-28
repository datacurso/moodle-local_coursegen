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
 * External API to send human feedback for AI course planning sessions.
 *
 * @package    local_coursegen
 * @category   external
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use aiprovider_datacurso\httpclient\ai_course_api;
use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External API to send human feedback for AI course planning sessions.
 */
class course_planning_feedback extends external_api {
    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'recordid' => new external_value(PARAM_INT, 'ID from local_coursegen_course_sessions'),
            'approval_status' => new external_value(PARAM_ALPHANUMEXT, 'Feedback action, e.g. accept or adjust'),
            'instruction' => new external_value(PARAM_TEXT, 'Feedback text for adjusting the plan', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Send feedback for the given planning session.
     *
     * @param int $recordid Session record id in local_coursegen_course_sessions
     * @param string $approvalstatus Approval status (accept|adjust)
     * @param string $instruction Optional feedback text
     * @return array
     */
    public static function execute(int $recordid, string $approvalstatus, string $instruction = ''): array {
        global $CFG, $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'recordid' => $recordid,
            'approval_status' => $approvalstatus,
            'instruction' => $instruction,
        ]);

        $recordid = (int) $params['recordid'];
        $approvalstatus = (string) $params['approval_status'];
        $instruction = (string) $params['instruction'];

        $context = context_system::instance();
        self::validate_context($context);

        $session = $DB->get_record('local_coursegen_course_sessions', [
            'id' => $recordid,
            'userid' => $USER->id,
        ], '*', MUST_EXIST);

        if (empty($session->session_id)) {
            return [
                'success' => false,
                'message' => 'Missing session_id for planning session',
                'action' => null,
            ];
        }

        $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
        $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;

        $client = new ai_course_api(null, $baseurl, $baseurleu);

        $payload = [
            'approval_status' => $approvalstatus,
            'instruction' => $instruction,
        ];

        $endpoint = '/course/feedback/' . $session->session_id;

        try {
            $result = $client->request('POST', $endpoint, $payload);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'action' => null,
            ];
        }

        $action = $result['action'] ?? null;

        return [
            'success' => true,
            'message' => 'Feedback sent',
            'action' => $action,
        ];
    }

    /**
     * Returns description of method return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
            'action' => new external_value(PARAM_TEXT, 'Action decided by backend (approve/adjust)', VALUE_OPTIONAL),
        ]);
    }
}
