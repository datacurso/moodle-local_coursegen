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
 * External API that tells whether an activity may be marked as a template.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_system;
use local_coursegen\local\service\access_guard;
use local_coursegen\local\service\template_placeholder_guard;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Refuses an activity with no placeholder before the editor marks it as a template.
 *
 * The refusal is the one save_template gives, so the editor shows the server's
 * own message as soon as the user picks the action instead of when saving.
 */
class check_template_activity extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the activity'),
        ]);
    }

    /**
     * Check that the activity has at least one placeholder.
     *
     * @param int $cmid Course module id of the activity.
     * @return array {allowed: true}
     * @throws \moodle_exception When the activity has no placeholder.
     */
    public static function execute($cmid) {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        $context = context_system::instance();
        self::validate_context($context);
        access_guard::require_any(
            ['local/coursegen:createtemplates', 'local/coursegen:edittemplates'],
            $context
        );

        template_placeholder_guard::assert_cmid($params['cmid']);

        return ['allowed' => true];
    }

    /**
     * Returns description of method return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'allowed' => new external_value(PARAM_BOOL, 'Whether the activity may be marked as a template'),
        ]);
    }
}
