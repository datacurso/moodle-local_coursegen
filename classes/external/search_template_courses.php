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
use local_coursegen\local\template\course_search;
use local_coursegen\local\template\template_access;

/**
 * External function that searches the courses an admin can choose as the base of a template.
 *
 * @package    local_coursegen
 * @category   external
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_template_courses extends external_api {
    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT, 'Category to search in, or 0 for every category', VALUE_DEFAULT, 0),
            'query' => new external_value(PARAM_RAW, 'Text the course name contains', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Search the courses.
     *
     * @param int $categoryid Category to search in, or 0 for every category.
     * @param string $query Text the course name contains.
     * @return array The courses found.
     */
    public static function execute(int $categoryid = 0, string $query = ''): array {
        $definition = self::execute_parameters();
        $params = self::validate_parameters($definition, [
            'categoryid' => $categoryid,
            'query' => $query,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        template_access::require_manage();

        return course_search::find($params['categoryid'], $params['query']);
    }

    /**
     * Return definition.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Course id'),
                'fullname' => new external_value(PARAM_RAW, 'Full name of the course'),
                'shortname' => new external_value(PARAM_RAW, 'Short name of the course'),
            ])
        );
    }
}
