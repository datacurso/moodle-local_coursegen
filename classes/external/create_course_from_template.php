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
 * External API for creating a course from a template.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use context_system;
use local_coursegen\local\models\template;
use local_coursegen\local\service\template_course_builder_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Create a new course out of a template: keep/modify/exclude its base
 * course's sections and activities, plus any new sections/activities the
 * professor added on top of it.
 */
class create_course_from_template extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'Template ID'),
            'newsections' => new external_multiple_structure(
                new external_single_structure([
                    'clientid' => new external_value(
                        PARAM_INT,
                        'Negative client-side placeholder id for this new section'
                    ),
                    'name' => new external_value(PARAM_TEXT, 'Section name'),
                ]),
                'Sections added by the professor, not present in the template base course',
                VALUE_DEFAULT,
                []
            ),
            'newactivities' => new external_multiple_structure(
                new external_single_structure([
                    'sectionid' => new external_value(
                        PARAM_INT,
                        'Target section: positive = real base-course section id (must be behavior=custom); '
                        . 'negative = a newsections clientid'
                    ),
                    'modname' => new external_value(PARAM_PLUGIN, 'Activity module name'),
                ]),
                'Activities added by the professor via the chooser, not present in the template base course',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Create a course from a template.
     *
     * @param int $templateid Template ID.
     * @param array $newsections New sections added by the professor.
     * @param array $newactivities New activities added by the professor.
     * @return array
     */
    public static function execute(int $templateid, array $newsections = [], array $newactivities = []): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'templateid' => $templateid,
            'newsections' => $newsections,
            'newactivities' => $newactivities,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/course:create', $context);
        require_capability('local/coursegen:createcoursewithai', $context);

        $template = template::get_record(['id' => $params['templateid']]);
        if (!$template) {
            throw new \moodle_exception('invalidtemplate', 'local_coursegen');
        }

        return template_course_builder_service::create_course_from_template(
            $template,
            $params['newsections'],
            $params['newactivities'],
            (int) $USER->id
        );
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'courseid' => new external_value(PARAM_INT, 'Created course ID'),
            'courseurl' => new external_value(PARAM_URL, 'Course URL', VALUE_OPTIONAL),
            'fullname' => new external_value(PARAM_TEXT, 'Course fullname'),
            'shortname' => new external_value(PARAM_TEXT, 'Course shortname'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
            'partial' => new external_value(
                PARAM_BOOL,
                'Whether course content was only partially applied',
                VALUE_DEFAULT,
                false
            ),
            'haswarnings' => new external_value(
                PARAM_BOOL,
                'Whether activity creation warnings were detected',
                VALUE_DEFAULT,
                false
            ),
            'warningscount' => new external_value(PARAM_INT, 'Count of skipped activity creations', VALUE_DEFAULT, 0),
            'activityerrors' => new external_multiple_structure(
                new external_single_structure([
                    'resource_type' => new external_value(PARAM_TEXT, 'Activity/module type that failed'),
                    'section' => new external_value(PARAM_INT, 'Destination section number'),
                    'message' => new external_value(PARAM_TEXT, 'Failure reason'),
                    'title' => new external_value(PARAM_TEXT, 'Activity title, if known'),
                ]),
                'Per-activity failures that did not abort the whole course creation',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }
}
