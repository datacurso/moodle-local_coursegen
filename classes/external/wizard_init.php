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

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use aiprovider_datacurso\httpclient\ai_course_api;
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\models\course_session;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External function to initialize wizard session.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wizard_init extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'prompt' => new external_value(PARAM_TEXT, 'Course description prompt'),
            'lang' => new external_value(PARAM_TEXT, 'Language code (es, en, etc.)', VALUE_DEFAULT, 'es'),
            'withimages' => new external_value(PARAM_BOOL, 'Include image suggestions', VALUE_DEFAULT, false),
            'systeminstructionid' => new external_value(
                PARAM_INT,
                'System instruction ID (optional)',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Initialize wizard session and create course planning thread.
     *
     * @param string $prompt Course description
     * @param string $lang Language code
     * @param bool $withimages Include images
     * @param int $systeminstructionid System instruction ID
     * @return array Result with sessionid and thread_id
     */
    public static function execute(
        string $prompt,
        string $lang = 'es',
        bool $withimages = false,
        int $systeminstructionid = 0
    ): array {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'prompt' => $prompt,
            'lang' => $lang,
            'withimages' => $withimages,
            'systeminstructionid' => $systeminstructionid,
        ]);

        // Check permissions.
        $context = context_system::instance();
        require_capability('moodle/course:create', $context);
        require_capability('local/coursegen:createcoursewithai', $context);

        try {
            // Get system instruction content if provided.
            $instructions = null;
            if ($params['systeminstructionid'] > 0) {
                $record = $DB->get_record(
                    'local_coursegen_system_instruction',
                    ['id' => $params['systeminstructionid'], 'deleted' => 0],
                    'content'
                );
                if ($record) {
                    $instructions = $record->content;
                }
            }

            // Prepare payload for Python API.
            $baseurl = get_config('local_coursegen', 'datacurso_service_url');
            $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu');

            if (empty($baseurl) && empty($baseurleu)) {
                return [
                    'success' => false,
                    'sessionid' => 0,
                    'threadid' => '',
                    'streamingurl' => '',
                    'message' => get_string('error_no_api_url', 'local_coursegen'),
                ];
            }

            // Create API service instance.
            $apiservice = new ai_course_api_service();

            // Call Python API /init endpoint.
            $response = $apiservice->start_course_planning([
                'prompt' => $params['prompt'],
                'instructions' => $instructions,
                'lang' => $params['lang'],
                'with_images' => $params['withimages'],
            ]);

            if (empty($response['thread_id'])) {
                return [
                    'success' => false,
                    'sessionid' => 0,
                    'threadid' => '',
                    'streamingurl' => '',
                    'message' => get_string('error_api_response', 'local_coursegen'),
                ];
            }

            $threadid = $response['thread_id'];
            $client = new ai_course_api(null, $baseurl ?: null, $baseurleu ?: null);
            $streamingurl = $client->get_streaming_url_for_session($threadid);

            // Create course session record.
            $session = new course_session();
            $session->set('userid', $USER->id);
            $session->set('session_id', $threadid);
            $session->set('status', 1); // Planning.
            $session->set('coursedata', json_encode([
                'local_coursegen_lang' => $params['lang'],
                'local_coursegen_generate_images' => $params['withimages'] ? 1 : 0,
                'local_coursegen_context_type' => 'customprompt',
                'local_coursegen_custom_prompt' => $params['prompt'],
                'local_coursegen_use_system_instruction' => $params['systeminstructionid'] > 0 ? 1 : 0,
                'local_coursegen_select_system_instruction' => $params['systeminstructionid'],
            ]));
            $session->create();

            return [
                'success' => true,
                'sessionid' => (int) $session->get('id'),
                'threadid' => $threadid,
                'streamingurl' => $streamingurl,
                'message' => get_string('wizard_init_success', 'local_coursegen'),
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'sessionid' => 0,
                'threadid' => '',
                'streamingurl' => '',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the operation succeeded'),
            'sessionid' => new external_value(PARAM_INT, 'Moodle session ID'),
            'threadid' => new external_value(PARAM_TEXT, 'Python API thread ID'),
            'streamingurl' => new external_value(PARAM_TEXT, 'Python API stream URL'),
            'message' => new external_value(PARAM_TEXT, 'Success or error message'),
        ]);
    }
}
