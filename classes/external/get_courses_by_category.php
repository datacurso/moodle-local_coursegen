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
 * Get courses within a category (and optionally its subcategories).
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

/**
 * External function to get courses by category.
 */
class get_courses_by_category extends external_api {

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT, 'Category ID'),
            'recursive' => new external_value(PARAM_BOOL, 'Include subcategories', VALUE_DEFAULT, true),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $categoryid
     * @param bool $recursive
     * @return array
     */
    public static function execute(int $categoryid, bool $recursive = true): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryid' => $categoryid,
            'recursive' => $recursive,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:managetemplates', $context);

        // Collect category IDs.
        $catids = [$params['categoryid']];
        if ($params['recursive']) {
            $cat = \core_course_category::get($params['categoryid'], IGNORE_MISSING);
            if ($cat) {
                $children = $cat->get_all_children_ids();
                $catids = array_merge($catids, $children);
            }
        }

        list($insql, $inparams) = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED);
        $courses = $DB->get_records_sql(
            "SELECT c.id, c.fullname, c.shortname, c.category
               FROM {course} c
              WHERE c.category {$insql} AND c.id != :siteid
              ORDER BY c.fullname ASC",
            $inparams + ['siteid' => SITEID]
        );

        $result = [];
        foreach ($courses as $c) {
            $catname = '';
            $cat = \core_course_category::get($c->category, IGNORE_MISSING);
            if ($cat) {
                $catname = $cat->get_nested_name(false);
            }
            $numsections = $DB->count_records('course_sections', ['course' => $c->id]) - 1;
            $result[] = [
                'id' => (int) $c->id,
                'fullname' => format_string($c->fullname),
                'shortname' => $c->shortname,
                'categoryid' => (int) $c->category,
                'categoryname' => $catname,
                'numsections' => max(0, $numsections),
            ];
        }

        return $result;
    }

    /**
     * Return definition.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Course ID'),
                'fullname' => new external_value(PARAM_TEXT, 'Full name'),
                'shortname' => new external_value(PARAM_TEXT, 'Short name'),
                'categoryid' => new external_value(PARAM_INT, 'Category ID'),
                'categoryname' => new external_value(PARAM_TEXT, 'Category path'),
                'numsections' => new external_value(PARAM_INT, 'Number of sections'),
            ])
        );
    }
}
