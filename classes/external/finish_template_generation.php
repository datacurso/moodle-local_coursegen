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
use local_coursegen\local\service\activity_link_resolver;
use local_coursegen\local\service\course_creation_guard;
use local_coursegen\local\service\course_review_service;
use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\create_course_service;
use local_coursegen\local\service\generated_activities_filter;
use local_coursegen\local\service\kept_link_rewriter;
use local_coursegen\local\service\template_ai_api_service;
use local_coursegen\local\service\template_course_image_applier;
use local_coursegen\local\service\template_course_order;
use local_coursegen\local\service\template_keep_copier;
use local_coursegen\local\service\template_run_history;
use local_coursegen\local\service\template_settings_copier;
use local_coursegen\local\service\template_syllabus_linker;
use local_coursegen\local\space\activity_spaces;
use local_coursegen\local\space\file_spaces;
use local_coursegen\local\space\space_file_storage;
use local_coursegen\local\space\space_resource_file;
use local_coursegen\local\space\space_scope;
use local_coursegen\local\space\space_selection;

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
    /** @var int Seconds a request waits for the one that is building the course. */
    private const WAIT_SECONDS = 600;

    /** @var int Seconds without news after which a creation that says it is going on is taken as dead. */
    private const STALE_SECONDS = 90;

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
        try {
            return self::build_course($sessionid, $fullname, $shortname, $category);
        } catch (\Throwable $e) {
            // The person sees a short message; the cause is kept in the log of the server.
            error_log('local_coursegen: creating the course of session ' . (int) $sessionid . ' failed: ' . get_class($e) . ': '
                . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            throw $e;
        }
    }

    /**
     * Create the course of a finished generation.
     *
     * The web service and the stream of the page both end here. The stream passes a callback, which hears of each
     * step as it is done: a request that stays silent for two minutes is cut by the proxies in front of the site, and
     * the creation of a course with a hundred activities takes longer than that.
     *
     * @param int $sessionid
     * @param string $fullname
     * @param string $shortname
     * @param int $category
     * @param callable|null $progress Called with (string $step, int $done, int $total) as the creation goes on.
     * @return array
     */
    public static function build_course($sessionid, $fullname, $shortname, $category, ?callable $progress = null) {
        global $USER, $CFG, $DB;

        // Each step also says, in the record of the session, that the creation is alive: a request that finds the
        // session "creating" waits only while that is recent, and builds the course itself when it is not.
        $report = static function (string $step, int $done = 0, int $total = 0) use ($progress, $DB, $params): void {
            $DB->set_field('local_coursegen_course_sessions', 'timemodified', time(), ['id' => (int) $params['sessionid']]);
            if ($progress !== null) {
                $progress($step, $done, $total);
            }
        };

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
        // A request that is already building it goes on; this one waits for it instead of building a second course.
        // When the one that was building stopped (its process died), nobody is going to finish it: this one builds.
        if (self::is_being_built($session)) {
            // Waiting is not news of the creation, so it does not go through the report that marks it alive.
            $answer = self::wait_for_creation(
                (int) $params['sessionid'],
                $progress ?? static function (string $step, int $done = 0, int $total = 0): void {
                },
                $CFG->wwwroot
            );
            if ($answer !== null) {
                return $answer;
            }
            $session = new course_session($params['sessionid']);
        }

        $report('result');
        $api = new template_ai_api_service();
        $result = $api->get_result($session->get('session_id'));
        $templateid = self::template_id_of($session);

        // Only the AI-generated activities are built from the payload. The
        // kept ones already exist, fully configured and with their files, in
        // the base course - they are copied below instead of being rebuilt
        // from a JSON description that could never carry all of that.
        $generatedactivities = $result['generated_activities'] ?? [];
        $writtenactivities = generated_activities_filter::only_ai_written($generatedactivities);
        $result['generated_activities'] = $writtenactivities;
        $selection = self::space_selection_of($session, $templateid);

        $overrides = course_review_service::overrides(
            (string) $params['fullname'],
            (string) $params['shortname'],
            (int) $params['category']
        );
        $report('course');
        create_course_service::on_progress($report);
        try {
            $created = self::create_in_space_scope($selection, $session, $result, $overrides);
        } finally {
            create_course_service::on_progress(null);
        }
        course_creation_guard::ensure_created($created);
        $courseid = $created['courseid'] ?? 0;
        $courseid = (int) $courseid;
        $keptcms = [];
        if ($courseid > 0 && $templateid !== null && $templateid > 0) {
            $report('kept');
            template_keep_copier::copy_into($templateid, $courseid, $keptcms, $selection->filled_cmids());
        }
        self::place_space_files($session, $selection, $keptcms);
        $spacecms = self::place_space_activities($session, $templateid, $courseid);
        $generatedcms = $created['generatedcms'] ?? [];
        $report('arrange');
        self::arrange_course($templateid, $courseid, $generatedactivities, $generatedcms, $keptcms, $spacecms);
        self::resolve_activity_links($session, $courseid, $generatedactivities, $generatedcms, $keptcms);
        if ($courseid > 0) {
            template_syllabus_linker::apply(
                $courseid,
                array_values($generatedcms),
                course_session_service::syllabus_file((int) $params['sessionid'])
            );
            $coursedata = json_decode((string) $session->get('coursedata'), true);
            template_course_image_applier::apply(
                $courseid,
                (array) ($result['slot_images'] ?? []),
                (array) ($coursedata['payload']['image_slots'] ?? [])
            );
            // Kept for the conversation a reload shows again.
            template_run_history::record_completed((int) $params['sessionid'], $courseid, $generatedcms);
        }
        space_file_storage::delete_session((int) $session->get('userid'), (int) $session->get('id'));

        return self::created_response($courseid, $CFG->wwwroot);
    }

    /**
     * Copy into the new course the activities the teacher brought to the spaces of the template.
     *
     * @param course_session $session
     * @param int|null $templateid
     * @param int $courseid
     * @return array Space uid => cmid of the copy in the new course.
     */
    private static function place_space_activities(course_session $session, ?int $templateid, int $courseid): array {
        if ($templateid === null || $templateid <= 0 || $courseid <= 0) {
            return [];
        }
        $coursedata = json_decode((string) $session->get('coursedata'), true);
        $chosen = (array) ($coursedata['space_activities'] ?? []);
        return activity_spaces::place($templateid, $courseid, $chosen);
    }

    /**
     * Whether another request is building the course of the session right now.
     *
     * The session says "creating" while a request builds it, and a request that dies leaves it saying so. What tells
     * them apart is the time of the last news: a creation that has not moved for a while is not going on.
     *
     * @param course_session $session
     * @return bool
     */
    private static function is_being_built(course_session $session): bool {
        return (int) $session->get('status') === course_session::STATUS_CREATING
            && time() - (int) $session->get('timemodified') < self::STALE_SECONDS;
    }

    /**
     * Wait for the request that is building the course, and answer as it does.
     *
     * Each turn tells the one who listens that it is still waiting, so a stream that waits is never silent.
     *
     * @param int $sessionid Local session id.
     * @param callable $report Called with (string $step, int $done, int $total).
     * @param string $wwwroot
     * @return array|null The created course, or null when the creation stopped without ending.
     * @throws \moodle_exception When the creation failed or did not end in time.
     */
    private static function wait_for_creation(int $sessionid, callable $report, string $wwwroot): ?array {
        $deadline = time() + self::WAIT_SECONDS;
        while (time() < $deadline) {
            sleep(2);
            $session = new course_session($sessionid);
            $status = (int) $session->get('status');
            if ($status === course_session::STATUS_CREATED) {
                return self::created_response((int) $session->get('courseid'), $wwwroot);
            }
            if ($status === course_session::STATUS_FAILED) {
                throw new \moodle_exception('error_course_creation_failed', 'local_coursegen');
            }
            if (!self::is_being_built($session)) {
                return null;
            }
            $report('waiting');
        }
        course_session_service::update_status($sessionid, course_session::STATUS_FAILED);
        throw new \moodle_exception('error_course_creation_failed', 'local_coursegen');
    }

    /**
     * Make the kept activities and the order of the course follow the template.
     *
     * @param int|null $templateid
     * @param int $courseid
     * @param array $payloadactivities Every activity entry of the result, kept ones included.
     * @param array $generatedcms Payload cmid => created cmid, for the generated activities.
     * @param array $keptcms Payload cmid => created cmid, for the copied kept activities.
     * @param array $spacecms Space uid => cmid of the activity the teacher brought, in the new course.
     */
    private static function arrange_course(
        ?int $templateid,
        int $courseid,
        array $payloadactivities,
        array $generatedcms,
        array $keptcms,
        array $spacecms = []
    ): void {
        if ($courseid <= 0 || $templateid === null || $templateid <= 0) {
            return;
        }
        kept_link_rewriter::rewrite_for_course($courseid, $payloadactivities, $generatedcms, $keptcms);
        template_course_order::apply($templateid, $courseid, $payloadactivities, $generatedcms, $keptcms, $spacecms);
        template_settings_copier::copy_into($templateid, $courseid, $payloadactivities, $generatedcms, $keptcms);
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
     * The spaces of the template with the files the teacher brought for this generation.
     *
     * @param course_session $session
     * @param int|null $templateid
     * @return space_selection
     */
    private static function space_selection_of(course_session $session, ?int $templateid): space_selection {
        if ($templateid === null || $templateid <= 0) {
            return new space_selection([], []);
        }
        $userid = (int) $session->get('userid');
        $sessionid = (int) $session->get('id');
        return file_spaces::selection_of_session($templateid, $userid, $sessionid);
    }

    /**
     * Build the course with the spaces in scope, so every activity gets the teacher's file or loses its element.
     *
     * @param space_selection $selection
     * @param course_session $session
     * @param array $result The result with the activities to build.
     * @param array $overrides What the teacher chose at the review.
     * @return array What create_course returns.
     */
    private static function create_in_space_scope(
        space_selection $selection,
        course_session $session,
        array $result,
        array $overrides
    ): array {
        space_scope::enter($selection);
        try {
            return create_course_service::create_course($session, $result, $overrides);
        } finally {
            space_scope::leave();
        }
    }

    /**
     * Put the teacher's file in the copy of each space's resource.
     *
     * When a copy is missing the generation is marked failed and the error reaches the caller.
     *
     * @param course_session $session
     * @param space_selection $selection
     * @param array $keptcms Base course cmid => created cmid, for the copied activities.
     */
    private static function place_space_files(course_session $session, space_selection $selection, array $keptcms): void {
        foreach ($selection->filled() as $space) {
            $created = $keptcms[$space->cmid] ?? null;
            if ($created === null) {
                $sessionid = (int) $session->get('id');
                course_session_service::update_status($sessionid, course_session::STATUS_FAILED);
                throw new \moodle_exception('spacecopyfailed', 'local_coursegen', '', $space->name);
            }
            space_resource_file::replace((int) $created, $selection->file_of($space));
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
