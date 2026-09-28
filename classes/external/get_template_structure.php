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
 * External API for the professor-facing template guided form: the template's
 * section/activity structure (with the admin-defined lock state applied) and
 * the catalog of activity types the professor may add.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use context_system;
use local_coursegen\local\models\template;
use local_coursegen\local\service\template_structure_view;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Builds the guided-form structure for a given template.
 */
class get_template_structure extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'Template ID'),
        ]);
    }

    /**
     * Return the template's structure, locked/reference state and allowed activity catalog.
     *
     * @param int $templateid Template ID.
     * @return array
     */
    public static function execute($templateid) {
        global $OUTPUT;

        $params = self::validate_parameters(self::execute_parameters(), ['templateid' => $templateid]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:createcoursewithai', $context);

        $template = template::get_record(['id' => $params['templateid']]);
        if (!$template) {
            throw new \moodle_exception('invalidtemplate', 'local_coursegen');
        }

        $course  = get_course($template->get('courseid'));
        $modinfo = get_fast_modinfo($course);

        return template_structure_view::build($template, $course, $modinfo, $OUTPUT);
    }

    /**
     * Returns description of method return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'nolimit' => new external_value(PARAM_BOOL, 'Whether the section limit is disabled'),
            'maxsections' => new external_value(PARAM_INT, 'Maximum total sections allowed'),
            'remainingsections' => new external_value(PARAM_INT, 'Additional sections the professor may still add'),
            'sections' => new external_multiple_structure(
                new external_single_structure([
                    'id'     => new external_value(PARAM_INT, 'Section ID'),
                    'num'    => new external_value(PARAM_INT, 'Section number'),
                    'name'   => new external_value(PARAM_TEXT, 'Section name'),
                    'behavior' => new external_value(PARAM_ALPHA, 'Admin-configured section behavior (custom/keep)'),
                    'locked' => new external_value(PARAM_BOOL, 'Whether the section is kept as-is from the template'),
                    'activities' => new external_multiple_structure(
                        new external_single_structure([
                            'id'       => new external_value(PARAM_INT,
                                'Course module ID; NEGATIVE (-recordid) for virtual instance rows'),
                            'name'     => new external_value(PARAM_TEXT, 'Activity name'),
                            'modname'  => new external_value(PARAM_ALPHANUMEXT,
                                'Module type name; may be empty on an instance row with no snapshot'),
                            'purpose'  => new external_value(PARAM_ALPHA, 'Activity purpose category'),
                            'typelabel' => new external_value(PARAM_TEXT,
                                'Snapshotted type label for instance rows; empty for real activities'),
                            'iconhtml' => new external_value(PARAM_RAW, 'Rendered module icon HTML; may be empty'),
                            'locked'   => new external_value(PARAM_BOOL, 'Always true — activities from the template are reference-only'),
                            'action'   => new external_value(PARAM_ALPHA,
                                'Resolved admin action ("keep"); empty for virtual instance rows'),
                            'isinstance' => new external_value(PARAM_BOOL, 'Whether this is a virtual instance row'),
                            'aigenerated' => new external_value(PARAM_BOOL,
                                'Whether AI will generate this activity in the new course (drives the badge)'),
                            'generationcmid' => new external_value(PARAM_INT,
                                'Id this row answers to in the generation progress events; 0 when it is not generated'),
                        ])
                    ),
                ])
            ),
            'allowedactivities' => new external_multiple_structure(
                new external_single_structure([
                    'modname'     => new external_value(PARAM_ALPHANUMEXT, 'Module type name'),
                    'displayname' => new external_value(PARAM_TEXT, 'Human-readable module name'),
                    'purpose'     => new external_value(PARAM_ALPHA, 'Activity purpose category'),
                    'iconhtml'    => new external_value(PARAM_RAW, 'Rendered module icon HTML'),
                ])
            ),
        ]);
    }
}
