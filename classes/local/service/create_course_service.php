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

namespace local_coursegen\local\service;

use core\context\course;
use core\context\coursecat;
use core\context\system;
use core\exception\moodle_exception;
use core_course_category;
use local_coursegen\event\generation_failed;
use local_coursegen\event\generation_result_applied;
use local_coursegen\local\models\course_session;

/**
 * Service responsible for creating a course from an AI planning session.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_course_service {
    /**
     * Execute course creation from AI-generated result data.
     *
     * The result data must already be fetched from the API; this method only
     * processes it. This separation makes the method testable without requiring
     * a live API connection.
     *
     * Optional overrides allow the user to modify course identity fields
     * (fullname, shortname, category) before creation, as set via the review modal.
     *
     * @param course_session $session Planning session persistent.
     * @param array $resultdata Result data from the Datacurso API (course_configuration, sections, activities).
     * @param array $overrides Optional user overrides for course fields.
     *     Supported keys: fullname (string), shortname (string), category (int).
     * @return array Result of the course content application.
     */
    public static function create_course(course_session $session, array $resultdata, array $overrides = []): array {
        global $CFG;

        // Resolve the effective category (user override → AI value → site default)
        // and require course creation permission in that exact category before any
        // creation work happens. Thrown (not returned) so callers surface it as a
        // proper permission error.
        $effectivecategoryid = self::resolve_effective_category($resultdata, $overrides);
        require_capability('moodle/course:create', coursecat::instance($effectivecategoryid));

        try {
            // This request may take a long time depending on the complexity of the prompt that the AI has to resolve.
            \core_php_time_limit::raise();
            raise_memory_limit(MEMORY_EXTRA);
            // Release the session so other tabs in the same session are not blocked.
            \core\session\manager::write_close();

            require_once($CFG->dirroot . '/course/lib.php');

            $coursedata = self::resolve_course_data($resultdata, $overrides);
            $course = self::create_shell_course($session, $coursedata);
            $activityerrors = self::populate_structure($course, $resultdata);
            $repairs = self::ensure_course_structure_consistent((int)$course->id);

            return self::finalise_session($session, $course, $activityerrors, $repairs);
        } catch (\Throwable $e) {
            return self::fail_session($session, $e);
        }
    }

    /**
     * Build the course identity from the AI result, apply the user overrides and make
     * the unique fields unique.
     *
     * Overrides (from the review modal) take precedence over the API response values.
     *
     * @param array $resultdata Result data from the Datacurso API.
     * @param array $overrides Optional user overrides: fullname, shortname, category.
     * @return \stdClass Course data ready for create_course().
     */
    private static function resolve_course_data(array $resultdata, array $overrides): \stdClass {
        $coursedata = self::build_course_data_from_api($resultdata);

        if (!empty($overrides['fullname'])) {
            $coursedata->fullname = (string)\core_text::substr($overrides['fullname'], 0, 255);
        }
        if (!empty($overrides['shortname'])) {
            $coursedata->shortname = (string)\core_text::substr(trim($overrides['shortname']), 0, 100);
        }
        if (!empty($overrides['category'])) {
            $coursedata->category = (int)$overrides['category'];
        }

        return self::ensure_unique_course_fields($coursedata);
    }

    /**
     * Create the Moodle course, audit it and bind the planning session to it.
     *
     * The session is marked as creating until the structure is complete.
     *
     * @param course_session $session Planning session persistent.
     * @param \stdClass $coursedata Course data from resolve_course_data().
     * @return \stdClass The created course record.
     */
    private static function create_shell_course(course_session $session, \stdClass $coursedata): \stdClass {
        $course = create_course($coursedata);

        generation_result_applied::create([
            'context' => course::instance($course->id),
            'other' => ['courseid' => (int)$course->id],
        ])->trigger();

        $sessionid = (int)$session->get('id');
        $sessionpersistent = new course_session($sessionid);
        $sessionpersistent->set('courseid', $course->id);
        $sessionpersistent->set('timemodified', time());
        $sessionpersistent->update();
        course_session_service::update_status($sessionid, course_session::STATUS_CREATING);

        return $course;
    }

    /**
     * Create the sections, subsections and activities of the result inside the course.
     *
     * @param \stdClass $course Course record.
     * @param array $resultdata Result data from the Datacurso API.
     * @return array Activity creation errors (resource_type, section, message, title).
     */
    private static function populate_structure(\stdClass $course, array $resultdata): array {
        if (!empty($resultdata['sections_info'])) {
            self::process_course_sections($course->id, $resultdata['sections_info']);
        }

        // Index declared subsections (Moodle 4.5 delegated sections) so the
        // activity loop can materialize each one lazily, in presentation order.
        $subsections = self::index_declared_subsections($resultdata['subsections_info'] ?? []);

        $activityerrors = [];
        if (!empty($resultdata['generated_activities'])) {
            $activityerrors = self::process_generated_activities(
                $course->id,
                $resultdata['generated_activities'],
                $subsections
            );
        }

        // Subsections declared without activities materialize at the end of
        // their parent section.
        self::materialize_remaining_subsections($course->id, $subsections, $activityerrors);

        return $activityerrors;
    }

    /**
     * Mark the session created, report the degradations and build the success response.
     *
     * @param course_session $session Planning session persistent.
     * @param \stdClass $course Created course record.
     * @param array $activityerrors Activity creation errors from populate_structure().
     * @param int $repairs Number of structure repairs made by ensure_course_structure_consistent().
     * @return array Success response.
     */
    private static function finalise_session(
        course_session $session,
        \stdClass $course,
        array $activityerrors,
        int $repairs
    ): array {
        $sessionid = (int)$session->get('id');
        course_session_service::update_status($sessionid, course_session::STATUS_CREATED);

        if (!empty($activityerrors)) {
            debugging(
                'local_coursegen: created course with module errors. Session ' . $sessionid
                . '. Errors: ' . json_encode($activityerrors, JSON_UNESCAPED_UNICODE)
            );
        }

        if ($repairs > 0) {
            debugging(
                'local_coursegen: repaired the course structure while creating course '
                . $course->id . '. Repairs: ' . $repairs
            );
        }

        $message = get_string('coursecreated', 'local_coursegen');
        if (!empty($activityerrors)) {
            $message .= ' ' . get_string('coursecreated_partial', 'local_coursegen');
        }

        return [
            'success' => true,
            'courseid' => $course->id,
            'shortname' => $course->shortname,
            'fullname' => $course->fullname,
            'message' => $message,
            'courseurl' => course_get_url($course->id)->out(),
            'partial' => !empty($activityerrors),
            'haswarnings' => !empty($activityerrors),
            'warningscount' => count($activityerrors),
            'activityerrors' => $activityerrors,
        ];
    }

    /**
     * Mark the session failed, audit the failure and build the failure response.
     *
     * The technical detail stays in developer debugging: the client receives a
     * localized message without internal information.
     *
     * @param course_session $session Planning session persistent.
     * @param \Throwable $e The failure.
     * @return array Failure response.
     */
    private static function fail_session(course_session $session, \Throwable $e): array {
        course_session_service::update_status((int)$session->get('id'), course_session::STATUS_FAILED);

        debugging('local_coursegen: course creation failed. ' . $e->getMessage());

        generation_failed::create([
            'context' => system::instance(),
            'other' => ['reason' => get_class($e)],
        ])->trigger();

        return [
            'success' => false,
            'courseid' => 0,
            'shortname' => '',
            'fullname' => '',
            'message' => get_string('error_course_creation_failed', 'local_coursegen'),
            'partial' => false,
            'haswarnings' => false,
            'warningscount' => 0,
        ];
    }

    /**
     * Resolve the category the course will effectively be created in.
     *
     * Precedence: user override → AI-generated value → site default category.
     *
     * @param array $resultdata Result data from the Datacurso API.
     * @param array $overrides Optional user overrides for course fields.
     * @return int Category id.
     */
    private static function resolve_effective_category(array $resultdata, array $overrides): int {
        if (!empty($overrides['category'])) {
            return (int)$overrides['category'];
        }

        $config = $resultdata['course_configuration'] ?? null;
        if (is_array($config) && !empty($config['category'])) {
            return (int)$config['category'];
        }

        $defaultcategory = core_course_category::get_default();
        return $defaultcategory ? (int)$defaultcategory->id : 0;
    }

    /**
     * Build course data object entirely from the API response.
     *
     * @param array $resultdata Final payload from the Datacurso service.
     * @return \stdClass
     */
    private static function build_course_data_from_api(array $resultdata): \stdClass {
        $coursedata = new \stdClass();

        $defaultcategory = core_course_category::get_default();
        $defaultcategoryid = $defaultcategory ? (int)$defaultcategory->id : 0;

        $config = $resultdata['course_configuration'] ?? null;
        if (!is_array($config)) {
            $coursedata->fullname = get_string('createwithai', 'local_coursegen');
            $coursedata->shortname = 'courseai-' . time();
            $coursedata->category = $defaultcategoryid;
            return $coursedata;
        }

        $fullname = trim((string)($config['fullname'] ?? ''));
        if ($fullname !== '') {
            $coursedata->fullname = (string)\core_text::substr($fullname, 0, 255);
        } else {
            $coursedata->fullname = get_string('createwithai', 'local_coursegen');
        }

        $shortname = trim((string)($config['shortname'] ?? ''));
        if ($shortname !== '') {
            $coursedata->shortname = (string)\core_text::substr(trim($shortname), 0, 100);
        } else {
            $coursedata->shortname = 'courseai-' . time();
        }

        $coursedata->category = (int)($config['category'] ?? $defaultcategoryid);

        return $coursedata;
    }

    /**
     * Get the final course settings from the AI-generated result, without creating the course.
     *
     * Used by the review panel to show the user the AI-generated course data
     * (fullname, shortname, category) before they confirm creation.
     *
     * @param course_session $session Planning session persistent.
     * @param array $resultdata Result data from the Datacurso API.
     * @return array Settings data with fullname, shortname, category.
     */
    public static function get_course_settings(course_session $session, array $resultdata): array {
        $coursedata = self::build_course_data_from_api($resultdata);
        return [
            'fullname' => $coursedata->fullname ?? '',
            'shortname' => $coursedata->shortname ?? '',
            'category' => $coursedata->category ?? 0,
        ];
    }

    /**
     * Ensure unique values for course fields that must be unique.
     *
     * Currently handles shortname and idnumber.
     *
     * @param \stdClass $coursedata Original course data.
     * @return \stdClass Updated course data with unique values where required.
     */
    private static function ensure_unique_course_fields(\stdClass $coursedata): \stdClass {
        global $DB;

        if (!empty($coursedata->shortname)) {
            $base = $coursedata->shortname;
            $candidate = $base;
            $suffix = 1;

            while ($DB->record_exists('course', ['shortname' => $candidate])) {
                $candidate = $base . '-' . $suffix;
                $suffix++;
            }

            $coursedata->shortname = $candidate;
        }

        if (!empty($coursedata->idnumber)) {
            $base = $coursedata->idnumber;
            $candidate = $base;
            $suffix = 1;

            while ($DB->record_exists('course', ['idnumber' => $candidate])) {
                $candidate = $base . '-' . $suffix;
                $suffix++;
            }

            $coursedata->idnumber = $candidate;
        }

        return $coursedata;
    }

    /**
     * Delete all course sections except section 0 and clear section 0 modules.
     *
     * @param int $courseid Course ID.
     * @return void
     */
    private static function delete_course_sections(int $courseid): void {
        global $DB;

        // Get course object.
        $course = get_course($courseid);

        // First, clear all modules from section 0 (general section).
        $modinfo = get_fast_modinfo($course);
        $section0 = $modinfo->get_section_info(0);

        if ($section0 && !empty($section0->sequence)) {
            // Get all course modules in section 0.
            $cms = $modinfo->get_cms();
            foreach ($cms as $cm) {
                if ($cm->sectionnum == 0) {
                    course_delete_module($cm->id);
                }
            }
        }

        // Get all sections except section 0.
        $sections = $DB->get_records_select(
            'course_sections',
            'course = ? AND section > 0',
            [$courseid],
            'section DESC'
        );

        foreach ($sections as $section) {
            // Use Moodle's core function to delete section safely.
            course_delete_section($course, $section->section);
        }
    }

    /**
     * Process course sections from API response.
     *
     * @param int $courseid Course ID.
     * @param array $sectionsinfo Sections information from API.
     * @return void
     */
    private static function process_course_sections(int $courseid, array $sectionsinfo): void {
        global $DB;

        // Get course format to handle sections properly.
        $course = get_course($courseid);
        $courseformat = course_get_format($course);

        // Delete all existing sections except section 0 (general section).
        self::delete_course_sections($courseid);

        // Get existing sections indexed by section number (should only be section 0 now).
        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
        $existingsections = array_column($sections, null, 'section');

        foreach ($sectionsinfo as $sectioninfo) {
            $sectionnumber = (int)$sectioninfo['section'];
            $sectionname = $sectioninfo['name'] ?? '';

            if (isset($existingsections[$sectionnumber])) {
                // Update existing section name.
                if (!empty($sectionname) && $existingsections[$sectionnumber]->name !== $sectionname) {
                    $DB->update_record('course_sections', [
                        'id' => $existingsections[$sectionnumber]->id,
                        'name' => $sectionname,
                    ]);
                }
            } else {
                // Create new section.
                $sectiondata = new \stdClass();
                $sectiondata->course = $courseid;
                $sectiondata->section = $sectionnumber;
                $sectiondata->name = $sectionname;
                $sectiondata->summary = '';
                $sectiondata->summaryformat = FORMAT_HTML;
                $sectiondata->sequence = '';
                $sectiondata->visible = 1;
                $sectiondata->availability = null;
                $sectiondata->timemodified = time();

                $DB->insert_record('course_sections', $sectiondata);
            }
        }

        // Update course format options if needed.
        $maxsection = max(array_column($sectionsinfo, 'section'));
        if ($maxsection > 0) {
            // Update numsections for formats that support it.
            $formatoptions = $courseformat->get_format_options();
            if (isset($formatoptions['numsections'])) {
                $courseformat->update_course_format_options(['numsections' => $maxsection]);
            }
        }

        // Rebuild course cache.
        rebuild_course_cache($courseid, true);
    }

    /**
     * Index the subsections declared by the AI, keyed by their id.
     *
     * Each entry tracks the delegated section number once materialized, so
     * every nested activity after the first reuses the same subsection.
     *
     * @param array $subsectionsinfo subsections_info from the API result.
     * @return array<string,array{name:string,description:string,parentsection:int,delegatedsectionnum:?int}>
     */
    private static function index_declared_subsections(array $subsectionsinfo): array {
        $subsections = [];
        foreach ($subsectionsinfo as $info) {
            $id = (string)($info['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $subsections[$id] = [
                'name' => trim((string)($info['name'] ?? '')),
                'description' => trim((string)($info['description'] ?? '')),
                'parentsection' => (int)($info['parent_section'] ?? 0),
                'delegatedsectionnum' => null,
            ];
        }
        return $subsections;
    }

    /**
     * Whether subsections can be materialized in this course.
     *
     * Requires the subsection activity module to be enabled and the course
     * format to support delegated section components (topics/weeks in 4.5).
     *
     * @param \stdClass $course Course record.
     * @return bool
     */
    private static function can_materialize_subsections(\stdClass $course): bool {
        $enabledmods = \core_plugin_manager::instance()->get_enabled_plugins('mod');
        if (!array_key_exists('subsection', $enabledmods)) {
            return false;
        }
        return course_get_format($course)->supports_components();
    }

    /**
     * Create the mod_subsection instance for one declared subsection.
     *
     * The subsection module lands at the current end of the parent section's
     * sequence — calling this when its first activity appears preserves the
     * AI's presentation order. Returns the delegated section number where the
     * subsection's activities must be created.
     *
     * @param \stdClass $course Course record.
     * @param array $subsection Entry from index_declared_subsections().
     * @return int Delegated section number.
     */
    private static function materialize_subsection(\stdClass $course, array $subsection): int {
        global $DB;

        $resultinfo = [
            'resource_type' => 'subsection',
            'parameters' => [
                'modulename' => 'subsection',
                'name' => $subsection['name'] !== '' ? $subsection['name'] : get_string('pluginname', 'mod_subsection'),
                'visible' => 1,
                'visibleoncoursepage' => 1,
                'groupmode' => 0,
                'groupingid' => 0,
                'completion' => 0,
                'completiongradeitemnumber' => '',
                'completionview' => 0,
                'completionexpected' => 0,
                'completionpassgrade' => 0,
                'mod_settings' => [],
            ],
        ];

        $newcm = create_mod_service::create_from_ai_result($resultinfo, $course, $subsection['parentsection']);

        $delegated = $DB->get_record('course_sections', [
            'course' => $course->id,
            'component' => 'mod_subsection',
            'itemid' => (int)$newcm->instance,
        ], '*', MUST_EXIST);

        if ($subsection['description'] !== '') {
            $DB->update_record('course_sections', (object)[
                'id' => $delegated->id,
                'summary' => clean_param(trim((string)($subsection['description'] ?? '')), PARAM_CLEANHTML),
                'summaryformat' => FORMAT_HTML,
            ]);
        }

        return (int)$delegated->section;
    }

    /**
     * Materialize subsections that no activity referenced during creation.
     *
     * @param int $courseid Course ID.
     * @param array $subsections Index from index_declared_subsections(), possibly mutated.
     * @param array $activityerrors Error accumulator (by reference semantics via return not needed; appended).
     * @return void
     */
    private static function materialize_remaining_subsections(int $courseid, array $subsections, array &$activityerrors): void {
        $pending = array_filter($subsections, static function (array $subsection): bool {
            return $subsection['delegatedsectionnum'] === null;
        });
        if (empty($pending)) {
            return;
        }

        $course = get_course($courseid);
        if (!self::can_materialize_subsections($course)) {
            return;
        }

        foreach ($pending as $subsection) {
            try {
                self::materialize_subsection($course, $subsection);
            } catch (\Throwable $e) {
                $activityerrors[] = [
                    'resource_type' => 'subsection',
                    'section' => (int)$subsection['parentsection'],
                    'message' => get_string('error_activity_creation_failed', 'local_coursegen'),
                    'title' => (string)$subsection['name'],
                ];
                debugging('local_coursegen: empty subsection creation skipped. ' . $e->getMessage());
            }
        }

        rebuild_course_cache($courseid, true);
    }

    /**
     * Process generated activities from API response.
     *
     * Activities carrying a top-level subsection_id are created inside the
     * delegated section of the matching declared subsection; the subsection
     * module itself is materialized lazily when its first activity appears,
     * which keeps the AI's presentation order inside the parent section.
     * When subsections cannot be materialized (module disabled or format
     * without component support) nested activities flatten into their parent
     * section, in the same order.
     *
     * @param int $courseid Course ID.
     * @param array $activities Generated activities from API.
     * @param array $subsections Declared subsections index, mutated as they materialize.
     * @return array Activity creation errors.
     */
    private static function process_generated_activities(int $courseid, array $activities, array &$subsections = []): array {
        global $CFG;

        require_once($CFG->dirroot . '/course/modlib.php');

        $course = get_course($courseid);
        $errors = [];
        $subsectionsavailable = !empty($subsections) && self::can_materialize_subsections($course);
        if (!empty($subsections) && !$subsectionsavailable) {
            debugging('local_coursegen: subsections in result but mod_subsection unavailable; flattening into parent sections.');
        }

        foreach ($activities as $activity) {
            $sectionnum = 0;
            if (isset($activity['parameters']) && isset($activity['parameters']['section'])) {
                $sectionnum = $activity['parameters']['section'];
            }

            $subsectionid = (string)($activity['subsection_id'] ?? '');
            if ($subsectionid !== '' && $subsectionsavailable && isset($subsections[$subsectionid])) {
                try {
                    if ($subsections[$subsectionid]['delegatedsectionnum'] === null) {
                        $subsections[$subsectionid]['delegatedsectionnum'] =
                            self::materialize_subsection($course, $subsections[$subsectionid]);
                    }
                    $sectionnum = $subsections[$subsectionid]['delegatedsectionnum'];
                } catch (\Throwable $e) {
                    // Fall back to the parent section for this and later
                    // activities of the subsection (parameters.section is
                    // always the top-level parent).
                    $errors[] = [
                        'resource_type' => 'subsection',
                        'section' => (int)$sectionnum,
                        'message' => get_string('error_activity_creation_failed', 'local_coursegen'),
                        'title' => (string)$subsections[$subsectionid]['name'],
                    ];
                    unset($subsections[$subsectionid]);
                    debugging('local_coursegen: subsection creation failed, flattening its activities. ' . $e->getMessage());
                }
            }

            try {
                create_mod_service::create_from_ai_result($activity, $course, $sectionnum);
            } catch (\Throwable $e) {
                $resource = (string)($activity['resource_type'] ?? 'unknown');
                $title = (string)($activity['parameters']['name'] ?? $activity['parameters']['title'] ?? '');
                $errors[] = [
                    'resource_type' => $resource,
                    'section' => (int)$sectionnum,
                    'message' => get_string('error_activity_creation_failed', 'local_coursegen'),
                    'title' => $title,
                ];
                $context = [
                    'resource_type' => $resource,
                    'section' => (int)$sectionnum,
                    'title' => $title,
                    'error' => $e->getMessage(),
                ];
                debugging('local_coursegen: module creation skipped due to error. ' . json_encode($context));
                // Continue with next activity.
                continue;
            }
        }

        // Rebuild course cache after adding all activities.
        rebuild_course_cache($courseid, true);

        return $errors;
    }

    /**
     * Repair the course structure with core's integrity check and make sure modinfo
     * resolves every listed module.
     *
     * course_integrity_check() in full-check mode removes module ids that do not exist
     * from the section sequences, lists modules missing from every sequence in the
     * section their course_modules row points to, drops duplicates and re-points
     * course_modules.section to the section whose sequence lists the module. A check-only
     * pass afterwards and a fresh modinfo confirm the result; when a listed module still
     * cannot be resolved the creation fails.
     *
     * @param int $courseid Course ID.
     * @return int Number of repairs made.
     * @throws moodle_exception When the structure is still inconsistent after the repair.
     */
    private static function ensure_course_structure_consistent(int $courseid): int {
        // Full check ($fullcheck = true): repairs the sequences and course_modules.section in the DB.
        $repairs = course_integrity_check($courseid, null, null, true);
        $repairs = is_array($repairs) ? count($repairs) : 0;

        rebuild_course_cache($courseid, true);
        get_fast_modinfo($courseid, 0, true);
        $modinfo = get_fast_modinfo($courseid);

        // Full check in check-only mode ($checkonly = true): reports what is still wrong, writes nothing.
        $remaining = course_integrity_check($courseid, null, null, true, true);
        if (!empty($remaining) || !self::modinfo_resolves_sequences($modinfo)) {
            throw new moodle_exception('error_course_structure_inconsistent', 'local_coursegen');
        }

        return $repairs;
    }

    /**
     * Whether every module id listed in the section sequences is a module of the modinfo.
     *
     * A module whose type was disabled meanwhile stays in the sequence but is not part
     * of the modinfo, so the course page could not render it.
     *
     * @param \course_modinfo $modinfo Fresh modinfo of the course.
     * @return bool
     */
    private static function modinfo_resolves_sequences(\course_modinfo $modinfo): bool {
        global $DB;

        $cms = $modinfo->get_cms();
        $sections = $DB->get_records('course_sections', ['course' => $modinfo->get_course_id()], '', 'id,sequence');

        foreach ($sections as $section) {
            $sequence = trim((string)($section->sequence ?? ''));
            if ($sequence === '') {
                continue;
            }
            foreach (explode(',', $sequence) as $cmid) {
                if (!isset($cms[(int)$cmid])) {
                    return false;
                }
            }
        }

        return true;
    }
}
