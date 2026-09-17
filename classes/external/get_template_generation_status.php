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
 * Polls a template generation and creates the course once it is ready.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_coursegen\local\models\course_session;
use local_coursegen\local\service\create_course_service;
use local_coursegen\local\service\template_ai_api_service;
use local_coursegen\local\service\template_keep_copier;
use local_coursegen\local\service\template_layout_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * One poll step: still running, or finished and the course created.
 */
class get_template_generation_status extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'sessionid' => new external_value(PARAM_INT, 'Local session id'),
        ]);
    }

    /**
     * Poll, and create the course when the result is ready.
     *
     * @param int $sessionid
     * @return array
     */
    public static function execute($sessionid) {
        global $USER, $CFG;

        $params = self::validate_parameters(self::execute_parameters(), ['sessionid' => $sessionid]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:createcoursewithai', $context);

        $session = new course_session($params['sessionid']);
        if ((int) $session->get('userid') !== (int) $USER->id) {
            throw new \moodle_exception('nopermissions', 'error', '', 'view this generation');
        }

        // Already finished on a previous poll.
        if ((int) $session->get('status') === course_session::STATUS_CREATED) {
            return self::created_response((int) $session->get('courseid'), $CFG->wwwroot);
        }

        $api = new template_ai_api_service();
        $threadid = $session->get('session_id');

        $state = $api->get_status($threadid);
        if ($state['status'] === 'failed') {
            throw new \moodle_exception(
                'error_generating_resource',
                'local_coursegen',
                '',
                null,
                $state['error_message']
            );
        }
        if ($state['status'] !== 'completed') {
            return ['status' => 'running', 'courseid' => 0, 'courseurl' => ''];
        }

        $result = $api->get_result($threadid);
        $templateid = create_course_service::template_id_of($session);

        // Only the AI-generated activities are built from the payload. The
        // kept ones already exist, fully configured and with their files, in
        // the base course - they are copied below instead of being rebuilt
        // from a JSON description that could never carry all of that.
        $result['generated_activities'] = self::ai_generated_only($result['generated_activities'] ?? [], $templateid);

        $created = create_course_service::create_course($session, $result);
        $courseid = (int) ($created['courseid'] ?? 0);
        if ($courseid > 0 && $templateid > 0) {
            $keptcms = template_keep_copier::copy_into($templateid, $courseid);
            template_layout_service::apply(
                $templateid,
                $courseid,
                template_layout_service::generated_by_instance($created['generatedcms'] ?? [], $templateid),
                $keptcms
            );
        }

        return self::created_response($courseid, $CFG->wwwroot);
    }

    /**
     * Drop the activities that are only travelling as context.
     *
     * A "keep"/"reference" activity comes back exactly as it was submitted -
     * a description of an activity that already exists elsewhere, not
     * something to build. Only the entries the AI actually generated (the
     * template's virtual instances, whose synthetic cmid names a saved
     * instance of this template) are created from the payload.
     *
     * @param array $activities
     * @param int $templateid
     * @return array
     */
    private static function ai_generated_only(array $activities, int $templateid): array {
        $generated = [];
        foreach ($activities as $activity) {
            $cmid = (int) ($activity['cmid'] ?? 0);
            if (template_layout_service::instance_id_for($cmid, $templateid) !== null) {
                $generated[] = $activity;
            }
        }
        return $generated;
    }

    /**
     * The finished response shape.
     *
     * @param int $courseid
     * @param string $wwwroot
     * @return array
     */
    private static function created_response(int $courseid, string $wwwroot): array {
        return [
            'status' => 'completed',
            'courseid' => $courseid,
            'courseurl' => $courseid > 0 ? $wwwroot . '/course/view.php?id=' . $courseid : '',
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'running or completed'),
            'courseid' => new external_value(PARAM_INT, 'Created course id, 0 while running'),
            'courseurl' => new external_value(PARAM_RAW, 'Created course URL, empty while running'),
        ]);
    }
}
