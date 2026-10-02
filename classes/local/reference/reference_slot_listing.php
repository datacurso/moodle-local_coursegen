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

/**
 * The places of a template as the teacher sees them: what each asks for and what was already brought.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reference_slot_listing {
    /**
     * One row per place of the template.
     *
     * @param int $userid The teacher.
     * @param int $templateid
     * @return array[] Each {key, activityname, instruction, kind, accept, filename, hasfile}.
     */
    public static function rows(int $userid, int $templateid): array {
        $slots = reference_slot_scanner::for_template($templateid);
        $rows = [];
        foreach ($slots as $slot) {
            $rows[] = self::row_of($slot, $userid, $templateid);
        }
        return $rows;
    }

    /**
     * The row of one place.
     *
     * @param reference_slot $slot
     * @param int $userid
     * @param int $templateid
     * @return array
     */
    private static function row_of(reference_slot $slot, int $userid, int $templateid): array {
        $key = $slot->key();
        $file = reference_file_storage::staged_file($userid, $templateid, $key);
        $filename = '';
        if ($file !== null) {
            $filename = $file->get_filename();
        }
        $extensions = reference_file_policy::extensions_for($slot->kind);
        return [
            'key' => $key,
            'activityname' => $slot->activityname,
            'instruction' => $slot->instruction,
            'kind' => $slot->kind,
            'accept' => implode(',', $extensions),
            'filename' => $filename,
            'hasfile' => $file !== null,
        ];
    }
}
