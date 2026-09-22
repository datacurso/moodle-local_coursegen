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

namespace local_coursegen\external;

use external_multiple_structure;
use external_single_structure;
use external_value;

/**
 * get_template_structure's return-value contract, kept apart from the rest of
 * the webservice class only because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait get_template_structure_schema {
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
                            'id'       => new external_value(PARAM_ALPHANUMEXT,
                                'Course module ID for a real activity; the instance uid for a virtual instance row'),
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
                            'generationuid' => new external_value(PARAM_ALPHANUMEXT,
                                'Name this row answers to in the generation answer; empty when it is not generated'),
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
