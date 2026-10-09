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
 * Sends the change request of a teacher to a template run that completed.
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
use local_coursegen\local\service\template_adjuster;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Asks the completed run of a session to change its result; the run goes on from its draft.
 */
class template_review_feedback extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            // Example: 139.
            'sessionid' => new external_value(PARAM_INT, 'Local session id'),
            // Example: adj1k3m9x2.
            'callid' => new external_value(PARAM_ALPHANUMEXT, 'Id of this change request'),
            // Example: Make the guide shorter.
            'instruction' => new external_value(PARAM_RAW, 'What to change'),
            // Example: t:11342.
            'aid' => new external_value(PARAM_RAW, 'Draft id of the only activity to change, empty for all', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Send the change request of the teacher to the run of a session.
     *
     * @param int $sessionid Local session id.
     * @param string $callid Id of this change request.
     * @param string $instruction What to change.
     * @param string $aid Draft id of the only activity to change, empty for all.
     * @return array The status of the request.
     */
    public static function execute($sessionid, $callid, $instruction, $aid = '') {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'sessionid' => $sessionid,
            'callid' => $callid,
            'instruction' => $instruction,
            'aid' => $aid,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:createtemplatecoursewithai', $context);

        $session = new course_session($params['sessionid']);
        if ((int) $session->get('userid') !== (int) $USER->id) {
            throw new \moodle_exception('nopermissions', 'error', '', 'change this generation');
        }

        (new template_adjuster())->adjust(
            (string) $session->get('session_id'),
            $params['callid'],
            (string) $params['instruction'],
            (string) $params['aid']
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
            'status' => new external_value(PARAM_ALPHA, 'Always "stored" once the request is recorded'),
        ]);
    }
}
