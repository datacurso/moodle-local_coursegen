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

use cm_info;
use course_modinfo;
use local_coursegen\local\link\module_link_texts;
use local_coursegen\local\link\module_link_texts_registry;
use local_coursegen\local\link\syllabus_link;

/**
 * Leaves the syllabus the professor uploaded in each generated text that links to it.
 *
 * A generated activity whose text has a syllabus link (see syllabus_link) gets a copy of the uploaded file in the
 * file area of that text, and the link points at it, so the course offers the very document it was made from. When
 * no file was uploaded those links are taken out. Texts without a syllabus link are not touched.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_syllabus_linker {
    /**
     * Link the syllabus from the texts of the generated activities of a course.
     *
     * @param int $courseid
     * @param int[] $generatedcmids The activities whose texts are searched.
     * @param \stored_file|null $syllabus The uploaded file; null when the professor uploaded none.
     */
    public static function apply(int $courseid, array $generatedcmids, ?\stored_file $syllabus): void {
        course_modinfo::clear_instance_cache($courseid);
        $modinfo = get_fast_modinfo($courseid);
        foreach ($generatedcmids as $cmid) {
            $cm = $modinfo->get_cm((int) $cmid);
            self::apply_to_activity($cm, $syllabus);
        }
    }

    /**
     * Link the syllabus from every text of one activity, when its module is searched.
     *
     * @param cm_info $cm
     * @param \stored_file|null $syllabus
     */
    private static function apply_to_activity(cm_info $cm, ?\stored_file $syllabus): void {
        global $DB;

        $texts = module_link_texts_registry::for_module($cm->modname);
        if ($texts === null) {
            return;
        }
        $records = $DB->get_records($texts->table(), [$texts->instance_column() => $cm->instance]);
        foreach ($records as $record) {
            foreach ($texts->text_columns() as $column) {
                self::apply_to_text($cm, $texts, $record, $column, $syllabus);
            }
        }
    }

    /**
     * Link the syllabus from one text, keeping a copy of the file with it.
     *
     * @param cm_info $cm
     * @param module_link_texts $texts
     * @param \stdClass $record
     * @param string $column
     * @param \stored_file|null $syllabus
     */
    private static function apply_to_text(
        cm_info $cm,
        module_link_texts $texts,
        \stdClass $record,
        string $column,
        ?\stored_file $syllabus
    ): void {
        global $DB;

        $original = (string) $record->{$column};
        if (!syllabus_link::present($original)) {
            return;
        }
        $filename = null;
        if ($syllabus !== null) {
            [$component, $filearea, $itemid] = $texts->file_area($column, $record);
            $filename = self::keep_copy($syllabus, $cm->context->id, $component, $filearea, $itemid);
        }
        $linked = syllabus_link::apply($original, $filename);
        if ($linked !== $original) {
            $DB->set_field($texts->table(), $column, $linked, ['id' => $record->id]);
        }
    }

    /**
     * A copy of the syllabus in a file area, made once even when several texts of the area link to it.
     *
     * @param \stored_file $syllabus
     * @param int $contextid
     * @param string $component
     * @param string $filearea
     * @param int $itemid
     * @return string The name of the copy.
     */
    private static function keep_copy(
        \stored_file $syllabus,
        int $contextid,
        string $component,
        string $filearea,
        int $itemid
    ): string {
        $fs = get_file_storage();
        $filename = $syllabus->get_filename();
        if (!$fs->file_exists($contextid, $component, $filearea, $itemid, '/', $filename)) {
            $fs->create_file_from_storedfile([
                'contextid' => $contextid,
                'component' => $component,
                'filearea' => $filearea,
                'itemid' => $itemid,
                'filepath' => '/',
                'filename' => $filename,
            ], $syllabus);
        }
        return $filename;
    }
}
