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
 * External API for fetching the proposed course settings of a finished template generation.
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
use local_coursegen\local\service\course_review_service;
use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\create_course_service;
use local_coursegen\local\service\template_ai_api_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * What the review step of a template generation shows before the course exists.
 *
 * The same review a course made without a template goes through: the proposed name,
 * short name and category, to be changed or kept by the teacher.
 */
class get_template_course_settings extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'recordid' => new external_value(PARAM_INT, 'Course generation session record ID'),
        ]);
    }

    /**
     * The proposed course settings of a finished generation, and the categories to choose from.
     *
     * @param int $recordid Session record ID.
     * @return array Course settings (fullname, shortname, category, categories).
     */
    public static function execute($recordid) {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['recordid' => $recordid]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:createtemplatecoursewithai', $context);

        $session = course_session_service::get_user_session((int) $params['recordid'], $USER->id);

        $api = new template_ai_api_service();
        $result = $api->get_result((string) $session->get('session_id'));

        $settings = create_course_service::get_course_settings($session, $result);
        $categories = course_review_service::available_categories();

        return $settings + ['categories' => $categories];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'fullname' => new external_value(PARAM_TEXT, 'Proposed course fullname'),
            'shortname' => new external_value(PARAM_TEXT, 'Proposed course shortname'),
            'category' => new external_value(PARAM_INT, 'Proposed course category ID'),
            'categories' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Category ID'),
                    'pathname' => new external_value(PARAM_RAW, 'Category path name'),
                ]),
                'List of available categories with full paths',
                VALUE_OPTIONAL
            ),
        ]);
    }
}
