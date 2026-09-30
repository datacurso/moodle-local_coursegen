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
 * External API for saving (creating or updating) a course template.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_coursegen\local\models\template;
use local_coursegen\local\service\template_persistence_service;
use context_system;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External API for creating or updating a course template with its section/activity config.
 */
class save_template extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'id'             => new external_value(PARAM_INT,  'Template ID (0 for new)', VALUE_DEFAULT, 0),
            'name'           => new external_value(PARAM_TEXT, 'Template name'),
            'description'    => new external_value(PARAM_RAW,  'Template description', VALUE_DEFAULT, ''),
            'courseid'       => new external_value(PARAM_INT,  'Base course ID'),
            'maxsections'    => new external_value(PARAM_INT,  'Max sections', VALUE_DEFAULT, 0),
            'nolimit'        => new external_value(PARAM_BOOL, 'No section limit', VALUE_DEFAULT, false),
            'allowedtypes'   => new external_value(PARAM_RAW,  'JSON array of allowed types', VALUE_DEFAULT, '[]'),
            'namingpattern'  => new external_value(PARAM_RAW,  'Section naming pattern', VALUE_DEFAULT, ''),
            'namingstart'    => new external_value(PARAM_INT,  'Naming start number', VALUE_DEFAULT, 1),
            'sections'       => new external_multiple_structure(
                new external_single_structure([
                    'sectionid'  => new external_value(PARAM_INT,   'Section ID'),
                    'sectionnum' => new external_value(PARAM_INT,   'Section number'),
                    'behavior'   => new external_value(PARAM_ALPHA, 'Section behavior'),
                    'activities' => new external_multiple_structure(
                        new external_single_structure([
                            'cmid'           => new external_value(PARAM_INT,  'Course module ID'),
                            'action'         => new external_value(PARAM_ALPHA, 'Activity action'),
                            'useasreference' => new external_value(PARAM_BOOL, 'Use as reference'),
                            'prompt'         => new external_value(PARAM_RAW,  'Activity prompt', VALUE_DEFAULT, ''),
                            'templatescope'  => new external_value(
                                PARAM_ALPHA,
                                'Template scope (course or section); only meaningful when action=template',
                                VALUE_DEFAULT,
                                'course'
                            ),
                        ])
                    ),
                    'instances' => new external_multiple_structure(
                        new external_single_structure([
                            'sourcecmid' => new external_value(PARAM_INT, 'cmid of the action=template activity this instance was created from'),
                            'sourcename' => new external_value(PARAM_TEXT, 'Snapshot of the source template\'s display name'),
                            'name'       => new external_value(PARAM_TEXT, 'This instance\'s own display name'),
                            'typelabel'  => new external_value(PARAM_TEXT, 'Snapshot of the source template\'s module type label'),
                            'modname'    => new external_value(PARAM_PLUGIN, 'Snapshot of the source template\'s module type', VALUE_DEFAULT, ''),
                            'prompt'     => new external_value(PARAM_RAW, 'Instance prompt', VALUE_DEFAULT, ''),
                            'aftercmid' => new external_value(
                                PARAM_INT,
                                'Real cmid this instance renders after within its section; 0 = section start',
                                VALUE_DEFAULT,
                                0
                            ),
                            'sortorder'  => new external_value(PARAM_INT, 'Tiebreaker order among instances sharing an anchor', VALUE_DEFAULT, 0),
                        ]),
                        'Virtual template instances placed in this section',
                        VALUE_DEFAULT,
                        []
                    ),
                ])
            ),
        ]);
    }

    /**
     * Create or update a course template, replacing all section and activity config.
     *
     * @param int    $id
     * @param string $name
     * @param string $description
     * @param int    $courseid
     * @param int    $maxsections
     * @param bool   $nolimit
     * @param string $allowedtypes
     * @param string $namingpattern
     * @param int    $namingstart
     * @param array  $sections
     * @return array Saved template id and name.
     */
    public static function execute(
        $id,
        $name,
        $description,
        $courseid,
        $maxsections,
        $nolimit,
        $allowedtypes,
        $namingpattern,
        $namingstart,
        $sections
    ) {
        $params = self::validate_parameters(self::execute_parameters(), [
            'id'            => $id,
            'name'          => $name,
            'description'   => $description,
            'courseid'      => $courseid,
            'maxsections'   => $maxsections,
            'nolimit'       => $nolimit,
            'allowedtypes'  => $allowedtypes,
            'namingpattern' => $namingpattern,
            'namingstart'   => $namingstart,
            'sections'      => $sections,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:managetemplates', $context);

        if (trim($params['name']) === '') {
            // The wizard's own name field only ever gets this far via a
            // direct save_template() call, never a real mform submission
            // (classes/form/template_name_form.php's "required" rule is
            // client-only and never actually runs) — this is the one place
            // a blank name is ever really rejected.
            throw new \moodle_exception('template_name_required', 'local_coursegen');
        }

        // Create or load existing template.
        $existingid = 0;
        if ($params['id'] > 0) {
            $existingid = $params['id'];
        }
        $tpl = new template($existingid);

        $maxsections = null;
        if ($params['maxsections']) {
            $maxsections = $params['maxsections'];
        }

        $tpl->set('name',          $params['name']);
        $tpl->set('description',   $params['description']);
        $tpl->set('courseid',      $params['courseid']);
        $tpl->set('maxsections',   $maxsections);
        $tpl->set('nolimit',       (int) $params['nolimit']);
        $tpl->set('allowedtypes',  $params['allowedtypes']);
        $tpl->set('namingpattern', $params['namingpattern']);
        $tpl->set('namingstart',   $params['namingstart']);

        if ($params['id'] > 0) {
            $tpl->update();
        } else {
            $tpl->create();
        }

        $templateid = (int) $tpl->get('id');

        template_persistence_service::save_sections($templateid, $params['sections']);

        return [
            'id'   => $templateid,
            'name' => $tpl->get('name'),
        ];
    }

    /**
     * Returns description of method return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'id'   => new external_value(PARAM_INT,  'Template ID'),
            'name' => new external_value(PARAM_TEXT, 'Template name'),
        ]);
    }
}
