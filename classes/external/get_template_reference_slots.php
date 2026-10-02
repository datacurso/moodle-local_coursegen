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
 * The places of a template where the teacher may bring a file.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use context_system;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_coursegen\local\reference\reference_slot_listing;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Lists the places of a template that take a file, with what the teacher already brought to each.
 */
class get_template_reference_slots extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'Template ID'),
        ]);
    }

    /**
     * List the places.
     *
     * @param int $templateid
     * @return array
     */
    public static function execute($templateid) {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['templateid' => $templateid]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:createtemplatecoursewithai', $context);

        $rows = reference_slot_listing::rows((int) $USER->id, $params['templateid']);
        return ['slots' => $rows, 'hasslots' => !empty($rows)];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'hasslots' => new external_value(PARAM_BOOL, 'Whether the template has any place for a file'),
            'slots' => new external_multiple_structure(
                new external_single_structure([
                    'key' => new external_value(PARAM_TEXT, 'Name of the place'),
                    'activityname' => new external_value(PARAM_TEXT, 'Name of the template activity that holds it'),
                    'instruction' => new external_value(PARAM_TEXT, 'What the place asks for'),
                    'kind' => new external_value(PARAM_ALPHA, 'Kind of file the place holds'),
                    'accept' => new external_value(PARAM_RAW, 'Extensions the place accepts, with their dot, comma separated'),
                    'filename' => new external_value(PARAM_FILE, 'Name of the file already brought, empty for none'),
                    'hasfile' => new external_value(PARAM_BOOL, 'Whether a file was already brought'),
                ])
            ),
        ]);
    }
}
