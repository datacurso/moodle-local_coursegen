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
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_coursegen\local\models\course_session;
use local_coursegen\local\service\template_ai_api_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * One answer to the review a generation is waiting on.
 *
 * A template fixes the structure of the course, so the professor is offered
 * two answers and no more: accept the plan as it stands, or ask for one or
 * more activities to be planned again. Adding, deleting or reordering, which
 * free course creation allows at this same point, would be undoing the
 * template.
 */
class template_planning_feedback extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'sessionid' => new external_value(PARAM_INT, 'Local session id'),
            'action' => new external_value(PARAM_ALPHAEXT, 'accept or replan_activity'),
            'targetids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Activity id to replan'),
                'Activities to replan; empty means all of them',
                VALUE_DEFAULT,
                []
            ),
            'instruction' => new external_value(PARAM_TEXT, 'What to change', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Send the answer to the service.
     *
     * @param int $sessionid
     * @param string $action
     * @param int[] $targetids
     * @param string $instruction
     * @return array
     */
    public static function execute($sessionid, $action, $targetids = [], $instruction = '') {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'sessionid' => $sessionid,
            'action' => $action,
            'targetids' => $targetids,
            'instruction' => $instruction,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:createcoursewithai', $context);

        $session = new course_session($params['sessionid']);
        if ((int) $session->get('userid') !== (int) $USER->id) {
            throw new \moodle_exception('nopermissions', 'error', '', 'answer this generation');
        }

        $intent = ['action' => $params['action']];
        if ($params['action'] === 'replan_activity') {
            $intent['target_ids'] = array_values(array_map('intval', $params['targetids']));
            $intent['instruction'] = $params['instruction'];
        }

        (new template_ai_api_service())->send_feedback($session->get('session_id'), $intent);

        return ['status' => 'stored'];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'Always "stored" once the answer is recorded'),
        ]);
    }
}
