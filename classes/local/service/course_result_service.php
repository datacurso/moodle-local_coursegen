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

use local_coursegen\mod_manager;
use local_coursegen\utils\text_editor_parameter_cleaner;

/**
 * Applies AI course results to an existing Moodle course.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_result_service {
    /**
     * Apply a completed AI result payload into a course.
     *
     * @param int $courseid
     * @param array $resultdata
     * @return void
     */
    public static function apply_result(int $courseid, array $resultdata): void {
        if (!empty($resultdata['sections_info'])) {
            self::process_course_sections($courseid, (array)$resultdata['sections_info']);
        }

        if (!empty($resultdata['generated_activities'])) {
            $cleanedactivities = text_editor_parameter_cleaner::clean_editor_parameters(
                (array)$resultdata['generated_activities']
            );
            self::process_generated_activities($courseid, $cleanedactivities);
        }
    }

    /**
     * Delete all course sections except section 0 and clear section 0 modules.
     *
     * @param int $courseid
     * @return void
     */
    private static function delete_course_sections(int $courseid): void {
        global $DB;

        $course = get_course($courseid);
        $modinfo = get_fast_modinfo($course);
        $section0 = $modinfo->get_section_info(0);

        if ($section0 && !empty($section0->sequence)) {
            $cms = $modinfo->get_cms();
            foreach ($cms as $cm) {
                if ((int)$cm->sectionnum === 0) {
                    course_delete_module((int)$cm->id);
                }
            }
        }

        $sections = $DB->get_records_select(
            'course_sections',
            'course = ? AND section > 0',
            [$courseid],
            'section DESC'
        );

        foreach ($sections as $section) {
            course_delete_section($course, (int)$section->section);
        }
    }

    /**
     * Process course sections from API response.
     *
     * @param int $courseid
     * @param array $sectionsinfo
     * @return void
     */
    private static function process_course_sections(int $courseid, array $sectionsinfo): void {
        global $DB;

        $course = get_course($courseid);
        $courseformat = course_get_format($course);

        self::delete_course_sections($courseid);

        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
        $existingsections = array_column($sections, null, 'section');

        foreach ($sectionsinfo as $sectioninfo) {
            $sectionnumber = (int)($sectioninfo['section'] ?? 0);
            $sectionname = trim((string)($sectioninfo['name'] ?? ''));
            if ($sectionnumber < 0) {
                continue;
            }

            if (isset($existingsections[$sectionnumber])) {
                if ($sectionname !== '' && $existingsections[$sectionnumber]->name !== $sectionname) {
                    $DB->update_record('course_sections', [
                        'id' => $existingsections[$sectionnumber]->id,
                        'name' => $sectionname,
                    ]);
                }
                continue;
            }

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

        $sectionnumbers = array_map(static function (array $info): int {
            return (int)($info['section'] ?? 0);
        }, $sectionsinfo);
        $maxsection = empty($sectionnumbers) ? 0 : max($sectionnumbers);
        if ($maxsection > 0) {
            $formatoptions = $courseformat->get_format_options();
            if (isset($formatoptions['numsections'])) {
                $courseformat->update_course_format_options(['numsections' => $maxsection]);
            }
        }

        rebuild_course_cache($courseid, true);
    }

    /**
     * Process generated activities from API response.
     *
     * @param int $courseid
     * @param array $activities
     * @return void
     */
    private static function process_generated_activities(int $courseid, array $activities): void {
        global $CFG;

        require_once($CFG->dirroot . '/course/modlib.php');
        $course = get_course($courseid);

        foreach ($activities as $activity) {
            $sectionnum = 0;
            if (isset($activity['parameters']) && isset($activity['parameters']['section'])) {
                $sectionnum = (int)$activity['parameters']['section'];
            }

            $resultinfo = [
                'result' => $activity,
            ];

            try {
                mod_manager::create_from_ai_result($resultinfo, $course, $sectionnum, null, true);
            } catch (\Throwable $e) {
                $title = (string)($activity['name'] ?? 'unknown');
                debugging('Error creating module ' . $title . ': ' . $e->getMessage());
            }
        }

        rebuild_course_cache($courseid, true);
    }
}
