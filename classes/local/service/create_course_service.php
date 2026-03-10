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

use local_coursegen\local\models\course_session;
use local_coursegen\utils\text_editor_parameter_cleaner;

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
     * @param int $recordid Session record ID in local_coursegen_course_sessions.
     * @param int $userid User ID requesting the creation.
     * @return array Result of the course content application.
     */
    public static function execute(int $recordid, int $userid): array {
        global $CFG;

        try {
            $session = course_session_service::get_user_session($recordid, $userid);

            $coursedatajson = $session->get('coursedata');
            if (empty($coursedatajson)) {
                throw new \moodle_exception('error_no_coursedata_found', 'local_coursegen');
            }

            $coursedata = json_decode($coursedatajson);
            if (!is_object($coursedata)) {
                throw new \moodle_exception('error_invalid_coursedata', 'local_coursegen');
            }

            if (empty($coursedata->category)) {
                throw new \moodle_exception('error_missing_category', 'local_coursegen');
            }

            // This request may take a long time depending on the complexity of the prompt that the AI has to resolve.
            \core_php_time_limit::raise();
            raise_memory_limit(MEMORY_EXTRA);
            // Release the session so other tabs in the same session are not blocked.
            \core\session\manager::write_close();

            require_once($CFG->dirroot . '/course/lib.php');

            // Create the Moodle course from stored form data.
            $course = create_course($coursedata);

            // Persist course id in the session record and mark as creating (2).
            $sessionid = (int)$session->get('id');
            $sessionpersistent = new course_session($sessionid);
            $sessionpersistent->set('courseid', $course->id);
            $sessionpersistent->set('timemodified', time());
            $sessionpersistent->update();
            course_session_service::update_status($sessionid, course_session::STATUS_CREATING);

            $apiservice = new ai_course_api_service();
            $result = $apiservice->get_course_result((string)$session->get('session_id'));

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

            // Update session status to created.
            course_session_service::update_status($sessionid, course_session::STATUS_CREATED);

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
            // Update session status to failed if session exists.
            if (isset($session) && $session instanceof course_session) {
                course_session_service::update_status((int)$session->get('id'), course_session::STATUS_FAILED);
            }

            return [
                'success' => false,
                'courseid' => 0,
                'shortname' => '',
                'fullname' => '',
                'message' => $e->getMessage(),
            ];
        }
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
    private static function process_generated_activities(int $courseid, array $activities): void {
        global $CFG;

        require_once($CFG->dirroot . '/course/modlib.php');

        $course = get_course($courseid);

        foreach ($activities as $activity) {
            $sectionnum = 0;
            if (isset($activity['parameters']) && isset($activity['parameters']['section'])) {
                $sectionnum = $activity['parameters']['section'];
            }

            try {
                create_mod_service::create_from_ai_result($activity, $course, $sectionnum);
            } catch (\Exception $e) {
                debugging('Error creating module from AI result: ' . $e->getMessage());
                // Continue with next activity.
                continue;
            }
        }

        // Rebuild course cache after adding all activities.
        rebuild_course_cache($courseid, true);
    }
}
