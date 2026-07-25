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

namespace local_coursegen\external;

use aiprovider_datacurso\httpclient\ai_course_api;
use context_course;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_coursegen\ai_context;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

/**
 * Class create_mod_stream
 *
 * Starts an AI job to generate a course resource in streaming mode.
 * It calls the /resources/create-mod?stream=true endpoint, stores the returned job_id
 * like a session_id using ai_course::save_course_session(), and returns that id to the caller.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_mod_stream extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number', VALUE_OPTIONAL),
            'prompt' => new external_value(PARAM_TEXT, 'Prompt to create module'),
            'generateimages' => new external_value(PARAM_INT, '1 to generate images, 0 to not generate images', VALUE_OPTIONAL),
            'beforemod' => new external_value(PARAM_INT, 'Before module id', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Start streaming job to create module with AI.
     *
     * @param int $courseid Course id where the module will be created
     * @param int|null $sectionnum Section number where the module will be created
     * @param string $prompt Prompt to create module
     * @param int $generateimages 1 indicates AI could generate images, 0 indicates AI could not generate images
     * @param int|null $beforemod Before module id where the module will be created
     * @return array
     */
    public static function execute(
        int $courseid,
        ?int $sectionnum,
        string $prompt,
        int $generateimages = 0,
        ?int $beforemod = null
    ) {
        global $CFG, $DB;

        try {
            $params = self::validate_parameters(self::execute_parameters(), [
                'courseid' => $courseid,
                'sectionnum' => $sectionnum,
                'prompt' => $prompt,
                'generateimages' => $generateimages,
                'beforemod' => $beforemod,
            ]);

            $courseid = $params['courseid'];
            $sectionnum = $params['sectionnum'] ?? null;
            $prompt = $params['prompt'];
            $generateimages = $params['generateimages'] ?? 0;
            $beforemod = $params['beforemod'] ?? null;

            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $context = context_course::instance($course->id);
            self::validate_context($context);

            // Server-side enforcement: admin settings and capabilities.
            if (!get_config('local_coursegen', 'enable_activity_ai')) {
                throw new \moodle_exception('error_activity_ai_disabled', 'local_coursegen');
            }
            require_capability('local/coursegen:createactivitywithai', $context);

            // Reject image generation if the setting or capability disallows it.
            if ($generateimages == 1) {
                if (!get_config('local_coursegen', 'enable_activity_image_generation')
                        || !has_capability('local/coursegen:generateactivityimages', $context)) {
                    throw new \moodle_exception('error_activity_image_generation_denied', 'local_coursegen');
                }
            }

            $aicontext = ai_context::get_course_context_info($courseid);

            // This request may take a long time depending on the complexity of the prompt that the AI has to resolve.
            \core_php_time_limit::raise();
            raise_memory_limit(MEMORY_EXTRA);
            // Release the session so other tabs in the same session are not blocked.
            \core\session\manager::write_close();

            $payload = [
                'course_id' => $courseid,
                'message' => $prompt,
                'generate_images' => ($generateimages == 1),
                'context_type' => $aicontext ? $aicontext->context_type : null,
                'system_instruction_name' => $aicontext ? $aicontext->name : null,
            ];

            $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
            $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;

            $client = new ai_course_api(null, $baseurl, $baseurleu);
            $result = $client->request('POST', '/resources/create-mod?stream=true', $payload);

            if (!isset($result['job_id'])) {
                debugging("Invalid response from AI service (stream). Response: " . json_encode($result));
                return [
                    'ok' => false,
                    'message' => get_string('error_generating_resource', 'local_coursegen'),
                    'log' => "Invalid response from AI service (stream). Response: " . json_encode($result),
                ];
            }

            $jobid = $result['job_id'];

            // Store job info in module jobs table.
            $success = \local_coursegen\module_jobs::save_job($courseid, $jobid, [
                'status' => $result['status'] ?? null,
                'generate_images' => $generateimages,
                'context_type' => $aicontext ? $aicontext->context_type : null,
                'system_instruction_name' => $aicontext ? $aicontext->name : null,
                'sectionnum' => $sectionnum,
                'beforemod' => $beforemod,
            ]);
            if (!$success) {
                return [
                    'ok' => false,
                    'message' => get_string('error_saving_session', 'local_coursegen'),
                    'log' => 'Failed to save module job to database',
                ];
            }

            $streamingurl = $client->get_mod_streaming_url_for_job($jobid);

            return [
                'ok' => true,
                'job_id' => $jobid,
                'status' => $result['status'] ?? null,
                'message' => $result['message'] ?? get_string('course_planning_started', 'local_coursegen'),
                'streamingurl' => $streamingurl,
            ];
        } catch (\Exception $e) {
            debugging("Unexpected error while starting resource generation (stream): " . $e->getMessage());
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Returns description of method result values.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Response status from server'),
            'message' => new external_value(PARAM_RAW, 'Response message from server', VALUE_OPTIONAL),
            'status' => new external_value(PARAM_TEXT, 'Status message from server', VALUE_OPTIONAL),
            'job_id' => new external_value(PARAM_TEXT, 'Job id returned by the server', VALUE_OPTIONAL),
            'streamingurl' => new external_value(PARAM_URL, 'Streaming URL for real-time updates', VALUE_OPTIONAL),
        ]);
    }
}
