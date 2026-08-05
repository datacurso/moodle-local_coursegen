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

use local_coursegen\local\models\system_instruction;

/**
 * Service class for handling system instructions using the persistent model.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class system_instruction_service {
    /**
     * Create a new system instruction.
     *
     * @param string $name Instruction name
     * @param string $content Instruction content
     * @return system_instruction
     */
    public static function create(string $name, string $content): system_instruction {
        global $USER;

        if (!self::validate_unique_name($name)) {
            throw new \moodle_exception('systeminstructionnameexists', 'local_coursegen');
        }

        $now = time();

        $record = (object) [
            'name' => trim($name),
            'content' => $content,
            'deleted' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $USER->id,
        ];

        $instruction = new system_instruction(0, $record);
        $instruction->create();

        return $instruction;
    }

    /**
     * Update an existing system instruction.
     *
     * @param int $id Instruction ID
     * @param string $name Instruction name
     * @param string $content Instruction content
     * @return system_instruction
     */
    public static function update(int $id, string $name, string $content): system_instruction {
        global $USER;

        $instruction = self::get_by_id($id);
        if (!$instruction) {
            throw new \moodle_exception('invalidrecord', 'error');
        }

        if (!self::validate_unique_name($name, $id)) {
            throw new \moodle_exception('systeminstructionnameexists', 'local_coursegen');
        }

        $now = time();

        $instruction->set('name', trim($name));
        $instruction->set('content', $content);
        $instruction->set('timemodified', $now);
        $instruction->set('usermodified', $USER->id);

        $instruction->update();

        return $instruction;
    }

    /**
     * Soft delete a system instruction.
     *
     * @param int $id Instruction ID
     * @return bool
     */
    public static function delete(int $id): bool {
        global $DB;

        $instruction = self::get_by_id($id);
        if (!$instruction) {
            return false;
        }

        $DB->set_field(system_instruction::TABLE, 'deleted', 1, ['id' => $id]);
        return true;
    }

    /**
     * Get all active (non deleted) system instructions.
     *
     * @return system_instruction[]
     */
    public static function get_all(): array {
        return system_instruction::get_records(['deleted' => 0], 'timecreated', 'DESC');
    }

    /**
     * Get a system instruction by ID.
     *
     * @param int $id Instruction ID
     * @return system_instruction|null
     */
    public static function get_by_id(int $id): ?system_instruction {
        $instruction = system_instruction::get_record(['id' => $id, 'deleted' => 0]);
        return $instruction ?: null;
    }

    /**
     * Get the content of a system instruction by ID.
     *
     * Returns an empty string if the instruction does not exist or has empty content.
     *
     * @param int $id Instruction ID
     * @return string
     */
    public static function get_instruction_content(int $id): string {
        $instruction = self::get_by_id($id);

        if (!$instruction) {
            return '';
        }

        $content = $instruction->get('content');
        if (!$content) {
            return '';
        }

        return (string)$content;
    }

    /**
     * Validate that a system instruction name is unique (among non deleted records).
     *
     * @param string $name Instruction name
     * @param int|null $excludeid ID to exclude from the check (for updates)
     * @return bool
     */
    private static function validate_unique_name(string $name, ?int $excludeid = null): bool {
        global $DB;

        $name = trim($name);
        if ($name === '') {
            return false;
        }

        if ($excludeid) {
            $sql = "name = :name AND deleted = 0 AND id <> :id";
            $params = ['name' => $name, 'id' => $excludeid];
        } else {
            $sql = "name = :name AND deleted = 0";
            $params = ['name' => $name];
        }

        return !$DB->record_exists_select(system_instruction::TABLE, $sql, $params);
    }
}
