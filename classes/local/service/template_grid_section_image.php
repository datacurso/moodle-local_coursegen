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
 * The picture a section of the grid format is shown with, taken from the
 * same section of the template's course.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_grid_section_image {
    /**
     * Copy the picture of a section, original and displayed one, and the record that ties them to it.
     *
     * @param \stdClass $course The new course.
     * @param \stdClass $sourcecourse The template's course.
     * @param \stdClass $section The new section.
     * @param \stdClass $sourcesection The same section of the template's course.
     */
    public static function copy(
        \stdClass $course,
        \stdClass $sourcecourse,
        \stdClass $section,
        \stdClass $sourcesection
    ): void {
        global $DB;

        $image = $DB->get_record('format_grid_image', ['sectionid' => $sourcesection->id]);
        if (!$image) {
            return;
        }

        $record = clone $image;
        unset($record->id);
        $record->sectionid = $section->id;
        $record->courseid = $course->id;
        $DB->insert_record('format_grid_image', $record);

        $fromcontextid = \context_course::instance($sourcecourse->id)->id;
        $tocontextid = \context_course::instance($course->id)->id;
        self::copy_area('sectionimage', $fromcontextid, $tocontextid, $sourcesection, $section);
        self::copy_area('displayedsectionimage', $fromcontextid, $tocontextid, $sourcesection, $section);
    }

    /**
     * Copy the files of one of the file areas the grid format keeps a section's picture in.
     *
     * @param string $filearea
     * @param int $fromcontextid
     * @param int $tocontextid
     * @param \stdClass $sourcesection
     * @param \stdClass $section
     */
    private static function copy_area(
        string $filearea,
        int $fromcontextid,
        int $tocontextid,
        \stdClass $sourcesection,
        \stdClass $section
    ): void {
        template_area_files_copier::copy(
            $fromcontextid,
            $tocontextid,
            'format_grid',
            $filearea,
            $sourcesection->id,
            $section->id
        );
    }
}
