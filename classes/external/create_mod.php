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
use local_coursegen\mod_manager;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/externallib.php');
require_once($CFG->dirroot . '/course/modlib.php');

/**
 * Class create_mod
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_mod extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number'),
            'jobid' => new external_value(PARAM_TEXT, 'Streaming job id to fetch result from AI service'),
            'beforemod' => new external_value(PARAM_INT, 'Before module id', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Create course context for ask question to chatbot based in that information.
     *
     * @param int $courseid Course id where the module will be created
     * @param int $sectionnum Section number where the module will be created
     * @param string $jobid Streaming job id to fetch result from AI service
     * @param int|null $beforemod Before module id where the module will be created
     *
     * @return array
     */
    public static function execute(int $courseid, int $sectionnum, string $jobid, ?int $beforemod) {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionnum' => $sectionnum,
            'jobid' => $jobid,
            'beforemod' => $beforemod,
        ]);

        try {
            $courseid = $params['courseid'];
            $sectionnum = $params['sectionnum'];
            $beforemod = $params['beforemod'];
            $jobid = $params['jobid'];

            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $context = context_course::instance($course->id);
            self::validate_context($context);

            // Server-side enforcement: admin settings and capabilities.
            if (!get_config('local_coursegen', 'enable_activity_ai')) {
                throw new \moodle_exception('error_activity_ai_disabled', 'local_coursegen');
            }
            require_capability('local/coursegen:createactivitywithai', $context);

            // This request may take a long time depending on the complexity of the prompt that the AI ​​has to resolve.
            \core_php_time_limit::raise();
            raise_memory_limit(MEMORY_EXTRA);
            // Release the session so other tabs in the same session are not blocked.
            \core\session\manager::write_close();

            // This webservice is intended to be called after a streaming job completes.
            if (empty($jobid)) {
                return [
                    'ok' => false,
                    'message' => get_string('error_generating_resource', 'local_coursegen'),
                ];
            }

            $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
            $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;

            $client = new ai_course_api(null, $baseurl, $baseurleu);
            $result = $client->request('GET', '/resources/create-mod/result?job_id=' . urlencode($jobid));

            $newcm = mod_manager::create_from_ai_result($result, $course, $sectionnum, $beforemod);

            $url = new \moodle_url("/mod/$newcm->modulename/view.php", ["id" => $newcm->coursemodule]);

            return [
                'ok' => true,
                'message' => get_string('resource_created', 'local_coursegen', $newcm->modulename),
                'data' => [
                    'activityurl' => $url->out(false),
                    'cmid' => $newcm->id,
                    'modname' => $newcm->modulename,
                ],
            ];
        } catch (\Exception $e) {
            debugging("Unexpected error while creating resource: " . $e->getMessage());
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
            'message' => new external_value(PARAM_TEXT, 'Response message from server', VALUE_OPTIONAL),
            'data' => new external_single_structure([
                'activityurl' => new external_value(PARAM_URL, 'Activity URL', VALUE_OPTIONAL),
                'cmid' => new external_value(PARAM_INT, 'Course module ID', VALUE_OPTIONAL),
                'modname' => new external_value(PARAM_TEXT, 'Module name', VALUE_OPTIONAL),
            ], 'Activity data', VALUE_OPTIONAL),
        ]);
    }
}
