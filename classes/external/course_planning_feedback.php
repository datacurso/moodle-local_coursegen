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

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use external_multiple_structure;
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\service\course_session_service;

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
            'selected_image_ids' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'Selected detailed image ID'),
                'Selected image IDs from detailed planning review',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Send feedback for the given planning session.
     *
     * @param int $recordid Session record id in local_coursegen_course_sessions
     * @param string $approvalstatus Approval status (accept|adjust)
     * @param string $instruction Optional feedback text
     * @param array $selectedimageids Selected image IDs from detailed planning review
     * @return array
     */
    public static function execute(
        int $recordid,
        string $approvalstatus,
        string $instruction = '',
        array $selectedimageids = []
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'recordid' => $recordid,
            'approval_status' => $approvalstatus,
            'instruction' => $instruction,
            'selected_image_ids' => $selectedimageids,
        ]);

        $recordid = $params['recordid'];
        $approvalstatus = $params['approval_status'];
        $instruction = $params['instruction'];
        $selectedimageids = array_values(array_filter(array_map(
            static function(string $value): string {
                return trim($value);
            },
            $params['selected_image_ids'] ?? []
        ), static function(string $value): bool {
            return $value !== '';
        }));

        $context = context_system::instance();
        self::validate_context($context);

        $session = course_session_service::get_user_session($recordid, $USER->id);
        $sessionid = $session->get('session_id');

        if (!$sessionid) {
            throw new \moodle_exception('error_no_session_found', 'local_coursegen');
        }

        $apiservice = new ai_course_api_service();

        try {
            $result = $apiservice->send_planning_feedback(
                $sessionid,
                $approvalstatus,
                $instruction,
                $selectedimageids
            );
        } catch (\moodle_exception $e) {
            throw new \moodle_exception('error_sending_feedback', 'local_coursegen', '', $e->getMessage());
        }

        $action = $result['action'] ?? null;

        return [
            'success' => true,
            'message' => get_string('message_sent_successfully', 'local_coursegen'),
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
