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
use core_external\external_single_structure;
use core_external\external_value;
use local_coursegen\local\template\template_access;
use local_coursegen\local\template\template_service;

/**
 * External function that deletes a template.
 *
 * @package    local_coursegen
 * @category   external
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_template extends external_api {
    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'Template to delete'),
        ]);
    }

    /**
     * Delete the template.
     *
     * @param int $templateid Template to delete.
     * @return array
     */
    public static function execute(int $templateid): array {
        $definition = self::execute_parameters();
        $params = self::validate_parameters($definition, ['templateid' => $templateid]);

        $context = \context_system::instance();
        self::validate_context($context);
        template_access::require_manage();

        $service = new template_service();
        $service->delete($params['templateid']);

        return ['deleted' => true];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'deleted' => new external_value(PARAM_BOOL, 'True when the template was deleted'),
        ]);
    }
}
