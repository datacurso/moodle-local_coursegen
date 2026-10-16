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

use local_coursegen\utils\generated_file_cache;

/**
 * Puts the pictures the AI service made for the course itself where the template had its own.
 *
 * The cover replaces the one of the new course, the picture of a section replaces the one the grid format shows
 * for it (and is shown after the format sizes it again), and the picture of a summary replaces the one the copied
 * summary refers to.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_course_image_applier {
    /**
     * Apply every picture the service made.
     *
     * @param int $courseid The new course.
     * @param array $slotimages Slot key => generated file entry, as the result carries them.
     * @param array[] $slots The slots the run was given (the payload's image_slots).
     * @param generated_file_cache|null $cache Where the files are read from; tests pass their own.
     */
    public static function apply(int $courseid, array $slotimages, array $slots, ?generated_file_cache $cache = null): void {
        $cache = $cache ?? new generated_file_cache();
        $bykey = [];
        foreach ($slots as $slot) {
            $bykey[(string) $slot['key']] = $slot;
        }
        foreach ($slotimages as $key => $entry) {
            $slot = $bykey[(string) $key] ?? null;
            if ($slot === null) {
                continue;
            }
            // A picture is decoration: one that cannot be read back leaves the course without it, it never
            // stops the course from being made.
            try {
                $stored = $cache->get($entry);
                self::put($courseid, $slot, $entry, $stored);
                $cache->forget($entry);
            } catch (\moodle_exception $exception) {
                debugging('A picture of the course could not be applied: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            }
        }
        rebuild_course_cache($courseid, true);
    }

    /**
     * One picture, where its kind says.
     *
     * @param int $courseid
     * @param array $slot
     * @param array $entry
     * @param \stored_file $stored
     */
    private static function put(int $courseid, array $slot, array $entry, \stored_file $stored): void {
        if ($slot['kind'] === 'course_cover') {
            self::put_cover($courseid, $entry, $stored);
            return;
        }
        global $DB;
        $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => (int) $slot['section']]);
        if (!$section) {
            return;
        }
        if ($slot['kind'] === 'section_image') {
            self::put_section_image($courseid, $section, $entry, $stored);
        } else if ($slot['kind'] === 'summary_image') {
            self::put_summary_image($courseid, $section, (string) $slot['filename'], $entry, $stored);
        }
    }

    /**
     * The cover of the course.
     *
     * @param int $courseid
     * @param array $entry
     * @param \stored_file $stored
     */
    private static function put_cover(int $courseid, array $entry, \stored_file $stored): void {
        $context = \context_course::instance($courseid);
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'course', 'overviewfiles');
        $fs->create_file_from_storedfile(self::record($context->id, 'course', 'overviewfiles', 0, $entry['filename']), $stored);
    }

    /**
     * The picture a section has in the grid format. The format makes the displayed one from it when it is shown.
     *
     * @param int $courseid
     * @param \stdClass $section
     * @param array $entry
     * @param \stored_file $stored
     */
    private static function put_section_image(int $courseid, \stdClass $section, array $entry, \stored_file $stored): void {
        global $DB;

        $course = get_course($courseid);
        if ($course->format !== 'grid') {
            return;
        }
        $context = \context_course::instance($courseid);
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'format_grid', 'sectionimage', $section->id);
        $fs->delete_area_files($context->id, 'format_grid', 'displayedsectionimage', $section->id);
        $fs->create_file_from_storedfile(
            self::record($context->id, 'format_grid', 'sectionimage', (int) $section->id, $entry['filename']),
            $stored
        );
        $record = (object) [
            'sectionid' => $section->id,
            'courseid' => $courseid,
            'image' => $entry['filename'],
            'contenthash' => $stored->get_contenthash(),
            'displayedimagestate' => 0,
        ];
        $existing = $DB->get_record('format_grid_image', ['sectionid' => $section->id]);
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('format_grid_image', $record);
        } else {
            $DB->insert_record('format_grid_image', $record);
        }
    }

    /**
     * A picture of the summary of a section: the copied file is replaced and the summary refers to the new one.
     *
     * @param int $courseid
     * @param \stdClass $section
     * @param string $oldname The name the picture had in the template.
     * @param array $entry
     * @param \stored_file $stored
     */
    private static function put_summary_image(
        int $courseid,
        \stdClass $section,
        string $oldname,
        array $entry,
        \stored_file $stored
    ): void {
        global $DB;

        $context = \context_course::instance($courseid);
        $fs = get_file_storage();
        $old = $fs->get_file($context->id, 'course', 'section', $section->id, '/', $oldname);
        if ($old) {
            $old->delete();
        }
        $fs->create_file_from_storedfile(
            self::record($context->id, 'course', 'section', (int) $section->id, $entry['filename']),
            $stored
        );
        $summary = str_replace(
            ['@@PLUGINFILE@@/' . rawurlencode($oldname), '@@PLUGINFILE@@/' . $oldname],
            '@@PLUGINFILE@@/' . rawurlencode($entry['filename']),
            (string) $section->summary
        );
        $DB->set_field('course_sections', 'summary', $summary, ['id' => $section->id]);
    }

    /**
     * The record a file is created from.
     *
     * @param int $contextid
     * @param string $component
     * @param string $filearea
     * @param int $itemid
     * @param string $filename
     * @return array
     */
    private static function record(int $contextid, string $component, string $filearea, int $itemid, string $filename): array {
        return [
            'contextid' => $contextid,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
        ];
    }
}
