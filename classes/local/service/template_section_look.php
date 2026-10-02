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

/**
 * The look of each section of a course made from a template: its summary
 * with the files the summary shows, the options its format keeps for it, and
 * the picture the grid format shows it with. All of it is the template's.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_section_look {
    /**
     * Give every section of the new course the look of the same section of the template.
     *
     * @param \stdClass $course The new course, its sections already created.
     * @param array $sectionsinfo The payload's sections_info.
     * @param int $sourcecourseid The template's course.
     */
    public static function apply(\stdClass $course, array $sectionsinfo, int $sourcecourseid): void {
        global $DB;

        $sourcecourse = get_course($sourcecourseid);
        $sections = $DB->get_records('course_sections', ['course' => $course->id], '', 'section, id');
        $sourcesections = $DB->get_records('course_sections', ['course' => $sourcecourseid], '', 'section, id');

        foreach ($sectionsinfo as $info) {
            $number = (int) $info['section'];
            self::apply_to_section($course, $sourcecourse, $sections[$number], $sourcesections[$number], $info);
        }
        rebuild_course_cache($course->id, true);
    }

    /**
     * One section.
     *
     * @param \stdClass $course
     * @param \stdClass $sourcecourse
     * @param \stdClass $section The new section.
     * @param \stdClass $sourcesection The same section of the template's course.
     * @param array $info The section's entry of the payload.
     */
    private static function apply_to_section(
        \stdClass $course,
        \stdClass $sourcecourse,
        \stdClass $section,
        \stdClass $sourcesection,
        array $info
    ): void {
        self::apply_summary($course, $sourcecourse, $section, $sourcesection, $info);

        $options = $info['format_options'] ?? [];
        if (!empty($options)) {
            $options['id'] = $section->id;
            course_get_format($course)->update_section_format_options($options);
        }

        if ($course->format === 'grid') {
            template_grid_section_image::copy($course, $sourcecourse, $section, $sourcesection);
        }
    }

    /**
     * The summary of a section, with the files it shows.
     *
     * The payload carries the summary with its files as addresses of the
     * template's course; they go back to the placeholder a summary is saved
     * with, and the files are copied under the new section.
     *
     * @param \stdClass $course
     * @param \stdClass $sourcecourse
     * @param \stdClass $section
     * @param \stdClass $sourcesection
     * @param array $info
     */
    private static function apply_summary(
        \stdClass $course,
        \stdClass $sourcecourse,
        \stdClass $section,
        \stdClass $sourcesection,
        array $info
    ): void {
        global $CFG, $DB;

        $fromcontextid = \context_course::instance($sourcecourse->id)->id;
        $tocontextid = \context_course::instance($course->id)->id;

        $address = $CFG->wwwroot . '/pluginfile.php/' . $fromcontextid . '/course/section/' . $sourcesection->id;
        $summary = str_replace($address, '@@PLUGINFILE@@', (string) ($info['summary'] ?? ''));

        $DB->update_record('course_sections', (object) [
            'id' => $section->id,
            'summary' => $summary,
            'summaryformat' => (int) ($info['summaryformat'] ?? FORMAT_HTML),
            'timemodified' => time(),
        ]);
        template_area_files_copier::copy($fromcontextid, $tocontextid, 'course', 'section', $sourcesection->id, $section->id);
    }
}
