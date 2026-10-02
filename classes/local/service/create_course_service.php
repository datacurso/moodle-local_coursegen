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
     * @return array Result of the course content application. On success it also
     *     carries 'generatedcms' (payload cmid, negative for a virtual instance => created cmid), which is internal
     *     and must not be returned through a web service.
     */
    public static function create_course(course_session $session, array $resultdata, array $overrides = []): array {
        global $CFG;

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

            // Process sections if provided in the response.
            if (!empty($resultdata['sections_info'])) {
                course_sections_builder::process($course->id, $resultdata['sections_info']);
            }

            // Index declared subsections (Moodle 4.5 delegated sections) so the
            // activity loop can materialize each one lazily, in presentation order.
            $subsections = generated_activities_builder::index_declared_subsections($resultdata['subsections_info'] ?? []);

            // In template mode the payload's rich text may reference files of
            // the template's base course; only those may be copied over.
            $sourcecourseid = self::template_course_id_of($session);

            // Process generated activities if provided in the response.
            $activityerrors = [];
            $generatedcms = [];
            if (!empty($resultdata['generated_activities'])) {
                $activityerrors = generated_activities_builder::build(
                    $course->id,
                    $resultdata['generated_activities'],
                    $subsections,
                    $generatedcms,
                    $sourcecourseid
                );
            }

            // Subsections declared without activities materialize at the end of
            // their parent section.
            generated_activities_builder::materialize_remaining_subsections($course->id, $subsections, $activityerrors);

            // Ensure section sequences only contain valid course module ids.
            $removedreferences = course_structure_repair::repair_course_section_sequences($course->id);
            course_structure_repair::stabilize_course_structure_cache($course->id);

            $remainingorphans = course_structure_repair::count_orphaned_course_module_references($course->id);
            if ($remainingorphans > 0) {
                $removedreferences += course_structure_repair::repair_course_section_sequences($course->id);
                course_structure_repair::stabilize_course_structure_cache($course->id);
                $remainingorphans = course_structure_repair::count_orphaned_course_module_references($course->id);
            }

            $missingmodinfocms = course_structure_repair::count_unresolved_modinfo_sequence_references($course->id);
            if ($missingmodinfocms > 0) {
                $removedreferences += course_structure_repair::repair_course_section_sequences($course->id);
                course_structure_repair::stabilize_course_structure_cache($course->id);
                $missingmodinfocms = course_structure_repair::count_unresolved_modinfo_sequence_references($course->id);
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
                $message .= ' Some activities were skipped due to creation errors.';
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
}
