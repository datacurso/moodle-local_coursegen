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
use local_coursegen\event\generation_failed;
use local_coursegen\event\generation_result_applied;
use local_coursegen\local\models\course_session;
use local_coursegen\local\models\template;

/**
 * Service responsible for creating a course from an AI planning session.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_course_service {
    /** @var callable|null Hears of each activity created: (string $step, int $done, int $total). */
    private static $progress = null;

    /**
     * Set who hears of the steps of the creation, or null for nobody.
     *
     * @param callable|null $callback Called with (string $step, int $done, int $total).
     */
    public static function on_progress(?callable $callback): void {
        self::$progress = $callback;
    }

    /**
     * Tell the one who listens that a step was done.
     *
     * @param string $step
     * @param int $done
     * @param int $total
     */
    private static function report_progress(string $step, int $done, int $total): void {
        if (self::$progress !== null) {
            (self::$progress)($step, $done, $total);
        }
    }

    /**
     * Execute course creation from AI-generated result data.
     *
     * The result data must already be fetched from the API; this method only
     * processes it. This separation makes the method testable without requiring
     * a live API connection.
     *
     * Optional overrides allow the user to modify course identity fields
     * (fullname, shortname, category, startdate) before creation, as set via the review modal.
     *
     * @param course_session $session Planning session persistent.
     * @param array $resultdata Result data from the Datacurso API (course_configuration, sections, activities).
     * @param array $overrides Optional user overrides for course fields.
     *     Supported keys: fullname (string), shortname (string), category (int), startdate (int timestamp).
     * @return array Result of the course content application. On success it also
     *     carries 'generatedcms' (activity key, see generated_activity_key, => created cmid), which is internal
     *     and must not be returned through a web service.
     */
    public static function create_course(course_session $session, array $resultdata, array $overrides = []): array {
        global $CFG;

        // Resolve the effective category (user override → usable AI value → usable
        // site default → first usable category) and require the full creation set in
        // that exact category before any creation work happens (ticket #9539). Thrown
        // (not returned) so callers surface it as a proper permission error naming the
        // missing capability.
        $effectivecategoryid = self::resolve_effective_category($resultdata, $overrides);
        creation_permissions::require_for_category($effectivecategoryid);

        try {
            // This request may take a long time depending on the complexity of the prompt that the AI has to resolve.
            \core_php_time_limit::raise();
            raise_memory_limit(MEMORY_EXTRA);
            // Release the session so other tabs in the same session are not blocked.
            \core\session\manager::write_close();

            require_once($CFG->dirroot . '/course/lib.php');

            // In template mode the new course is the template's course with other content in it,
            // and the payload's rich text may reference files of that base course; only those may
            // be copied over.
            $sourcecourseid = self::template_course_id_of($session);

            // Keep section 0 as the course's general section. A template result keeps the section
            // numbers of its template, so only a free generation is renumbered.
            if ($sourcecourseid === null) {
                $resultdata = self::move_plan_out_of_general_section($resultdata);
            }

            // Build course data entirely from the API response.
            $coursedata = self::build_course_data_from_api($resultdata);
            $courseconfiguration = $resultdata['course_configuration'] ?? [];
            // The language chosen in the assistant; a template course then keeps its own settings, language included.
            $lang = self::resolve_course_language($session);
            if ($lang !== '') {
                $coursedata->lang = $lang;
            }
            if ($sourcecourseid !== null) {
                template_course_settings::apply_to_new_course($coursedata, $courseconfiguration);
            }

            // Apply user overrides (from the review modal) on top of AI-generated data.
            // These take precedence over the API response values.
            if (!empty($overrides['fullname'])) {
                $coursedata->fullname = (string)\core_text::substr($overrides['fullname'], 0, 255);
            }
            if (!empty($overrides['shortname'])) {
                $coursedata->shortname = (string)\core_text::substr(trim($overrides['shortname']), 0, 100);
            }
            // The course goes where the permissions were checked, never to a category
            // proposed by the AI service that this site does not have.
            $coursedata->category = $effectivecategoryid;
            if (!empty($overrides['startdate'])) {
                $coursedata->startdate = (int)$overrides['startdate'];
            }

            $coursedata = self::ensure_unique_course_fields($coursedata);

            // Create the Moodle course from stored form data.
            $course = create_course($coursedata);
            if ($sourcecourseid !== null) {
                template_course_settings::apply_format_options($course, $sourcecourseid);
                template_course_blocks::copy($course, $sourcecourseid);
                template_course_custom_fields::copy($course, $sourcecourseid);
            }

            generation_result_applied::create([
                'context' => \context_course::instance($course->id),
                'other' => ['courseid' => (int)$course->id],
            ])->trigger();

            // Persist course id in the session record and mark as creating (2).
            $sessionid = (int)$session->get('id');
            $sessionpersistent = new course_session($sessionid);
            $sessionpersistent->set('courseid', $course->id);
            $sessionpersistent->set('timemodified', time());
            $sessionpersistent->update();
            course_session_service::update_status($sessionid, course_session::STATUS_CREATING);

            // Process sections if provided in the response.
            if (!empty($resultdata['sections_info'])) {
                $sectionsinfo = $resultdata['sections_info'];
                if ($sourcecourseid !== null) {
                    $sectionsinfo = self::with_stored_structure($session, $sectionsinfo);
                }
                self::process_course_sections($course->id, $sectionsinfo);
                if ($sourcecourseid !== null) {
                    template_section_look::apply(get_course($course->id), $sectionsinfo, $sourcecourseid);
                    // The names the AI gave are the last word on the sections' names, whatever the look touched.
                    template_section_names::apply((int) $course->id, $sectionsinfo);
                }
            }

            // Index declared subsections (Moodle 4.5 delegated sections) so the
            // activity loop can materialize each one lazily, in presentation order.
            $subsections = self::index_declared_subsections($resultdata['subsections_info'] ?? []);

            // Process generated activities if provided in the response.
            $activityerrors = [];
            $generatedcms = [];
            if (!empty($resultdata['generated_activities'])) {
                $activityerrors = self::process_generated_activities(
                    $course->id,
                    $resultdata['generated_activities'],
                    $subsections,
                    $generatedcms,
                    $sourcecourseid
                );
            }

            // Subsections declared without activities materialize at the end of
            // their parent section.
            self::materialize_remaining_subsections($course->id, $subsections, $activityerrors);

            // Hidden activities, completion rules and unit locks the guideline asked for.
            $generatedactivities = $resultdata['generated_activities'] ?? [];
            guideline_activity_settings::apply((int) $course->id, $generatedactivities, $generatedcms);
            guideline_unit_locks::apply((int) $course->id, $resultdata['sections_info'] ?? [], $generatedactivities, $generatedcms);

            // The cover the service made for a free course; a template course applies its own pictures.
            if ($sourcecourseid === null) {
                free_course_cover::apply((int) $course->id, $resultdata);
            }

            // Ensure section sequences only contain valid course module ids.
            $removedreferences = self::repair_course_section_sequences($course->id);
            self::stabilize_course_structure_cache($course->id);

            $remainingorphans = self::count_orphaned_course_module_references($course->id);
            if ($remainingorphans > 0) {
                $removedreferences += self::repair_course_section_sequences($course->id);
                self::stabilize_course_structure_cache($course->id);
                $remainingorphans = self::count_orphaned_course_module_references($course->id);
            }

            $missingmodinfocms = self::count_unresolved_modinfo_sequence_references($course->id);
            if ($missingmodinfocms > 0) {
                $removedreferences += self::repair_course_section_sequences($course->id);
                self::stabilize_course_structure_cache($course->id);
                $missingmodinfocms = self::count_unresolved_modinfo_sequence_references($course->id);
            }

            if ($remainingorphans > 0 || $missingmodinfocms > 0) {
                throw new \Exception('Course structure is inconsistent after module creation.');
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
                // Payload cmid => created course module id, for every
                // generated activity that was built. Internal: strip it
                // before returning through a web service.
                'generatedcms' => $generatedcms,
            ];
        } catch (\Throwable $e) {
            // Update session status to failed if session exists.
            course_session_service::update_status((int)$session->get('id'), course_session::STATUS_FAILED);

            // Keep the technical detail in developer debugging only: the client
            // receives a localized message without internal information.
            debugging('local_coursegen: course creation failed. ' . $e->getMessage());

            generation_failed::create([
                'context' => \context_system::instance(),
                'other' => ['reason' => get_class($e)],
            ])->trigger();

            return [
                'success' => false,
                'courseid' => 0,
                'shortname' => '',
                'fullname' => '',
                'message' => license_failure::message($e) ?? get_string('error_course_creation_failed', 'local_coursegen'),
                'partial' => false,
                'haswarnings' => false,
                'warningscount' => 0,
            ];
        }
    }

    /**
     * Resolve the category the course will effectively be created in.
     *
     * A category the user picked in the review panel is kept: it must still exist, and the
     * permission check that follows refuses it when the user cannot create courses there.
     * The category proposed by the AI service is only a suggestion, made without knowing the
     * categories of this site, so it is used only when it exists and the user holds the
     * creation set there. Otherwise the site default category, and then the first category
     * where the user holds the creation set, take its place.
     *
     * @param array $resultdata Result data from the Datacurso API.
     * @param array $overrides Optional user overrides for course fields.
     * @return int Category id.
     * @throws \moodle_exception When the picked category does not exist, or no category is usable.
     */
    public static function resolve_effective_category(array $resultdata, array $overrides): int {
        if (!empty($overrides['category'])) {
            $categoryid = (int)$overrides['category'];
            if (!self::category_exists($categoryid)) {
                throw new \moodle_exception('error_category_unavailable', 'local_coursegen');
            }
            return $categoryid;
        }

        $candidates = [];
        $config = $resultdata['course_configuration'] ?? null;
        if (is_array($config) && !empty($config['category'])) {
            $candidates[] = (int)$config['category'];
        }
        $defaultcategory = core_course_category::get_default();
        if ($defaultcategory) {
            $candidates[] = (int)$defaultcategory->id;
        }
        foreach ($candidates as $categoryid) {
            if (self::category_exists($categoryid) && creation_permissions::can_create_in($categoryid)) {
                return $categoryid;
            }
        }

        $firstallowed = creation_permissions::first_allowed_category();
        if ($firstallowed > 0) {
            return $firstallowed;
        }
        throw new \moodle_exception('error_category_unavailable', 'local_coursegen');
    }

    /**
     * Whether a course category exists on this site.
     *
     * @param int $categoryid Category id.
     * @return bool
     */
    private static function category_exists(int $categoryid): bool {
        global $DB;
        return $categoryid > 0 && $DB->record_exists('course_categories', ['id' => $categoryid]);
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
            $coursedata->enablecompletion = self::enablecompletion_for(null);
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
        $coursedata->enablecompletion = self::enablecompletion_for($config);

        $description = trim((string)($config['description'] ?? ''));
        if ($description !== '') {
            $coursedata->summary = self::description_to_html($description);
            $coursedata->summaryformat = FORMAT_HTML;
        }

        return $coursedata;
    }

    /**
     * Whether the new course tracks activity completion.
     *
     * create_course() inserts the record as given, so an unset value falls to
     * the DB column default (0), NOT to the site's course default the edit
     * form applies. With completion disabled on the course, add_moduleinfo()
     * silently ignores every completion setting of every generated activity
     * (completion_info::is_enabled() is false), so the value must be explicit.
     * The payload may name it; otherwise the site's default for new courses
     * applies, as it does when a course is created through the UI.
     *
     * @param array|null $config course_configuration from the payload.
     * @return int 0 or 1.
     */
    private static function enablecompletion_for(?array $config): int {
        global $CFG;

        if (empty($CFG->enablecompletion)) {
            return 0;
        }
        if (is_array($config) && isset($config['enablecompletion'])) {
            return (int) (bool) $config['enablecompletion'];
        }
        return (int) (bool) get_config('moodlecourse', 'enablecompletion');
    }

    /**
     * The base course of the template this session was started from, if any.
     *
     * Template-mode sessions store their template id in coursedata; a
     * free-form planning session has none.
     *
     * @param course_session $session
     * @return int|null Base course id, or null when not a template session.
     */
    public static function template_course_id_of(course_session $session): ?int {
        $templateid = self::template_id_of($session);
        if ($templateid <= 0) {
            return null;
        }
        $template = template::get_record(['id' => $templateid]);
        return $template ? (int) $template->get('courseid') : null;
    }

    /**
     * Which template this session was started from.
     *
     * @param course_session $session
     * @return int 0 when the session is not a template session.
     */
    public static function template_id_of(course_session $session): int {
        $data = json_decode((string) $session->get('coursedata'), true);
        return (int) ($data['templateid'] ?? 0);
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
        // Offer the category the course would be created in, so the review panel never
        // starts on a category this site does not have or the user cannot use.
        try {
            $category = self::resolve_effective_category($resultdata, []);
        } catch (\moodle_exception $e) {
            $category = 0;
        }
        return [
            'fullname' => $coursedata->fullname ?? '',
            'shortname' => $coursedata->shortname ?? '',
            'category' => $category,
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
     * The sections of the result, with the structure of the payload that was sent.
     *
     * A flexsections course made with another number of units than the
     * template's was resized before it was sent; the structure (parents and the
     * template section each one comes from) lives in the stored payload, not
     * in what the service answers.
     *
     * @param course_session $session
     * @param array $sectionsinfo The result's sections_info.
     * @return array
     */
    private static function with_stored_structure(course_session $session, array $sectionsinfo): array {
        $coursedata = json_decode((string) $session->get('coursedata'), true);
        $sent = $coursedata['payload']['sections_info'] ?? [];
        return template_unit_resizer::with_structure_of($sectionsinfo, $sent);
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
            $sectionname = template_section_names::clean_name((string)($sectioninfo['name'] ?? ''));
            $description = trim((string)($sectioninfo['description'] ?? ''));
            $summary = $description !== '' ? self::description_to_html($description) : '';

            if (isset($existingsections[$sectionnumber])) {
                // Update the existing section with the planned name and summary.
                $update = ['id' => $existingsections[$sectionnumber]->id];
                if (!empty($sectionname) && $existingsections[$sectionnumber]->name !== $sectionname) {
                    $update['name'] = $sectionname;
                }
                if ($summary !== '') {
                    $update['summary'] = $summary;
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
                $sectiondata->summary = $summary;
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
     * Shift a plan that starts at section 0 one position down.
     *
     * The service numbers the planned sections from 0, which would turn the first
     * unit into the course's general section and leave the course without one.
     * Sections, the top-level section of each activity and the parent of each
     * subsection move together, so the structure is kept.
     *
     * @param array $resultdata Result data from the Datacurso API.
     * @return array The same data, with section numbers starting at 1.
     */
    private static function move_plan_out_of_general_section(array $resultdata): array {
        $sections = $resultdata['sections_info'] ?? null;
        if (!is_array($sections) || $sections === []) {
            return $resultdata;
        }
        $numbers = array_map(fn($section) => (int)($section['section'] ?? 0), array_filter($sections, 'is_array'));
        if ($numbers === [] || min($numbers) !== 0) {
            return $resultdata;
        }

        foreach ($resultdata['sections_info'] as $key => $section) {
            if (is_array($section)) {
                $resultdata['sections_info'][$key]['section'] = (int)($section['section'] ?? 0) + 1;
            }
        }
        foreach ($resultdata['generated_activities'] ?? [] as $key => $activity) {
            if (is_array($activity)) {
                $current = (int)($activity['parameters']['section'] ?? 0);
                $resultdata['generated_activities'][$key]['parameters']['section'] = $current + 1;
            }
        }
        foreach ($resultdata['subsections_info'] ?? [] as $key => $subsection) {
            if (is_array($subsection)) {
                $resultdata['subsections_info'][$key]['parent_section'] = (int)($subsection['parent_section'] ?? 0) + 1;
            }
        }
        return $resultdata;
    }

    /**
     * Language chosen in the assistant, when its language pack is installed.
     *
     * @param course_session $session Planning session persistent.
     * @return string Language code, or '' to keep the site default.
     */
    private static function resolve_course_language(course_session $session): string {
        $coursedata = json_decode((string)$session->get('coursedata'), true);
        $lang = clean_param((string)($coursedata['local_coursegen_lang'] ?? ''), PARAM_LANG);
        if ($lang === '' || !get_string_manager()->translation_exists($lang, false)) {
            return '';
        }
        return $lang;
    }

    /**
     * Turn a plain-text description from the service into summary HTML.
     *
     * @param string $description Plain text, one paragraph per line.
     * @return string
     */
    private static function description_to_html(string $description): string {
        $paragraphs = preg_split('/\R+/u', $description, -1, PREG_SPLIT_NO_EMPTY);
        return implode('', array_map(fn($paragraph) => \html_writer::tag('p', s(trim($paragraph))), $paragraphs));
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
     * @param array $generatedcms Filled with activity key => created cmid for every activity that was
     *     created; an activity the AI wrote is known by its uid (see generated_activity_key).
     * @param int|null $sourcecourseid Course whose files the payload may reference (template base course).
     * @return array Activity creation errors.
     */
    private static function process_generated_activities(
        int $courseid,
        array $activities,
        array &$subsections = [],
        array &$generatedcms = [],
        ?int $sourcecourseid = null
    ): array {
        global $CFG;

        require_once($CFG->dirroot . '/course/modlib.php');

        $course = get_course($courseid);
        $errors = [];
        $subsectionsavailable = !empty($subsections) && self::can_materialize_subsections($course);
        if (!empty($subsections) && !$subsectionsavailable) {
            debugging('local_coursegen: subsections in result but mod_subsection unavailable; flattening into parent sections.');
        }

        $total = count($activities);
        $done = 0;
        foreach ($activities as $activity) {
            self::report_progress('activities', $done, $total);
            $done++;
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
                $newcm = create_mod_service::create_from_ai_result($activity, $course, $sectionnum, null, $sourcecourseid);
                $key = generated_activity_key::of($activity);
                if ($key !== '') {
                    $generatedcms[$key] = (int) $newcm->coursemodule;
                }
            } catch (\Throwable $e) {
                $resource = (string)($activity['resource_type'] ?? 'unknown');
                $title = (string)($activity['parameters']['name'] ?? $activity['parameters']['title'] ?? '');
                $errors[] = [
                    'resource_type' => $resource,
                    'section' => (int)$sectionnum,
                    'message' => get_string('error_activity_creation_failed', 'local_coursegen'),
                    'title' => $title,
                    'cause' => self::failure_cause($e),
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
     * Why an activity could not be created, in one word the caller can act on.
     *
     * A file the activity is built from (an H5P package, a document) is fetched from the service when the course is
     * created; when the service no longer has it the fetch fails, and generating again is what makes it anew.
     *
     * @param \Throwable $error
     * @return string "download" when a file of the service could not be fetched, "other" for anything else.
     */
    private static function failure_cause(\Throwable $error): string {
        $fetch = $error instanceof \file_exception && str_contains((string) ($error->a ?? ''), 'Cannot fetch file from URL');
        return $fetch ? 'download' : 'other';
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
     * @param int $courseid Course ID.
     * @return int Number of unresolved references.
     */
    private static function count_unresolved_modinfo_sequence_references(int $courseid): int {
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
