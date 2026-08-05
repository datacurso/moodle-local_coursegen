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
 * External API to regenerate a single section, activity, or image in the detailed plan.
 *
 * @package    local_coursegen
 * @category   external
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\service\course_session_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External API to regenerate a single section, activity, or image in the detailed plan.
 */
class regenerate_detailed_item extends external_api {
    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'recordid' => new external_value(PARAM_INT, 'ID from local_coursegen_course_sessions'),
            'target_type' => new external_value(PARAM_ALPHA, 'Target type: section, activity, or image'),
            'section_index' => new external_value(PARAM_INT, '0-based section index'),
            'activity_index' => new external_value(PARAM_INT, '0-based activity index (for activity/image)', VALUE_DEFAULT, -1),
            'instruction' => new external_value(PARAM_TEXT, 'Adjustment text for the regenerated item', VALUE_DEFAULT, ''),
            'deleted' => new external_value(PARAM_BOOL, 'Mark target as deleted in plan', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Regenerate a single item in the detailed plan.
     *
     * @param int $recordid Session record id
     * @param string $targettype Target type (section|activity|image)
     * @param int $sectionindex Section index
     * @param int $activityindex Activity index (-1 if not applicable)
     * @param string $instruction Adjustment text
     * @param bool $deleted Mark target as deleted
     * @return array
     */
    public static function execute(
        int $recordid,
        string $targettype,
        int $sectionindex,
        int $activityindex = -1,
        string $instruction = '',
        bool $deleted = false
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'recordid' => $recordid,
            'target_type' => $targettype,
            'section_index' => $sectionindex,
            'activity_index' => $activityindex,
            'instruction' => $instruction,
            'deleted' => $deleted,
        ]);

        $context = context_system::instance();
        self::validate_context($context);

        $session = course_session_service::get_user_session($params['recordid'], $USER->id);
        $sessionid = $session->get('session_id');

        if (!$sessionid) {
            throw new \moodle_exception('error_no_session_found', 'local_coursegen');
        }

        $apiservice = new ai_course_api_service();

        try {
            $result = $apiservice->regenerate_detailed_item(
                $sessionid,
                $params['target_type'],
                $params['section_index'],
                $params['activity_index'] >= 0 ? $params['activity_index'] : null,
                $params['instruction'],
                (bool)$params['deleted']
            );
        } catch (\moodle_exception $e) {
            throw new \moodle_exception('error_sending_feedback', 'local_coursegen', '', $e->getMessage());
        }

        return [
            'success' => !empty($result['success']),
            'result' => json_encode($result),
        ];
    }

    /**
     * Returns description of method return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'result' => new external_value(PARAM_RAW, 'JSON result from the AI backend'),
        ]);
    }
}
