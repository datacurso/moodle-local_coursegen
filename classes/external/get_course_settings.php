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

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_value;
use external_single_structure;
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\create_course_service;
use context_system;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

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

        $context = context_system::instance();
        self::validate_context($context);

        $recordid = (int)$params['recordid'];

        // Load session (validates ownership).
        $session = course_session_service::get_user_session($recordid, $USER->id);

        // Fetch the AI-generated result data from the Datacurso API.
        $apiservice = static::get_api_service();
        $result = $apiservice->get_course_result((string)$session->get('session_id'));
        $resultdata = $result['result'] ?? [];

        $settings = create_course_service::get_course_settings($session, $resultdata);

        // Load categories with full paths using Moodle's built-in function.
        $categories = [];
        $catlist = \core_course_category::make_categories_list('moodle/category:manage');
        foreach ($catlist as $id => $pathname) {
            $categories[] = [
                'id' => (int)$id,
                'pathname' => $pathname,
            ];
        }

        return $settings + ['categories' => $categories];
    }

    /**
     * Build the AI course API service used by this endpoint.
     *
     * Extracted as a protected factory so PHPUnit tests can override it
     * through a testable subclass (late static binding).
     *
     * @return ai_course_api_service
     */
    protected static function get_api_service(): ai_course_api_service {
        return new ai_course_api_service();
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
