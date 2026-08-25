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
use local_coursegen\local\models\template_section;
use local_coursegen\local\models\template_activity;
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
                        ])
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

        // Create or load existing template.
        $tpl = new template($params['id'] > 0 ? $params['id'] : 0);

        $tpl->set('name',          $params['name']);
        $tpl->set('description',   $params['description']);
        $tpl->set('courseid',      $params['courseid']);
        $tpl->set('maxsections',   $params['maxsections'] ?: null);
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

        // Replace all child activity records.
        $oldactivities = template_activity::get_records(['templateid' => $templateid]);
        foreach ($oldactivities as $a) {
            $a->delete();
        }

        // Replace all child section records.
        $oldsections = template_section::get_records(['templateid' => $templateid]);
        foreach ($oldsections as $s) {
            $s->delete();
        }

        // Insert new section and activity configuration.
        foreach ($params['sections'] as $sectiondata) {
            $sec = new template_section(0);
            $sec->set('templateid', $templateid);
            $sec->set('sectionid',  $sectiondata['sectionid']);
            $sec->set('sectionnum', $sectiondata['sectionnum']);
            $sec->set('behavior',   $sectiondata['behavior']);
            $sec->create();

            foreach ($sectiondata['activities'] as $actdata) {
                $act = new template_activity(0);
                $act->set('templateid',      $templateid);
                $act->set('sectionid',       $sectiondata['sectionid']);
                $act->set('cmid',            $actdata['cmid']);
                $act->set('action',          $actdata['action']);
                $act->set('useasreference',  (int) $actdata['useasreference']);
                $act->set('prompt',          $actdata['prompt']);
                $act->create();
            }
        }

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
