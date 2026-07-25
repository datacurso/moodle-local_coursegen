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

use aiprovider_datacurso\httpclient\ai_course_api;
use local_coursegen\ai_course;
use local_coursegen\mod_manager;
use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use moodle_exception;
use local_coursegen\utils\text_editor_parameter_cleaner;


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * External API for creating courses with AI assistance.
 */
class create_course extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * Apply AI-generated course content to an existing course.
     *
     * @param int $courseid Course ID
     * @return array Result of the course content application
     * @throws moodle_exception
     */
    public static function execute($courseid) {
        global $CFG, $DB, $USER;

        try {
            $params = self::validate_parameters(self::execute_parameters(), [
                'courseid' => $courseid,
            ]);

            $courseid = $params['courseid'];

            // Validate course exists and user has permission.
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $coursecontext = \context_course::instance($course->id);
            self::validate_context($coursecontext);
            require_capability('moodle/course:manageactivities', $coursecontext);
            require_capability('local/coursegen:createcoursewithai', $coursecontext);

            if (!get_config('local_coursegen', 'enable_course_ai')) {
                throw new \moodle_exception('error_course_ai_disabled', 'local_coursegen');
            }

            // Validate that a session exists for this course and user.
            $session = $DB->get_record('local_coursegen_course_sessions', [
                'courseid' => $course->id,
                'userid' => $USER->id,
            ]);

            if (!$session) {
                return [
                    'success' => false,
                    'message' => get_string('error_no_session_found', 'local_coursegen', $course->id),
                    'courseid' => $course->id,
                    'shortname' => $course->shortname,
                    'fullname' => $course->fullname,
                ];
            }

            // This request may take a long time depending on the complexity of the prompt that the AI ​​has to resolve.
            \core_php_time_limit::raise();
            raise_memory_limit(MEMORY_EXTRA);
            // Release the session so other tabs in the same session are not blocked.
            \core\session\manager::write_close();

            $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
            $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;

            $client = new ai_course_api(null, $baseurl, $baseurleu);
            $result = $client->request('GET', '/course/result?session_id=' . urlencode($session->session_id));

            // Check if the plan is completed.
            if (empty($result['status']) || $result['status'] !== 'completed') {
                // Update session status to failed (4).
                ai_course::update_session_status($session->id, 4);

                return [
                    'success' => false,
                    'message' => 'Course plan is not completed yet',
                ];
            }

            // Extract result data.
            $resultdata = $result['result'] ?? [];

            // Process sections if provided in the response.
            if (!empty($resultdata['sections_info'])) {
                self::process_course_sections($course->id, $resultdata['sections_info']);
            }

            // Process generated activities if provided in the response.
            if (!empty($resultdata['generated_activities'])) {
                // Clean text editor parameters before processing.
                $cleanedactivities = text_editor_parameter_cleaner::clean_editor_parameters(
                    $resultdata['generated_activities']
                );
                self::process_generated_activities($course->id, $cleanedactivities);
            }

            // Update session status to created (3).
            ai_course::update_session_status($session->id, 3);

            // Return success response.
            return [
                'success' => true,
                'courseid' => $course->id,
                'shortname' => $course->shortname,
                'fullname' => $course->fullname,
                'message' => get_string('coursecreated', 'local_coursegen'),
                'courseurl' => course_get_url($course->id)->out(),
            ];
        } catch (\Exception $e) {
            // Update session status to failed (4) if session exists.
            if (isset($session) && $session) {
                ai_course::update_session_status($session->id, 4);
            }

            return [
                'success' => false,
                'courseid' => $courseid ?? 0,
                'shortname' => '',
                'fullname' => '',
                'message' => $e->getMessage(),
            ];
        }
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
        ]);
    }

    /**
     * Generate a unique course shortname.
     *
     * @return string Unique shortname
     */
    private static function generate_unique_shortname() {
        global $DB;

        $baseprefix = 'AI_COURSE_';

        // Get the highest existing number from AI_COURSE_ prefixed courses.
        $sql = "SELECT shortname FROM {course} WHERE shortname LIKE ? ORDER BY shortname DESC LIMIT 1";
        $lastcourse = $DB->get_field_sql($sql, [$baseprefix . '%']);

        $nextnumber = 1;
        if ($lastcourse) {
            // Extract the number from the shortname (e.g., AI_COURSE_5 -> 5).
            if (preg_match('/AI_COURSE_(\d+)/', $lastcourse, $matches)) {
                $nextnumber = intval($matches[1]) + 1;
            }
        }

        return $baseprefix . $nextnumber;
    }

    /**
     * Delete all course sections except section 0 and clear section 0 modules.
     *
     * @param int $courseid Course ID
     */
    private static function delete_course_sections($courseid) {
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
            'section DESC' // Delete from highest to lowest to avoid numbering issues.
        );

        foreach ($sections as $section) {
            // Use Moodle's core function to delete section safely.
            course_delete_section($course, $section->section);
        }
    }

    /**
     * Process course sections from API response.
     *
     * @param int $courseid Course ID
     * @param array $sectionsinfo Sections information from API
     */
    private static function process_course_sections($courseid, $sectionsinfo) {
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
     * @param int $courseid Course ID
     * @param array $activities Generated activities from API
     */
    private static function process_generated_activities($courseid, $activities) {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/modlib.php');

        $course = get_course($courseid);

        foreach ($activities as $activity) {
            $sectionnum = 0;
            if (isset($activity['parameters']) && isset($activity['parameters']['section'])) {
                $sectionnum = $activity['parameters']['section'];
            }

            $resultinfo = [
                'result' => $activity,
            ];

            try {
                mod_manager::create_from_ai_result($resultinfo, $course, $sectionnum);
            } catch (\Exception $e) {
                debugging("Error creating module {$modname}: " . $e->getMessage());
                // Continue with next activity.
                continue;
            }
        }

        // Rebuild course cache after adding all activities.
        rebuild_course_cache($courseid, true);
    }
}
