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
 * Starts a course generation from a saved template.
 *
 * @package    local_coursegen
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
use local_coursegen\local\service\template_ai_api_service;
use local_coursegen\local\service\template_export_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Exports a template, sends it to the AI service, attaches the syllabus and starts the run.
 */
class start_template_generation extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'Template ID'),
            'prompt' => new external_value(PARAM_RAW, 'The professor\'s general instruction', VALUE_DEFAULT, ''),
            'draftitemid' => new external_value(PARAM_INT, 'Draft item id of the syllabus, 0 for none', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Start the generation.
     *
     * @param int $templateid
     * @param string $prompt
     * @param int $draftitemid
     * @return array
     */
    public static function execute($templateid, $prompt = '', $draftitemid = 0) {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'templateid' => $templateid,
            'prompt' => $prompt,
            'draftitemid' => $draftitemid,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:createcoursewithai', $context);

        $payload = template_export_service::build_init_payload($params['templateid'], $params['prompt']);

        $api = new template_ai_api_service();
        $threadid = $api->init($payload);

        $file = self::draft_file($params['draftitemid']);
        if ($file !== null) {
            $api->upload_reference_file($threadid, $file);
        }

        $session = new course_session(0, (object) [
            'userid' => (int) $USER->id,
            'session_id' => $threadid,
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode(['templateid' => $params['templateid']]),
        ]);
        $session->create();

        // Nothing has run yet: consuming the stream is what drives the
        // generation, so the caller opens this URL and watches it happen,
        // rather than starting a blind run and asking whether it is done.
        return [
            'threadid' => $threadid,
            'sessionid' => (int) $session->get('id'),
            'streamurl' => $api->stream_url($threadid),
        ];
    }

    /**
     * The first real file in a draft area, or null when there is none.
     *
     * @param int $draftitemid
     * @return \stored_file|null
     */
    private static function draft_file(int $draftitemid): ?\stored_file {
        if ($draftitemid <= 0) {
            return null;
        }
        global $USER;
        $usercontext = \context_user::instance($USER->id);
        $fs = get_file_storage();
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'itemid', false);
        $file = reset($files);
        if (!$file) {
            return null;
        }
        return $file;
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'threadid' => new external_value(PARAM_TEXT, 'Generation thread id'),
            'sessionid' => new external_value(PARAM_INT, 'Local session id'),
            'streamurl' => new external_value(PARAM_URL, 'SSE URL whose consumption runs the generation'),
        ]);
    }
}
