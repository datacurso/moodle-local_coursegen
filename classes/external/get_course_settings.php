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
 * External API for getting final course settings from the AI-generated result.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use core\context\system;
use core\exception\required_capability_exception;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursegen\local\api_client_factory;
use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\create_course_service;

/**
 * External API for fetching the AI-generated course settings for final review.
 */
class get_course_settings extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'recordid' => new external_value(PARAM_INT, 'Course planning session record ID'),
        ]);
    }

    /**
     * Get the AI-generated course settings (fullname, shortname, category) for final review.
     *
     * @param int $recordid Session record ID.
     * @return array Course settings (fullname, shortname, category, categories).
     */
    public static function execute($recordid) {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'recordid' => $recordid,
        ]);

        $context = system::instance();
        self::validate_context($context);

        // This endpoint deliberately does not use access::require_course_creation():
        // the plugin capability is required at system level, but moodle/course:create
        // is accepted at system level OR in at least one category, because category
        // level course creators must be offered their categories (the final course
        // creation then checks moodle/course:create in the chosen category).
        require_capability('local/coursegen:createcoursewithai', $context);

        $catlist = \core_course_category::make_categories_list('moodle/course:create');
        if (empty($catlist) && !has_capability('moodle/course:create', $context)) {
            throw new required_capability_exception($context, 'moodle/course:create', 'nopermissions', '');
        }

        $recordid = (int)$params['recordid'];

        // Load session (validates ownership).
        $session = course_session_service::require_owned_session($recordid, $USER->id);

        // Fetch the AI-generated result data from the Datacurso API.
        $apiservice = api_client_factory::ai_course_api_service();
        $result = $apiservice->get_course_result((string)$session->get('session_id'));
        $resultdata = $result['result'] ?? [];

        $settings = create_course_service::get_course_settings($session, $resultdata);

        // Offer only the categories where the user can actually create courses.
        $categories = [];
        foreach ($catlist as $id => $pathname) {
            $categories[] = [
                'id' => (int)$id,
                'pathname' => $pathname,
            ];
        }

        return $settings + ['categories' => $categories];
    }

    /**
     * Returns description of method return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'fullname' => new external_value(PARAM_TEXT, 'AI-generated course fullname'),
            'shortname' => new external_value(PARAM_TEXT, 'AI-generated course shortname'),
            'category' => new external_value(PARAM_INT, 'AI-generated course category ID'),
            'categories' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Category ID'),
                    'pathname' => new external_value(PARAM_RAW, 'Category path name (e.g. "Miscellaneous / Subcategory")'),
                ]),
                'List of available categories with full paths',
                VALUE_OPTIONAL
            ),
        ]);
    }
}
