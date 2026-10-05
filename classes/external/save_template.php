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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursegen\local\template\template_access;
use local_coursegen\local\template\template_service;

/**
 * External function that saves a template: its course, its name and what the AI does with each activity.
 *
 * @package    local_coursegen
 * @category   external
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_template extends external_api {
    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'Template to replace, or 0 to create one'),
            'courseid' => new external_value(PARAM_INT, 'Course whose structure is the template'),
            'name' => new external_value(PARAM_RAW, 'Name of the template, cleaned when it is saved'),
            'description' => new external_value(PARAM_RAW, 'Optional description', VALUE_DEFAULT, ''),
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'cmid' => new external_value(PARAM_INT, 'Course module of the template course'),
                    'action' => new external_value(PARAM_ALPHA, 'keep or ai'),
                    'instruction' => new external_value(PARAM_RAW, 'Optional text telling the AI what to do', VALUE_DEFAULT, ''),
                ]),
                'What the AI does with each activity',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Save the template.
     *
     * @param int $templateid Template to replace, or 0 to create one.
     * @param int $courseid Course whose structure is the template.
     * @param string $name Name of the template.
     * @param string $description Optional description.
     * @param array $items What the AI does with each activity.
     * @return array The id of the template and how many activities it has.
     */
    public static function execute(int $templateid, int $courseid, string $name, string $description, array $items): array {
        global $USER;

        $definition = self::execute_parameters();
        $params = self::validate_parameters($definition, [
            'templateid' => $templateid,
            'courseid' => $courseid,
            'name' => $name,
            'description' => $description,
            'items' => $items,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        template_access::require_manage();
        template_access::require_course_visible($params['courseid']);

        $service = new template_service();
        $savedid = $service->save(
            $params['templateid'],
            $params['courseid'],
            $params['name'],
            $params['description'],
            $params['items'],
            (int) $USER->id
        );

        $itemcount = count($params['items']);

        return ['templateid' => $savedid, 'itemcount' => $itemcount];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'templateid' => new external_value(PARAM_INT, 'Id of the saved template'),
            'itemcount' => new external_value(PARAM_INT, 'Activities saved with the template'),
        ]);
    }
}
