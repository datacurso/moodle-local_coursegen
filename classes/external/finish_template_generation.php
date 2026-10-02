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
use local_coursegen\local\reference\generated_reference_files;
use local_coursegen\local\reference\reference_file_storage;
use local_coursegen\local\reference\reference_file_urls;
use local_coursegen\local\reference\reference_leftover_guard;
use local_coursegen\local\service\activity_link_resolver;
use local_coursegen\local\service\course_creation_guard;
use local_coursegen\local\service\course_review_service;
use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\create_course_service;
use local_coursegen\local\service\generated_activities_filter;
use local_coursegen\local\service\kept_link_rewriter;
use local_coursegen\local\service\template_ai_api_service;
use local_coursegen\local\service\template_course_order;
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
            'fullname' => new external_value(PARAM_TEXT, 'Course fullname chosen at the review', VALUE_DEFAULT, ''),
            'shortname' => new external_value(PARAM_TEXT, 'Course shortname chosen at the review', VALUE_DEFAULT, ''),
            'category' => new external_value(PARAM_INT, 'Course category chosen at the review', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Build the course from the finished result.
     *
     * @param int $sessionid
     * @param string $fullname Course fullname chosen at the review.
     * @param string $shortname Course shortname chosen at the review.
     * @param int $category Course category chosen at the review.
     * @return array
     */
    public static function execute($sessionid, $fullname = '', $shortname = '', $category = 0) {
        global $USER, $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'sessionid' => $sessionid,
            'fullname' => $fullname,
            'shortname' => $shortname,
            'category' => $category,
        ]);

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
        $writtenactivities = generated_activities_filter::only_ai_written($generatedactivities);
        $result['generated_activities'] = self::with_reference_files($session, $writtenactivities);

        $overrides = course_review_service::overrides(
            (string) $params['fullname'],
            (string) $params['shortname'],
            (int) $params['category']
        );
        $created = create_course_service::create_course($session, $result, $overrides);
        course_creation_guard::ensure_created($created);
        $courseid = $created['courseid'] ?? 0;
        $courseid = (int) $courseid;
        $keptcms = [];
        if ($courseid > 0 && $templateid !== null && $templateid > 0) {
            template_keep_copier::copy_into($templateid, $courseid, $keptcms);
        }
        $generatedcms = $created['generatedcms'] ?? [];
        self::arrange_course($templateid, $courseid, $generatedactivities, $generatedcms, $keptcms);
        self::resolve_activity_links($session, $courseid, $generatedactivities, $generatedcms, $keptcms);
        self::settle_reference_files($session, $courseid, $generatedcms);

        return self::created_response($courseid, $CFG->wwwroot);
    }

    /**
     * Make the kept activities and the order of the course follow the template.
     *
     * @param int|null $templateid
     * @param int $courseid
     * @param array $payloadactivities Every activity entry of the result, kept ones included.
     * @param array $generatedcms Payload cmid => created cmid, for the generated activities.
     * @param array $keptcms Payload cmid => created cmid, for the copied kept activities.
     */
    private static function arrange_course(
        ?int $templateid,
        int $courseid,
        array $payloadactivities,
        array $generatedcms,
        array $keptcms
    ): void {
        if ($courseid <= 0 || $templateid === null || $templateid <= 0) {
            return;
        }
        kept_link_rewriter::rewrite_for_course($courseid, $payloadactivities, $generatedcms, $keptcms);
        template_course_order::apply($templateid, $courseid, $payloadactivities, $generatedcms, $keptcms);
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
     * Put the files the teacher brought where the run left the token of each reference.
     *
     * Done before the activities are built, so the copy of the files a text
     * refers to carries them into the new activities. Nothing of the template's
     * own file is left in those texts: the service replaced it with the token.
     *
     * @param course_session $session
     * @param array[] $activities The activity entries the run wrote.
     * @return array[]
     */
    private static function with_reference_files(course_session $session, array $activities): array {
        $urlbyslot = reference_file_urls::for_course_session($session);
        return generated_reference_files::apply($activities, $urlbyslot);
    }

    /**
     * Check that every reference was carried into the course, then delete the temporary files.
     *
     * When one was not, the generation is marked failed and the error reaches
     * the caller, so no course is left pointing at a file about to disappear.
     *
     * @param course_session $session
     * @param int $courseid
     * @param array $generatedcms Payload cmid => created cmid, for the generated activities.
     */
    private static function settle_reference_files(course_session $session, int $courseid, array $generatedcms): void {
        try {
            $generatedcmids = array_values($generatedcms);
            reference_leftover_guard::ensure_none_left($courseid, $generatedcmids);
        } catch (\Throwable $exception) {
            $sessionid = (int) $session->get('id');
            course_session_service::update_status($sessionid, course_session::STATUS_FAILED);
            throw $exception;
        }
        $userid = (int) $session->get('userid');
        $sessionid = (int) $session->get('id');
        reference_file_storage::delete_session($userid, $sessionid);
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
     * The finished response shape, the one creating a course without a template answers with.
     *
     * @param int $courseid
     * @param string $wwwroot
     * @return array
     */
    private static function created_response(int $courseid, string $wwwroot): array {
        $course = get_course($courseid);
        return [
            'success' => true,
            'courseid' => $courseid,
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
            'message' => get_string('coursecreated', 'local_coursegen'),
            'courseurl' => $wwwroot . '/course/view.php?id=' . $courseid,
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Always true once the course exists'),
            'courseid' => new external_value(PARAM_INT, 'Created course id'),
            'fullname' => new external_value(PARAM_TEXT, 'Course fullname'),
            'shortname' => new external_value(PARAM_TEXT, 'Course shortname'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
            'courseurl' => new external_value(PARAM_RAW, 'Created course URL'),
        ]);
    }
}
