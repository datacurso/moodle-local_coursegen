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
 * Answers the questions the AI asked before it started a template generation.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use context_system;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_coursegen\local\models\course_session;
use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\draft_file;
use local_coursegen\local\service\template_ai_api_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * One answer to the questions a run asked before it started, or the choice to go on without answering.
 *
 * What a question is and how it is answered belongs to the service: this only carries the answers there. A file
 * answer travels as the draft item the person picked; it is uploaded to the run first, and the answer sent to the
 * service names the upload, never a path.
 */
class answer_template_questions extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'sessionid' => new external_value(PARAM_INT, 'Local session id'),
            'skipped' => new external_value(PARAM_BOOL, 'Go on without answering', VALUE_DEFAULT, false),
            'answers' => new external_multiple_structure(
                new external_single_structure([
                    'questionid' => new external_value(PARAM_ALPHANUMEXT, 'Question id'),
                    'value' => new external_value(PARAM_RAW, 'The answer, as JSON'),
                ]),
                'Answers by question',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Send the answers to the service.
     *
     * @param int $sessionid
     * @param bool $skipped
     * @param array $answers
     * @return array
     */
    public static function execute($sessionid, $skipped = false, $answers = []) {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'sessionid' => $sessionid,
            'skipped' => $skipped,
            'answers' => $answers,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:createtemplatecoursewithai', $context);

        $session = new course_session($params['sessionid']);
        if ((int) $session->get('userid') !== (int) $USER->id) {
            throw new \moodle_exception('nopermissions', 'error', '', 'answer this generation');
        }

        $api = new template_ai_api_service();
        $threadid = $session->get('session_id');
        $sent = [];
        if (!$params['skipped']) {
            foreach ($params['answers'] as $answer) {
                $value = json_decode($answer['value'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \invalid_parameter_exception('The answer to ' . $answer['questionid'] . ' is not valid JSON');
                }
                if (is_array($value) && array_key_exists('draftitemid', $value)) {
                    $value = self::upload_file($api, $threadid, $session, (int) $value['draftitemid'], $context);
                }
                $sent[] = ['question_id' => $answer['questionid'], 'value' => $value];
            }
        }

        $reply = $api->answer_questions($threadid, (bool) $params['skipped'], $sent);
        $settings = (array) ($reply['settings'] ?? []);
        self::keep_settings($session, $settings);

        return [
            'settled' => !empty($reply['settled']),
            'answered' => (int) ($reply['answered'] ?? 0),
            'images' => !empty($settings['with_images']),
        ];
    }

    /**
     * Keep with the session what the run will be generated with once it takes the answers.
     *
     * The summary at the top of the conversation is read from the session when the page is opened again, and must
     * say the pictures are on when an answer turned them on.
     *
     * @param course_session $session
     * @param array $settings The options the service says the run has: with_images, lang and lang_auto.
     */
    private static function keep_settings(course_session $session, array $settings): void {
        $data = json_decode((string) $session->get('coursedata'), true);
        if (!is_array($data) || !array_key_exists('with_images', $settings)) {
            return;
        }
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $payload['with_images'] = !empty($settings['with_images']);
        if (isset($settings['lang']) && $settings['lang'] !== '') {
            $payload['lang'] = (string) $settings['lang'];
            $payload['lang_auto'] = !empty($settings['lang_auto']);
        }
        $data['payload'] = $payload;
        $session->set('coursedata', json_encode($data));
        $session->update();
    }

    /**
     * Upload the file the person picked to the run, and keep it with the session as the syllabus is kept.
     *
     * @param template_ai_api_service $api
     * @param string $threadid
     * @param course_session $session
     * @param int $draftitemid
     * @param \context $context
     * @return array The answer to send: the upload's id and the file's name.
     */
    private static function upload_file($api, string $threadid, course_session $session, int $draftitemid, $context): array {
        require_capability('local/coursegen:uploadcoursesyllabus', $context);
        $file = draft_file::first($draftitemid);
        if ($file === null) {
            throw new \invalid_parameter_exception('There is no file in that draft item');
        }
        $uploaded = $api->upload_reference_file($threadid, $file);
        course_session_service::keep_syllabus((int) $session->get('id'), $file);
        $data = json_decode((string) $session->get('coursedata'), true);
        if (is_array($data)) {
            $data['syllabus'] = $file->get_filename();
            $session->set('coursedata', json_encode($data));
            $session->update();
        }
        return ['file_id' => (string) ($uploaded['file_id'] ?? ''), 'filename' => $file->get_filename()];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'settled' => new external_value(PARAM_BOOL, 'Whether the run had already taken its answers'),
            'answered' => new external_value(PARAM_INT, 'How many questions were answered'),
            'images' => new external_value(PARAM_BOOL, 'Whether the pictures are made once the answers are taken'),
        ]);
    }
}
