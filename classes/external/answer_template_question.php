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
 * Answers the plan review of a paused template generation.
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
use local_coursegen\local\service\template_question_answerer;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Answers the question a template run is paused on.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class answer_template_question extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            // Example: 139.
            'sessionid' => new external_value(PARAM_INT, 'Local session id'),
            // Example: c4.
            'callid' => new external_value(PARAM_ALPHANUMEXT, 'Call id of the pending question'),
            // Example: file.
            'kind' => new external_value(PARAM_ALPHA, 'Kind of answer: file, text or choice'),
            // Example: 912345678.
            'draftitemid' => new external_value(PARAM_INT, 'Draft item id of the file, 0 for none', VALUE_DEFAULT, 0),
            // Example: Yes, use the second unit.
            'text' => new external_value(PARAM_RAW, 'Text of a text answer', VALUE_DEFAULT, ''),
            // Example: Unit 2.
            'choice' => new external_value(PARAM_RAW, 'Option of a choice answer', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Answer the pending question of the run of a session.
     *
     * @param int $sessionid Local session id.
     * @param string $callid Call id of the pending question.
     * @param string $kind Kind of answer.
     * @param int $draftitemid Draft item id of the file.
     * @param string $text Text of a text answer.
     * @param string $choice Option of a choice answer.
     * @return array The status of the answer.
     */
    public static function execute($sessionid, $callid, $kind, $draftitemid = 0, $text = '', $choice = '') {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'sessionid' => $sessionid,
            'callid' => $callid,
            'kind' => $kind,
            'draftitemid' => $draftitemid,
            'text' => $text,
            'choice' => $choice,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:createtemplatecoursewithai', $context);

        $session = new course_session($params['sessionid']);
        if ((int) $session->get('userid') !== (int) $USER->id) {
            throw new \moodle_exception('nopermissions', 'error', '', 'answer this generation');
        }

        $threadid = (string) $session->get('session_id');
        $answerer = new template_question_answerer();
        $answerer->answer(
            $threadid,
            $params['callid'],
            $params['kind'],
            (int) $params['draftitemid'],
            (string) $params['text'],
            (string) $params['choice']
        );

        return ['status' => 'stored'];
    }

    /**
     * Describes the value execute returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'Always "stored" once the answer is recorded'),
        ]);
    }
}
