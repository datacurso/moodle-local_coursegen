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

use context_course;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_coursegen\local\image_generation\image_policy_builder;
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\service\course_context_service;
use local_coursegen\local\service\filetype_catalog_service;
use local_coursegen\local\service\module_job_service;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/externallib.php');

/**
 * Start streaming job to create module with AI and store job/thread id.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
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
            'prompt' => new external_value(PARAM_RAW, 'Prompt to create module'),
            'generateimages' => new external_value(
                PARAM_INT,
                '1 to generate images, 0 to not generate images',
                VALUE_OPTIONAL
            ),
            'beforemod' => new external_value(PARAM_INT, 'Before module id', VALUE_OPTIONAL),
            'lang' => new external_value(PARAM_ALPHANUMEXT, 'Requested language code', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Start streaming job to create module with AI.
     *
     * @param int $courseid Course id where the module will be created.
     * @param int|null $sectionnum Section number where the module will be created.
     * @param string $prompt Prompt to create module.
     * @param int $generateimages 1 indicates AI could generate images, 0 indicates AI could not generate images.
     * @param int|null $beforemod Before module id where the module will be created.
     * @param string|null $lang Requested language code.
     * @return array
     */
    public static function execute(
        int $courseid,
        ?int $sectionnum,
        string $prompt,
        int $generateimages = 0,
        ?int $beforemod = null,
        ?string $lang = null
    ) {
        global $CFG, $DB, $USER;

        try {
            $params = self::validate_parameters(self::execute_parameters(), [
                'courseid' => $courseid,
                'sectionnum' => $sectionnum,
                'prompt' => $prompt,
                'generateimages' => $generateimages,
                'beforemod' => $beforemod,
                'lang' => $lang,
            ]);

            $courseid = $params['courseid'];
            $sectionnum = $params['sectionnum'] ?? null;
            $prompt = $params['prompt'];
            $generateimages = $params['generateimages'] ?? 0;
            $beforemod = $params['beforemod'] ?? null;
            $lang = $params['lang'] ?? null;

            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $context = context_course::instance($course->id);
            self::validate_context($context);

            $coursecontext = course_context_service::get_course_context($courseid);

            // This request may take a long time depending on the complexity of the prompt that the AI has to resolve.
            \core_php_time_limit::raise();
            raise_memory_limit(MEMORY_EXTRA);
            // Release the session so other tabs in the same session are not blocked.
            \core\session\manager::write_close();

            $lang = self::resolve_request_language($lang, $coursecontext);

            $payload = [
                'instructions' => $prompt,
                'lang' => $lang,
                'with_images' => $generateimages == 1,
            ];

            if ($generateimages == 1) {
                $payload['image_policy'] = image_policy_builder::build();
            }

            if (!empty($coursecontext) && !empty($coursecontext->context_type)) {
                $payload['context_type'] = $coursecontext->context_type;
            }

            // Tell the service which H5P framework (core API) this Moodle runs, so it packages the
            // generated .h5p with libraries compatible with that version (v127 vs v128 library set).
            try {
                (new \core_h5p\factory())->get_core(); // Ensures the active H5P handler is autoloaded.
                // phpcs:ignore moodle.NamingConventions.ValidVariableName.VariableNameLowerCase
                $coreapi = \core_h5p\core::$coreApi;
                if (!empty($coreapi['majorVersion'])) {
                    $payload['h5p_core_api'] = $coreapi['majorVersion'] . '.' . $coreapi['minorVersion'];
                }
            } catch (\Throwable $e) {
                // Leave unset; the service falls back to its most-compatible library set.
                debugging('local_coursegen: could not resolve H5P core API: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }

            // Send this instance's file-type group catalog (group key => extensions) so the service
            // can infer and validate accepted file types against the site's real groups, custom
            // types included, instead of assuming the stock Moodle catalog.
            $filetypegroups = filetype_catalog_service::get_groups();
            if ($filetypegroups !== null) {
                $payload['filetype_groups'] = $filetypegroups;
            }

            $apiservice = new ai_course_api_service();
            $result = $apiservice->start_activity($payload);

            if (!isset($result['thread_id'])) {
                debugging('Invalid response from AI service (activity init). Response: ' . json_encode($result));
                return [
                    'ok' => false,
                    'message' => get_string('error_generating_resource', 'local_coursegen'),
                    'log' => 'Invalid response from AI service (activity init). Response: ' . json_encode($result),
                ];
            }

            $jobid = $result['thread_id'];
            $status = $result['status'] ?? null;
            $contexttype = $coursecontext ? $coursecontext->context_type : null;
            $systeminstructionname = $coursecontext->name ?? null;

            // Store job info in module jobs table using persistent model.
            module_job_service::create_job(
                $courseid,
                $USER->id,
                $jobid,
                $generateimages,
                $contexttype,
                $systeminstructionname,
                $sectionnum,
                $beforemod,
                $status
            );

            $streamingurl = $apiservice->get_mod_streaming_url_for_job($jobid);

            return [
                'ok' => true,
                'job_id' => $jobid,
                'status' => $status,
                'message' => $result['message'] ?? get_string('course_planning_started', 'local_coursegen'),
                'streamingurl' => $streamingurl,
            ];
        } catch (\Exception $e) {
            debugging('Unexpected error while starting resource generation (stream): ' . $e->getMessage());
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Resolve language to send to the AI service.
     *
     * Priority order:
     * 1) Explicit request language from modal.
     * 2) Stored course context language.
     * 3) Current Moodle language.
     * 4) English fallback.
     *
     * @param string|null $requestlang
     * @param \stdClass|null $coursecontext
     * @return string
     */
    private static function resolve_request_language(?string $requestlang, ?\stdClass $coursecontext): string {
        $candidates = [$requestlang, $coursecontext->lang ?? '', \current_language()];

        foreach ($candidates as $candidate) {
            $candidate = str_replace('-', '_', \core_text::strtolower(trim((string)$candidate)));
            $lang = explode('_', $candidate)[0];
            if ($lang !== '') {
                return $lang;
            }
        }

        return 'en';
    }

    /**
     * Returns description of method result values.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Response status from server'),
            'job_id' => new external_value(PARAM_TEXT, 'Streaming job id from AI service', VALUE_OPTIONAL),
            'status' => new external_value(PARAM_RAW, 'Job status from AI service', VALUE_OPTIONAL),
            'message' => new external_value(PARAM_TEXT, 'Response message from server', VALUE_OPTIONAL),
            'streamingurl' => new external_value(PARAM_RAW, 'Streaming URL to connect to the activity stream', VALUE_OPTIONAL),
        ]);
    }
}
