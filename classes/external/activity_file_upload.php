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
 * External API to upload files for AI activity generation jobs.
 *
 * @package    local_coursegen
 * @category   external
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use core\context\course;
use core\context\user;
use core\exception\moodle_exception;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursegen\local\access;
use local_coursegen\local\api_client_factory;
use local_coursegen\local\service\module_job_service;

/**
 * External API to upload files for AI activity generation jobs.
 */
class activity_file_upload extends external_api {
    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id where the activity is being generated'),
            'jobid' => new external_value(PARAM_TEXT, 'Activity generation job/thread id'),
            'draftitemid' => new external_value(PARAM_INT, 'Draft item id containing the uploaded file'),
        ]);
    }

    /**
     * Upload file for the given activity job.
     *
     * @param int $courseid Course id where the activity is being generated
     * @param string $jobid Activity generation job id
     * @param int $draftitemid Draft item id containing the uploaded file
     * @return array
     */
    public static function execute(int $courseid, string $jobid, int $draftitemid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'jobid' => $jobid,
            'draftitemid' => $draftitemid,
        ]);

        $courseid = $params['courseid'];
        $jobid = $params['jobid'];
        $draftitemid = $params['draftitemid'];

        $context = course::instance($courseid);
        self::validate_context($context);

        // Same flow, same credits: gate this step of the AI generation behind
        // the same capabilities as create_mod_stream/create_mod.
        access::require_activity_creation($context);

        $job = module_job_service::get_user_job($jobid, $courseid, $USER->id);
        $threadid = $job->get('job_id');

        if (!$threadid) {
            throw new moodle_exception('error_no_session_found', 'local_coursegen');
        }

        $fs = get_file_storage();
        $usercontext = user::instance($USER->id);

        $files = $fs->get_area_files(
            $usercontext->id,
            'user',
            'draft',
            $draftitemid,
            'id',
            false
        );

        if (empty($files)) {
            throw new moodle_exception('nofile', 'error');
        }

        $file = reset($files);
        if (!$file instanceof \stored_file) {
            throw new moodle_exception('nofile', 'error');
        }

        $apiservice = api_client_factory::ai_course_api_service();

        try {
            $apiservice->upload_activity_file($threadid, $file);
        } catch (moodle_exception $e) {
            $details = $e->debuginfo ?: $e->getMessage();
            // The string has no placeholder: the technical detail is debug information.
            throw new moodle_exception('error_sending_activity_file', 'local_coursegen', '', null, $details);
        }

        return [
            'success' => true,
            'message' => get_string('activity_file_uploaded', 'local_coursegen'),
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
        ]);
    }
}
