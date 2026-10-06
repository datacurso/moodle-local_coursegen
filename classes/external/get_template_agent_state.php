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
use local_coursegen\local\service\template_agent_state_view;
use local_coursegen\local\service\template_ai_api_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * The snapshot a reloaded page of a template run repaints from.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_template_agent_state extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            // Example: 139.
            'sessionid' => new external_value(PARAM_INT, 'Local session id'),
        ]);
    }

    /**
     * Read the state of the run of a session.
     *
     * @param int $sessionid Local session id.
     * @return array status, threadid, streamurl, pendingquestion and progressevents.
     */
    public static function execute($sessionid) {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['sessionid' => $sessionid]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:createtemplatecoursewithai', $context);

        $session = new course_session($params['sessionid']);
        if ((int) $session->get('userid') !== (int) $USER->id) {
            throw new \moodle_exception('nopermissions', 'error', '', 'view this generation');
        }

        $threadid = (string) $session->get('session_id');
        $api = new template_ai_api_service();
        $state = $api->get_state($threadid);

        return template_agent_state_view::export($state, $threadid, $api->stream_url($threadid));
    }

    /**
     * Describes the value execute returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHAEXT, 'RUNNING, WAITING_USER, COMPLETED or FAILED'),
            'threadid' => new external_value(PARAM_TEXT, 'Thread id of the run'),
            'streamurl' => new external_value(PARAM_URL, 'Relay URL of the stream of the run'),
            'pendingquestion' => new external_value(PARAM_RAW, 'JSON of the pending question, empty when there is none'),
            'progressevents' => new external_value(PARAM_RAW, 'JSON list of the events of the run, in order'),
        ]);
    }
}
