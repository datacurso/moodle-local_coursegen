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

namespace local_coursegen\local\template;

use moodle_exception;
use stdClass;

/**
 * Checks and cleans what the editor sends when a template is saved.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_input {
    /** @var int Longest template name, the size of its column. */
    public const MAX_NAME_LENGTH = 255;

    /**
     * Clean the name of a template.
     *
     * @param string $name Name typed by the admin.
     * @return string
     * @throws moodle_exception When it is empty or too long.
     */
    public static function clean_name(string $name): string {
        $valid = fix_utf8($name);
        $spaced = preg_replace('/[\x00-\x1F\x7F]/', ' ', $valid);
        $collapsed = preg_replace('/\s+/u', ' ', $spaced);
        $trimmed = trim($collapsed);
        if ($trimmed === '') {
            throw new moodle_exception('error_template_name_required', 'local_coursegen');
        }

        if (\core_text::strlen($trimmed) > self::MAX_NAME_LENGTH) {
            throw new moodle_exception('error_template_name_too_long', 'local_coursegen', '', self::MAX_NAME_LENGTH);
        }

        return $trimmed;
    }

    /**
     * Validate the activities sent by the editor and turn them into rows.
     *
     * @param int $courseid Course of the template.
     * @param array[] $items One entry per activity.
     * @return stdClass[] Rows without template id.
     * @throws moodle_exception When an entry is not valid or repeats an activity.
     */
    public static function build_rows(int $courseid, array $items): array {
        $structure = course_structure::for_course($courseid);
        $cmids = course_structure::cmids_of($structure);
        $validcmids = array_flip($cmids);
        $seen = [];
        $rows = [];
        foreach ($items as $item) {
            $row = self::build_row($item, $validcmids);
            if (isset($seen[$row->cmid])) {
                throw new moodle_exception('error_template_duplicate_cm', 'local_coursegen');
            }
            $seen[$row->cmid] = true;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Validate one activity and turn it into a row.
     *
     * @param mixed $item Entry with cmid, action and an optional instruction.
     * @param array $validcmids Ids of the activities of the course, as keys.
     * @return stdClass Row without template id.
     * @throws moodle_exception When the entry is not valid.
     */
    private static function build_row($item, array $validcmids): stdClass {
        if (!is_array($item)) {
            throw new moodle_exception('error_template_item_invalid', 'local_coursegen');
        }

        $rawcmid = $item['cmid'] ?? 0;
        $rawaction = $item['action'] ?? template_actions::KEEP;
        $rawinstruction = $item['instruction'] ?? null;
        $cmid = (int) $rawcmid;
        if (!isset($validcmids[$cmid])) {
            throw new moodle_exception('error_template_cm_not_in_course', 'local_coursegen');
        }

        if (!is_string($rawaction) || !template_actions::is_valid($rawaction)) {
            throw new moodle_exception('error_template_action_invalid', 'local_coursegen');
        }

        $row = new stdClass();
        $row->cmid = $cmid;
        $row->action = $rawaction;
        $row->instruction = self::clean_instruction($rawaction, $rawinstruction);
        $row->timemodified = time();

        return $row;
    }

    /**
     * Clean the instruction of an activity. Only an activity the AI modifies keeps one.
     *
     * @param string $action Action of the activity.
     * @param mixed $instruction Text typed by the admin, or null.
     * @return string|null
     * @throws moodle_exception When it is not text or is too long.
     */
    private static function clean_instruction(string $action, $instruction): ?string {
        if ($action !== template_actions::AI) {
            return null;
        }

        if ($instruction !== null && !is_string($instruction)) {
            throw new moodle_exception('error_template_item_invalid', 'local_coursegen');
        }

        return plain_text::normalize($instruction);
    }
}
