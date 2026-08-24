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

use core_course_category;
use local_coursegen\local\api_client_factory;
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

        // The effective category (override or AI/default) must be one where
        // the user can create courses; a category the user cannot create in
        // is rejected before any creation work happens.
        $effectivecategoryid = self::resolve_effective_category($resultdata, $overrides);
        require_capability('moodle/course:create', \context_coursecat::instance($effectivecategoryid));

        try {
            // This request may take a long time depending on the complexity of the prompt that the AI has to resolve.
            \core_php_time_limit::raise();
            raise_memory_limit(MEMORY_EXTRA);
            // Release the session so other tabs in the same session are not blocked.
            \core\session\manager::write_close();

            require_once($CFG->dirroot . '/course/lib.php');

            // Build course data entirely from the API response.
            $coursedata = self::build_course_data_from_api($resultdata);

            // Apply user overrides (from the review modal) on top of AI-generated data.
            // These take precedence over the API response values.
            if (!empty($overrides['fullname'])) {
                $coursedata->fullname = (string)\core_text::substr($overrides['fullname'], 0, 255);
            }
            if (!empty($overrides['shortname'])) {
                $coursedata->shortname = (string)\core_text::substr(trim($overrides['shortname']), 0, 100);
            }
            if (!empty($overrides['category'])) {
                $coursedata->category = (int)$overrides['category'];
            }

            // The wizard language becomes the course language when its pack is
            // installed on the site; otherwise the site default applies.
            $courselang = self::resolve_course_lang($session);
            if ($courselang !== '') {
                $coursedata->lang = $courselang;
            }

            $coursedata = self::ensure_unique_course_fields($coursedata);

            // Create the Moodle course from stored form data.
            $course = create_course($coursedata);

            // Persist course id in the session record and mark as creating (2).
            $sessionid = (int)$session->get('id');
            $sessionpersistent = new course_session($sessionid);
            $sessionpersistent->set('courseid', $course->id);
            $sessionpersistent->set('timemodified', time());
            $sessionpersistent->update();
            course_session_service::update_status($sessionid, course_session::STATUS_CREATING);

            // Attach the AI-generated cover image when the result carries one.
            self::apply_course_image($course->id, $resultdata['course_image'] ?? null);

            // Process sections if provided in the response.
            if (!empty($resultdata['sections_info'])) {
                self::process_course_sections($course->id, $resultdata['sections_info']);
            }

            // Index declared subsections (Moodle 4.5 delegated sections) so the
            // activity loop can materialize each one lazily, in presentation order.
            $subsections = self::index_declared_subsections($resultdata['subsections_info'] ?? []);

            // Process generated activities if provided in the response.
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

            // Ensure section sequences only contain valid course module ids.
            $removedreferences = self::repair_course_section_sequences($course->id);
            self::stabilize_course_structure_cache($course->id);

            $remainingorphans = self::count_orphaned_course_module_references($course->id);
            if ($remainingorphans > 0) {
                $removedreferences += self::repair_course_section_sequences($course->id);
                self::stabilize_course_structure_cache($course->id);
                $remainingorphans = self::count_orphaned_course_module_references($course->id);
            }

            $missingmodinfocms = static::count_unresolved_modinfo_sequence_references($course->id);
            if ($missingmodinfocms > 0) {
                $removedreferences += self::repair_course_section_sequences($course->id);
                self::stabilize_course_structure_cache($course->id);
                $missingmodinfocms = static::count_unresolved_modinfo_sequence_references($course->id);
            }

            $structuralwarning = false;
            if ($remainingorphans > 0 || $missingmodinfocms > 0) {
                // The course already exists: failing the session here would
                // hide a half-built course behind a failed status, so it is
                // reported as created with a structural warning instead.
                $structuralwarning = true;
                debugging(
                    'local_coursegen: course structure is inconsistent after module creation. Course '
                    . $course->id . ', orphans: ' . $remainingorphans . ', unresolved: ' . $missingmodinfocms
                );
            }

            // Update session status to created.
            course_session_service::update_status($sessionid, course_session::STATUS_CREATED);

            if (!empty($activityerrors)) {
                debugging(
                    'local_coursegen: created course with module errors. Session ' . $sessionid
                    . '. Errors: ' . json_encode($activityerrors, JSON_UNESCAPED_UNICODE)
                );
            }

            if ($removedreferences > 0) {
                debugging(
                    'local_coursegen: removed orphaned course module references while creating course '
                    . $course->id . '. Removed: ' . $removedreferences
                );
            }

            // Return success response.
            $message = get_string('coursecreated', 'local_coursegen');
            if (!empty($activityerrors)) {
                $message .= ' ' . get_string('create_course_partial_warning', 'local_coursegen');
            }
            if ($structuralwarning) {
                $message .= ' ' . get_string('create_course_structure_warning', 'local_coursegen');
            }

            return [
                'success' => true,
                'courseid' => $course->id,
                'shortname' => $course->shortname,
                'fullname' => $course->fullname,
                'message' => $message,
                'courseurl' => course_get_url($course->id)->out(),
                'partial' => !empty($activityerrors) || $structuralwarning,
                'haswarnings' => !empty($activityerrors) || $structuralwarning,
                'warningscount' => count($activityerrors),
                'activityerrors' => $activityerrors,
            ];
        } catch (\Throwable $e) {
            // Update session status to failed if session exists.
            course_session_service::update_status((int)$session->get('id'), course_session::STATUS_FAILED);

            return [
                'success' => false,
                'courseid' => 0,
                'shortname' => '',
                'fullname' => '',
                'message' => $e->getMessage(),
                'partial' => false,
                'haswarnings' => false,
                'warningscount' => 0,
            ];
        }
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

        // The AI course description becomes the course summary when present.
        $description = trim((string)($config['description'] ?? ''));
        if ($description !== '') {
            $coursedata->summary = clean_param($description, PARAM_CLEANHTML);
            $coursedata->summaryformat = FORMAT_HTML;
        }

        return $coursedata;
    }

    /**
     * Resolve the effective category id the course will be created in.
     *
     * The user override wins over the AI-provided category; without either the
     * site default category applies (which keeps working for admins).
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
        $categoryid = is_array($config) ? (int)($config['category'] ?? 0) : 0;
        if ($categoryid > 0) {
            return $categoryid;
        }

        $defaultcategory = core_course_category::get_default();
        return $defaultcategory ? (int)$defaultcategory->id : 0;
    }

    /**
     * Resolve the course language from the wizard session data.
     *
     * The stored language is used only when its language pack is installed on
     * the site; otherwise an empty string keeps the site default.
     *
     * @param course_session $session Planning session persistent.
     * @return string Language code or empty string for the site default.
     */
    private static function resolve_course_lang(course_session $session): string {
        $sessiondata = json_decode((string)$session->get('coursedata'), true);
        $lang = is_array($sessiondata) ? trim((string)($sessiondata['local_coursegen_lang'] ?? '')) : '';
        if ($lang === '') {
            return '';
        }

        $translations = get_string_manager()->get_list_of_translations();
        return isset($translations[$lang]) ? $lang : '';
    }

    /**
     * Download and attach the AI-generated cover image as the course overview file.
     *
     * The image is decorative, so any missing data or download failure is
     * logged and skipped without failing the course creation.
     *
     * @param int $courseid Course ID.
     * @param array|null $courseimage course_image payload ({file_path, file_name}) or null.
     * @return void
     */
    private static function apply_course_image(int $courseid, ?array $courseimage): void {
        global $CFG;

        if (empty($courseimage) || !is_array($courseimage)) {
            return;
        }

        $filepath = trim((string)($courseimage['file_path'] ?? ''));
        $filename = clean_param(basename((string)($courseimage['file_name'] ?? '')), PARAM_FILE);
        if ($filepath === '' || $filename === '') {
            return;
        }

        try {
            require_once($CFG->libdir . '/filelib.php');

            $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
            $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;

            $client = api_client_factory::ai_course_api($baseurl, $baseurleu);
            $file = $client->download_file('/files/download?path=' . rawurlencode($filepath), $filename);

            file_save_draft_area_files(
                $file->get_itemid(),
                \context_course::instance($courseid)->id,
                'course',
                'overviewfiles',
                0,
                ['subdirs' => 0, 'maxfiles' => 1]
            );
        } catch (\Throwable $e) {
            // Never fail the course over its cover image.
            debugging('local_coursegen: course cover image could not be applied. ' . $e->getMessage());
        }
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
            // Older service results carry no description: tolerate its absence.
            $sectionsummary = clean_param(trim((string)($sectioninfo['description'] ?? '')), PARAM_CLEANHTML);

            if (isset($existingsections[$sectionnumber])) {
                // Update existing section name and summary.
                $update = ['id' => $existingsections[$sectionnumber]->id];
                if (!empty($sectionname) && $existingsections[$sectionnumber]->name !== $sectionname) {
                    $update['name'] = $sectionname;
                }
                if ($sectionsummary !== '' && $existingsections[$sectionnumber]->summary !== $sectionsummary) {
                    $update['summary'] = $sectionsummary;
                    $update['summaryformat'] = FORMAT_HTML;
                }
                if (count($update) > 1) {
                    $DB->update_record('course_sections', $update);
                }
            } else {
                // Create new section.
                $sectiondata = new \stdClass();
                $sectiondata->course = $courseid;
                $sectiondata->section = $sectionnumber;
                $sectiondata->name = $sectionname;
                $sectiondata->summary = $sectionsummary;
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
                    'message' => $e->getMessage(),
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
                        'message' => $e->getMessage(),
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
                    'message' => $e->getMessage(),
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
     * Remove invalid course module ids from all section sequences.
     *
     * @param int $courseid Course ID.
     * @return int Number of removed references.
     */
    private static function repair_course_section_sequences(int $courseid): int {
        global $DB;

        $validcmids = self::get_valid_course_module_ids($courseid);
        $sections = $DB->get_records('course_sections', ['course' => $courseid]);
        $removed = 0;

        foreach ($sections as $section) {
            $rawsequence = trim((string)($section->sequence ?? ''));
            if ($rawsequence === '') {
                continue;
            }

            $sequenceids = self::parse_sequence_ids($rawsequence);
            if (empty($sequenceids)) {
                if ($rawsequence !== '') {
                    $DB->set_field('course_sections', 'sequence', '', ['id' => $section->id]);
                    $removed++;
                }
                continue;
            }

            $filteredids = [];
            foreach ($sequenceids as $cmid) {
                if (isset($validcmids[$cmid])) {
                    $filteredids[] = $cmid;
                } else {
                    $removed++;
                }
            }

            $newsequence = implode(',', $filteredids);
            if ($newsequence !== $rawsequence) {
                $DB->set_field('course_sections', 'sequence', $newsequence, ['id' => $section->id]);
            }
        }

        if ($removed > 0) {
            rebuild_course_cache($courseid, true);
        }

        return $removed;
    }

    /**
     * Count orphaned course module references in section sequences.
     *
     * @param int $courseid Course ID.
     * @return int Number of orphaned references.
     */
    private static function count_orphaned_course_module_references(int $courseid): int {
        global $DB;

        $validcmids = self::get_valid_course_module_ids($courseid);
        $sections = $DB->get_records('course_sections', ['course' => $courseid], '', 'id,sequence');
        $orphans = 0;

        foreach ($sections as $section) {
            $sequenceids = self::parse_sequence_ids((string)($section->sequence ?? ''));
            foreach ($sequenceids as $cmid) {
                if (!isset($validcmids[$cmid])) {
                    $orphans++;
                }
            }
        }

        return $orphans;
    }

    /**
     * Parse a Moodle section sequence string into positive module ids.
     *
     * @param string $sequence Comma-separated module ids.
     * @return int[]
     */
    private static function parse_sequence_ids(string $sequence): array {
        if (trim($sequence) === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $sequence) as $rawid) {
            $cmid = (int)trim($rawid);
            if ($cmid > 0) {
                $ids[] = $cmid;
            }
        }

        return $ids;
    }

    /**
     * Get valid course module ids for a course as a lookup map.
     *
     * @param int $courseid Course ID.
     * @return array<int,bool>
     */
    private static function get_valid_course_module_ids(int $courseid): array {
        global $DB;

        $records = $DB->get_records('course_modules', ['course' => $courseid], '', 'id');
        $lookup = [];
        foreach ($records as $record) {
            $lookup[(int)$record->id] = true;
        }

        return $lookup;
    }

    /**
     * Stabilize cache state for course structure/navigation checks.
     *
     * @param int $courseid Course ID.
     * @return void
     */
    private static function stabilize_course_structure_cache(int $courseid): void {
        \course_modinfo::clear_instance_cache($courseid);
        rebuild_course_cache($courseid, true);
        rebuild_course_cache($courseid, false);
        \course_modinfo::clear_instance_cache($courseid);
    }

    /**
     * Count section sequence module ids that cannot be resolved by modinfo.
     *
     * Protected so PHPUnit can force the final consistency verification result
     * through a testable subclass (late static binding).
     *
     * @param int $courseid Course ID.
     * @return int Number of unresolved references.
     */
    protected static function count_unresolved_modinfo_sequence_references(int $courseid): int {
        global $DB;

        $course = get_course($courseid);
        $modinfo = get_fast_modinfo($course);
        $cms = $modinfo->get_cms();

        $sections = $DB->get_records('course_sections', ['course' => $courseid], '', 'id,sequence');
        $missing = 0;

        foreach ($sections as $section) {
            $sequenceids = self::parse_sequence_ids((string)($section->sequence ?? ''));
            foreach ($sequenceids as $cmid) {
                if (!isset($cms[$cmid])) {
                    $missing++;
                }
            }
        }

        return $missing;
    }
}
