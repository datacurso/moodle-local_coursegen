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
 * External API for creating courses with AI assistance.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use local_coursegen\event\generation_result_applied;
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\create_course_service;
use local_coursegen\task\import_generated_course_task;
use moodle_exception;
use context_coursecat;
use context_course;
use context_system;
use invalid_parameter_exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External API for creating courses with AI assistance.
 */
class create_course extends external_api {
    /** @var string Target mode: create a new course (today's behaviour). */
    const TARGETMODE_NEW = 'new';

    /** @var string Target mode: generate a staging course and import it into an existing course. */
    const TARGETMODE_IMPORT = 'import';

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'recordid' => new external_value(PARAM_INT, 'Course planning session record ID'),
            'fullname' => new external_value(PARAM_TEXT, 'Override course fullname', VALUE_DEFAULT, ''),
            'shortname' => new external_value(PARAM_TEXT, 'Override course shortname', VALUE_DEFAULT, ''),
            'category' => new external_value(PARAM_INT, 'Override course category ID', VALUE_DEFAULT, 0),
            'targetmode' => new external_value(
                PARAM_ALPHA,
                'Target mode: "new" creates a course, "import" imports the generated course into an existing one',
                VALUE_DEFAULT,
                self::TARGETMODE_NEW
            ),
            'targetcourseid' => new external_value(
                PARAM_INT,
                'Existing course to import into (import mode only)',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Create a course from an AI planning session.
     *
     * All business logic (coursedata parsing, category resolution, permission
     * checks, API dispatch) is handled by the service.
     *
     * @param int $recordid Session record ID in local_coursegen_course_sessions
     * @param string $fullname Optional override for the course fullname
     * @param string $shortname Optional override for the course shortname
     * @param int $category Optional override for the course category ID
     * @param string $targetmode One of the TARGETMODE_* constants
     * @param int $targetcourseid Existing course to import into (import mode only)
     * @return array Result of the course content application
     * @throws moodle_exception
     */
    public static function execute(
        $recordid,
        $fullname = '',
        $shortname = '',
        $category = 0,
        $targetmode = self::TARGETMODE_NEW,
        $targetcourseid = 0
    ) {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'recordid' => $recordid,
            'fullname' => $fullname,
            'shortname' => $shortname,
            'category' => $category,
            'targetmode' => $targetmode,
            'targetcourseid' => $targetcourseid,
        ]);
        if (!in_array($params['targetmode'], [self::TARGETMODE_NEW, self::TARGETMODE_IMPORT], true)) {
            throw new invalid_parameter_exception('Unknown targetmode: ' . $params['targetmode']);
        }
        $importing = $params['targetmode'] === self::TARGETMODE_IMPORT;

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/course:create', $context);
        require_capability('local/coursegen:createcoursewithai', $context);

        $recordid = (int)$params['recordid'];

        // Load session (validates ownership).
        $session = course_session_service::get_user_session($recordid, $USER->id);

        // In import mode the target is validated and the import permissions are
        // checked before anything is fetched or materialised: a denial must leave
        // no staging course behind.
        $target = null;
        if ($importing) {
            $target = self::require_import_target((int)$params['targetcourseid']);
        }

        // Fetch the AI-generated result data from the Datacurso API.
        $apiservice = static::get_api_service();
        $result = $apiservice->get_course_result((string)$session->get('session_id'));
        $resultdata = $result['result'] ?? [];

        // Build overrides from user-provided params (non-empty values only).
        $overrides = [];
        $fullname = trim((string)$params['fullname']);
        if ($fullname !== '') {
            $overrides['fullname'] = $fullname;
        }
        $shortname = trim((string)$params['shortname']);
        if ($shortname !== '') {
            $overrides['shortname'] = $shortname;
        }
        $category = (int)$params['category'];
        if ($category > 0) {
            $overrides['category'] = $category;
        }
        if ($target !== null) {
            // The staging course always lives next to the target, so the user needs
            // no course creation permission anywhere else.
            $overrides['category'] = (int)$target->category;
        }

        $outcome = create_course_service::create_course($session, $resultdata, $overrides);
        $outcome['importqueued'] = false;
        $outcome['targetcourseid'] = 0;
        if ($target !== null && !empty($outcome['success']) && !empty($outcome['courseid'])) {
            import_generated_course_task::queue(
                (int)$session->get('id'),
                (int)$outcome['courseid'],
                (int)$target->id,
                (int)$USER->id
            );
            $outcome['importqueued'] = true;
            $outcome['targetcourseid'] = (int)$target->id;
        }

        // Applying a generation result is a state change worth auditing. Only
        // the ids travel: no course content, no prompt.
        if (!empty($outcome['success']) && !empty($outcome['courseid'])) {
            generation_result_applied::create([
                'context' => $context,
                'other' => [
                    'jobid' => (string)$session->get('session_id'),
                    'courseid' => (int)$outcome['courseid'],
                ],
            ])->trigger();
        }

        return $outcome;
    }

    /**
     * Validate the import target and require the permissions the import needs on it.
     *
     * Mirrors the checks course_import_service performs before touching anything,
     * except moodle/backup:backuptargetimport on the staging course, which does
     * not exist yet; the service checks it when the task runs.
     *
     * @param int $targetcourseid Existing course chosen by the user.
     * @return \stdClass Target course record.
     * @throws invalid_parameter_exception When no target was given.
     * @throws moodle_exception When the feature is disabled or the target is not importable.
     * @throws \required_capability_exception When the user may not import into the target.
     */
    private static function require_import_target(int $targetcourseid): \stdClass {
        global $DB;

        if (empty(get_config('local_coursegen', 'enableimportexisting'))) {
            throw new moodle_exception('error_import_existing_disabled', 'local_coursegen');
        }
        if ($targetcourseid <= 0) {
            throw new invalid_parameter_exception('targetcourseid is required in import mode');
        }
        $target = $DB->get_record('course', ['id' => $targetcourseid]);
        if (!$target || (int)$target->id === (int)SITEID) {
            throw new moodle_exception('error_import_target_invalid', 'local_coursegen');
        }

        $targetcontext = context_course::instance($target->id);
        require_capability('moodle/backup:backupcourse', $targetcontext);
        require_capability('moodle/restore:restorecourse', $targetcontext);
        require_capability('moodle/course:create', context_coursecat::instance($target->category));
        // The import hides the original course, so the acting user must be allowed to change its visibility.
        require_capability('moodle/course:visibility', $targetcontext);

        return $target;
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
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'courseid' => new external_value(PARAM_INT, 'Created course ID'),
            'shortname' => new external_value(PARAM_TEXT, 'Course shortname'),
            'fullname' => new external_value(PARAM_TEXT, 'Course fullname'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
            'courseurl' => new external_value(PARAM_URL, 'Course URL', VALUE_OPTIONAL),
            'partial' => new external_value(PARAM_BOOL, 'Whether course content was only partially applied', VALUE_DEFAULT, false),
            'haswarnings' => new external_value(
                PARAM_BOOL,
                'Whether activity creation warnings were detected',
                VALUE_DEFAULT,
                false
            ),
            'warningscount' => new external_value(PARAM_INT, 'Count of skipped activity creations', VALUE_DEFAULT, 0),
            'importqueued' => new external_value(
                PARAM_BOOL,
                'Whether an import into an existing course was queued',
                VALUE_DEFAULT,
                false
            ),
            'targetcourseid' => new external_value(PARAM_INT, 'Existing course the import targets', VALUE_DEFAULT, 0),
        ]);
    }
}
