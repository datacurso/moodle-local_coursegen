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

namespace local_coursegen\local\reference;

use cm_info;
use course_modinfo;
use local_coursegen\local\link\module_link_texts;
use local_coursegen\local\link\module_link_texts_registry;

/**
 * Makes sure no reference of the teacher's files is left unresolved in a built course.
 *
 * The file of a reference reaches the new activity through the copy of the
 * files a text refers to. When a text was not copied that way, it would still
 * name a temporary file of the plugin, or still hold its token, and the course
 * would break once that file is deleted. Only the modules whose texts are
 * searched for link tokens are read.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reference_leftover_guard {
    /** @var string What a text holds when a reference was not resolved or its file was not copied. */
    private const LEFTOVER_PATTERN = '~\$@COURSEGENFILE\*|/local_coursegen/referencefile/~';

    /**
     * Refuse a course whose generated activities still point at a temporary reference.
     *
     * @param int $courseid
     * @param int[] $generatedcmids The created activities that were written by the run.
     * @throws \moodle_exception Naming the first activity that still holds one.
     */
    public static function ensure_none_left(int $courseid, array $generatedcmids): void {
        course_modinfo::clear_instance_cache($courseid);
        $modinfo = get_fast_modinfo($courseid);
        foreach ($generatedcmids as $cmid) {
            $cm = $modinfo->get_cm($cmid);
            self::ensure_activity($cm);
        }
    }

    /**
     * Check every text of one activity, when its module is searched.
     *
     * @param cm_info $cm
     */
    private static function ensure_activity(cm_info $cm): void {
        global $DB;

        $texts = module_link_texts_registry::for_module($cm->modname);
        if ($texts === null) {
            return;
        }
        $records = $DB->get_records($texts->table(), [$texts->instance_column() => $cm->instance]);
        foreach ($records as $record) {
            self::ensure_record($cm, $texts, $record);
        }
    }

    /**
     * Check the text columns of one record.
     *
     * @param cm_info $cm
     * @param module_link_texts $texts
     * @param \stdClass $record
     */
    private static function ensure_record(cm_info $cm, module_link_texts $texts, \stdClass $record): void {
        $columns = $texts->text_columns();
        foreach ($columns as $column) {
            $text = (string) $record->{$column};
            if (preg_match(self::LEFTOVER_PATTERN, $text)) {
                throw new \moodle_exception('referenceleftover', 'local_coursegen', '', $cm->name);
            }
        }
    }
}
