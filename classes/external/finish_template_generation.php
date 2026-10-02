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
use local_coursegen\local\service\course_creation_guard;
use local_coursegen\local\service\activity_link_resolver;
use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\create_course_service;
use local_coursegen\local\service\generated_activities_filter;
use local_coursegen\local\service\template_ai_api_service;
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
        require_capability('local/coursegen:createtemplatecoursewithai', $context);

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
        $generatedactivities = $result['generated_activities'] ?? [];
        $result['generated_activities'] = generated_activities_filter::only_ai_written($generatedactivities);

        $created = create_course_service::create_course($session, $result);
        course_creation_guard::ensure_created($created);
        $courseid = $created['courseid'] ?? 0;
        $courseid = (int) $courseid;
        $keptcms = [];
        if ($courseid > 0 && $templateid !== null && $templateid > 0) {
            template_keep_copier::copy_into($templateid, $courseid, $keptcms);
        }
        $generatedcms = $created['generatedcms'] ?? [];
        self::resolve_activity_links($session, $courseid, $generatedactivities, $generatedcms, $keptcms);

        return self::created_response($courseid, $CFG->wwwroot);
    }

    /**
     * Turn the link tokens of the generated activities into real URLs.
     *
     * Runs once every activity exists, the copied kept ones included, since a
     * token may name any of them. When one cannot be resolved the generation
     * is marked failed, so a retry is not answered as if it had finished, and
     * the error reaches the caller.
     *
     * @param course_session $session
     * @param int $courseid
     * @param array $payloadactivities Every activity entry of the result, kept ones included.
     * @param array $generatedcms Payload cmid => created cmid, for the generated activities.
     * @param array $keptcms Payload cmid => created cmid, for the copied kept activities.
     */
    private static function resolve_activity_links(
        course_session $session,
        int $courseid,
        array $payloadactivities,
        array $generatedcms,
        array $keptcms
    ): void {
        try {
            activity_link_resolver::resolve_for_course($courseid, $payloadactivities, $generatedcms, $keptcms);
        } catch (\Throwable $exception) {
            $sessionid = (int) $session->get('id');
            course_session_service::update_status($sessionid, course_session::STATUS_FAILED);
            throw $exception;
        }
    }

    /**
     * Which template this session was started from.
     *
     * @param course_session $session
     * @return int|null Null when the session predates this field.
     */
    private static function template_id_of(course_session $session): ?int {
        $coursedata = $session->get('coursedata');
        $coursedata = (string) $coursedata;
        $data = json_decode($coursedata, true);
        $templateid = $data['templateid'] ?? null;
        if ($templateid !== null) {
            $templateid = (int) $templateid;
        }
        return $templateid;
    }

    /**
     * The finished response shape.
     *
     * @param int $courseid
     * @param string $wwwroot
     * @return array
     */
    private static function created_response(int $courseid, string $wwwroot): array {
        $courseurl = '';
        if ($courseid > 0) {
            $courseurl = $wwwroot . '/course/view.php?id=' . $courseid;
        }
        return [
            'status' => 'completed',
            'courseid' => $courseid,
            'courseurl' => $courseurl,
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
