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
 * Data generator for local_coursegen.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_coursegen_generator extends component_generator_base {
    /**
     * Create an institutional guideline (system instruction) row.
     *
     * The course AI creation page lists every non-deleted row of
     * local_coursegen_system_instruction as a guideline, so seeding this table
     * is enough to exercise the guideline UI without the external AI service.
     *
     * @param array  Column overrides: name (required), content, deleted.
     * @return stdClass The inserted record.
     */
    public function create_system_instruction(array $record): stdClass {
        global $DB, $USER;

        if (empty($record['name'])) {
            throw new coding_exception('A system instruction requires a name.');
        }

        $now = time();
        $instruction = (object) [
            'name' => $record['name'],
            'content' => $record['content'] ?? '',
            'deleted' => (int) ($record['deleted'] ?? 0),
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => (int) ($record['usermodified'] ?? ($USER->id ?: 2)),
        ];
        $instruction->id = $DB->insert_record('local_coursegen_system_instruction', $instruction);

        return $instruction;
    }
}
