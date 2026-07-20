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
 * External API for deleting a course template.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_coursegen\local\models\template;
use local_coursegen\local\models\template_section;
use local_coursegen\local\models\template_activity;
use context_system;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External API for deleting a course template and all its child records.
 */
class delete_template extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Template ID to delete'),
        ]);
    }

    /**
     * Delete a template, including all associated sections and activities.
     *
     * @param int $id Template ID.
     * @return array Success flag.
     */
    public static function execute($id) {
        $params = self::validate_parameters(self::execute_parameters(), ['id' => $id]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:managetemplates', $context);

        // Delete child activity records first.
        $activities = template_activity::get_records(['templateid' => $params['id']]);
        foreach ($activities as $activity) {
            $activity->delete();
        }

        // Delete child section records.
        $sections = template_section::get_records(['templateid' => $params['id']]);
        foreach ($sections as $section) {
            $section->delete();
        }

        // Delete the template itself.
        $tpl = new template($params['id']);
        $tpl->delete();

        return ['success' => true];
    }

    /**
     * Returns description of method return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether deletion succeeded'),
        ]);
    }
}
