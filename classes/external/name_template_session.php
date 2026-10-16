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
 * Keeps the name the AI proposed for a template generation on its session.
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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Writes the full name and the short name the AI returned into the session, so the list of recent courses shows them.
 */
class name_template_session extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'sessionid' => new external_value(PARAM_INT, 'Local session id'),
            'fullname' => new external_value(PARAM_TEXT, 'Course full name the AI returned'),
            'shortname' => new external_value(PARAM_TEXT, 'Course short name the AI returned', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Save the name on the session.
     *
     * @param int $sessionid
     * @param string $fullname
     * @param string $shortname
     * @return array
     */
    public static function execute($sessionid, $fullname, $shortname = '') {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'sessionid' => $sessionid,
            'fullname' => $fullname,
            'shortname' => $shortname,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:createtemplatecoursewithai', $context);

        $session = new course_session($params['sessionid']);
        if ((int) $session->get('userid') !== (int) $USER->id) {
            throw new \moodle_exception('nopermissions', 'error', '', 'name this generation');
        }

        $coursedata = json_decode($session->get('coursedata') ?? '{}', true) ?: [];
        $coursedata['fullname'] = $params['fullname'];
        $coursedata['shortname'] = $params['shortname'];
        $session->set('coursedata', json_encode($coursedata, JSON_UNESCAPED_UNICODE));
        $session->update();

        return ['saved' => true];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'saved' => new external_value(PARAM_BOOL, 'Whether the name was saved'),
        ]);
    }
}
