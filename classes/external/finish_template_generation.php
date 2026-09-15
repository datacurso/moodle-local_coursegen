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
 * Builds the course from a finished template generation.
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
use local_coursegen\local\service\template_export_service;
use local_coursegen\local\service\template_keep_copier;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Turns one finished generation into a real course.
 *
 * Called once, by the client that watched the generation's own SSE stream
 * report it complete: the result payload is fetched here rather than pushed
 * back up from the browser, which only ever needs to know that the run
 * finished, not to carry a whole course through itself.
 */
class finish_template_generation extends external_api {
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
     * Build the course from the finished result.
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

        // A reconnecting client can land here twice; the course is built once.
        if ((int) $session->get('status') === course_session::STATUS_CREATED) {
            return self::created_response((int) $session->get('courseid'), $CFG->wwwroot);
        }

        $api = new template_ai_api_service();
        $result = $api->get_result($session->get('session_id'));
        $templateid = self::template_id_of($session);

        // Only the AI-generated activities are built from the payload. The
        // kept ones already exist, fully configured and with their files, in
        // the base course - they are copied below instead of being rebuilt
        // from a JSON description that could never carry all of that.
        $result['generated_activities'] = self::ai_generated_only($result['generated_activities'] ?? []);

        $created = create_course_service::create_course($session, $result);
        $courseid = (int) ($created['courseid'] ?? 0);
        if ($courseid > 0 && $templateid > 0) {
            template_keep_copier::copy_into($templateid, $courseid);
        }

        return self::created_response($courseid, $CFG->wwwroot);
    }

    /**
     * Which template this session was started from.
     *
     * @param course_session $session
     * @return int 0 when the session predates this field.
     */
    private static function template_id_of(course_session $session): int {
        $data = json_decode((string) $session->get('coursedata'), true);
        return (int) ($data['templateid'] ?? 0);
    }

    /**
     * Drop the activities that are only travelling as context.
     *
     * A "keep"/"reference" activity comes back exactly as it was submitted -
     * a description of an activity that already exists elsewhere, not
     * something to build. Only the entries the AI actually generated (the
     * template's virtual instances, which carry a synthetic cmid) are created
     * from the payload.
     *
     * @param array $activities
     * @return array
     */
    private static function ai_generated_only(array $activities): array {
        $generated = [];
        foreach ($activities as $activity) {
            if ((int) ($activity['cmid'] ?? 0) >= template_export_service::INSTANCE_CMID_BASE) {
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
            'status' => new external_value(PARAM_ALPHA, 'Always "completed" once the course exists'),
            'courseid' => new external_value(PARAM_INT, 'Created course id'),
            'courseurl' => new external_value(PARAM_RAW, 'Created course URL'),
        ]);
    }
}
