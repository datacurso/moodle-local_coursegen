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
            'pending_action' => new external_single_structure([
                'action' => new external_value(PARAM_ALPHANUMEXT, 'Action to run, e.g. accept, feedback, delete_section, discard_image'),
                'target_ids' => new external_multiple_structure(
                    new external_value(PARAM_RAW, 'Target UUID (section, activity, or image suggestion)'),
                    'UUIDs the action targets',
                    VALUE_DEFAULT,
                    []
                ),
                'parent_section_id' => new external_value(PARAM_RAW, 'Parent section UUID', VALUE_DEFAULT, null, NULL_ALLOWED),
                'position' => new external_value(PARAM_INT, 'Insertion position', VALUE_DEFAULT, null, NULL_ALLOWED),
                'moved_id' => new external_value(PARAM_RAW, 'UUID of the item dragged in a reorder', VALUE_DEFAULT, null, NULL_ALLOWED),
                'proposal_custom' => new external_value(PARAM_BOOL, 'Feedback typed into the proposals card "other" option', VALUE_DEFAULT, false),
                'instruction' => new external_value(PARAM_TEXT, "User's free-text instruction", VALUE_DEFAULT, ''),
            ]),
        ]);
    }

    /**
     * Send feedback for the given planning session.
     *
     * @param int $recordid Session record id in local_coursegen_course_sessions
     * @param array $pendingaction The ActionIntent to run
     * @return array
     */
    public static function execute(
        int $recordid,
        array $pendingaction
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'recordid' => $recordid,
            'pending_action' => $pendingaction,
        ]);

        $recordid = $params['recordid'];
        $pendingaction = $params['pending_action'];

        $context = context_system::instance();
        self::validate_context($context);

        $session = course_session_service::get_user_session($recordid, $USER->id);

        // Gate the paid AI generation behind the same capabilities as the flow
        // entry point (see start_course_planning): owning the planning session
        // is not enough once the course creation permissions are revoked.
        require_capability('moodle/course:create', $context);
        require_capability('local/coursegen:createcoursewithai', $context);

        $sessionid = $session->get('session_id');

        if (!$sessionid) {
            throw new \moodle_exception('error_no_session_found', 'local_coursegen');
        }

        $apiservice = static::get_api_service();

        try {
            $apiservice->send_planning_feedback($sessionid, $pendingaction);
        } catch (\moodle_exception $e) {
            throw new \moodle_exception('error_sending_feedback', 'local_coursegen', '', $e->getMessage());
        }

        return [
            'success' => true,
            'message' => get_string('message_sent_successfully', 'local_coursegen'),
        ];
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
     * Returns description of method return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
        ]);
    }
}
