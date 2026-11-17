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
 * Stops a generation of a course made from a template, for certain.
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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * The person who started a generation can stop it, and nothing of it goes on spending afterwards.
 *
 * Closing the browser is not enough, because the stream goes through Moodle and the service only learns that the
 * reader left when the connection drops. This tells the service to stop the run and answers once it has: every
 * request to the AI under it is cancelled. What the run already made is kept, and opening the stream of the session
 * again goes on with what is missing.
 */
class stop_template_generation extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'sessionid' => new external_value(PARAM_INT, 'Local session id'),
        ]);
    }

    /**
     * Ask the service to stop the run of the session.
     *
     * @param int $sessionid
     * @return array
     */
    public static function execute($sessionid) {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['sessionid' => $sessionid]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:createtemplatecoursewithai', $context);

        $session = new course_session($params['sessionid']);
        if ((int) $session->get('userid') !== (int) $USER->id) {
            throw new \moodle_exception('nopermissions', 'error', '', 'stop this generation');
        }

        $answer = (new template_ai_api_service())->stop($session->get('session_id'));

        return [
            'wasrunning' => !empty($answer['was_running']),
            'stopped' => !empty($answer['stopped']),
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'wasrunning' => new external_value(PARAM_BOOL, 'Whether the service had a run going for the session'),
            'stopped' => new external_value(PARAM_BOOL, 'Whether nothing of the run is going any more'),
        ]);
    }
}
