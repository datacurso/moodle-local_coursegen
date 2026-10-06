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
 * section/activity structure, with the activities the AI modifies marked.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use external_api;
use external_function_parameters;
use external_value;
use context_system;
use local_coursegen\local\template\template_repository;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Builds the guided-form structure for a given template.
 */
class get_template_structure extends external_api {
    use get_template_structure_rows;
    use get_template_structure_schema;

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
     * Return the template's structure and what the AI does with each activity.
     *
     * @param int $templateid Template ID.
     * @return array
     */
    public static function execute($templateid) {
        global $OUTPUT;

        $parameterdescription = self::execute_parameters();
        $params = self::validate_parameters($parameterdescription, ['templateid' => $templateid]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:createtemplatecoursewithai', $context);

        $repository = new template_repository();
        $template = $repository->find($params['templateid']);
        if ($template === null) {
            throw new \moodle_exception('invalidtemplate', 'local_coursegen');
        }

        $course = get_course($template->courseid);
        $modinfo = get_fast_modinfo($course);
        $items = $repository->items_of($params['templateid']);

        return [
            'sections' => self::sections($course, $modinfo, $items, $OUTPUT),
        ];
    }
}
