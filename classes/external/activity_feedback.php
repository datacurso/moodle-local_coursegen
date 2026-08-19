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
 * External API to send human feedback for AI activity generation jobs.
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
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\service\module_job_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External API to send human feedback for AI activity generation jobs.
 */
class activity_feedback extends external_api {
    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id where the activity is being generated'),
            'jobid' => new external_value(PARAM_TEXT, 'Activity generation job/thread id'),
            'approvalstatus' => new external_value(PARAM_ALPHANUMEXT, 'Feedback action, e.g. accept or adjust'),
            'instruction' => new external_value(PARAM_RAW, 'Feedback text for adjusting the activity', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Send feedback for the given activity job.
     *
     * @param int $courseid Course id where the activity is being generated
     * @param string $jobid Activity generation job id
     * @param string $approvalstatus Approval status (accept|adjust)
     * @param string $instruction Optional feedback text
     * @return array
     */
    public static function execute(int $courseid, string $jobid, string $approvalstatus, string $instruction = ''): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'jobid' => $jobid,
            'approvalstatus' => $approvalstatus,
            'instruction' => $instruction,
        ]);

        $courseid = $params['courseid'];
        $jobid = $params['jobid'];
        $approvalstatus = $params['approvalstatus'];
        $instruction = $params['instruction'];

        $context = context_system::instance();
        self::validate_context($context);

        // Same flow, same credits: gate this step of the AI generation behind
        // the same capabilities as create_mod_stream/create_mod, checked on
        // the course the activity is being generated in.
        $coursecontext = \context_course::instance($courseid);
        require_capability('moodle/course:manageactivities', $coursecontext);
        require_capability('local/coursegen:createactivitywithai', $coursecontext);

        $job = module_job_service::get_user_job($jobid, $courseid, $USER->id);
        $threadid = $job->get('job_id');

        if (!$threadid) {
            throw new \moodle_exception('error_no_session_found', 'local_coursegen');
        }

        $apiservice = new ai_course_api_service();

        try {
            $result = $apiservice->send_activity_feedback($threadid, $approvalstatus, $instruction);
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
