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

/**
 * Service responsible for creating a course from an AI planning session.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_course_service {
    /**
     * Execute course creation from a stored planning session.
     *
     * @param course_session $session Planning session persistent.
     * @return array Result of the course content application.
     */
    public static function create_course(course_session $session): array {
        global $CFG;

        try {
            // This request may take a long time depending on the complexity of the prompt that the AI has to resolve.
            \core_php_time_limit::raise();
            raise_memory_limit(MEMORY_EXTRA);
            // Release the session so other tabs in the same session are not blocked.
            \core\session\manager::write_close();

            require_once($CFG->dirroot . '/course/lib.php');

            $apiservice = new ai_course_api_service();
            $result = $apiservice->get_course_result((string)$session->get('session_id'));

            // Extract result data.
            $resultdata = $result['result'] ?? [];

            // Build course data entirely from the API response.
            $coursedata = self::build_course_data_from_api($resultdata);

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
                self::process_course_sections($course->id, $resultdata['sections_info']);
            }

            // Process generated activities if provided in the response.
            $activityerrors = [];
            if (!empty($resultdata['generated_activities'])) {
                $activityerrors = self::process_generated_activities($course->id, $resultdata['generated_activities']);
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

        return $coursedata;
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
     * Process generated activities from API response.
     *
     * @param int $courseid Course ID.
     * @param array $activities Generated activities from API.
     * @return void
     */
    private static function process_generated_activities(int $courseid, array $activities): array {
        global $CFG;

        require_once($CFG->dirroot . '/course/modlib.php');

        $course = get_course($courseid);
        $errors = [];

        foreach ($activities as $activity) {
            $sectionnum = 0;
            if (isset($activity['parameters']) && isset($activity['parameters']['section'])) {
                $sectionnum = $activity['parameters']['section'];
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
