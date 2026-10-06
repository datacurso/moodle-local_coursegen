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
 * Tenant-aware service for system instructions.
 *
 * Every instruction belongs to exactly one tenant and is private to it: a
 * tenant only sees, uses, edits and deletes its own instructions. Names are
 * unique per tenant.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class system_instruction_service {
    /**
     * Create a new system instruction owned by a tenant.
     *
     * @param array $data Instruction data: name (string) and content (string).
     * @param int $tenantid Owning tenant.
     * @return system_instruction
     * @throws \moodle_exception When the name is empty or already used in the tenant.
     */
    public static function create(array $data, int $tenantid): system_instruction {
        global $USER;

        $name = trim((string) ($data['name'] ?? ''));
        if (!self::validate_unique_name($name, $tenantid)) {
            throw new \moodle_exception('systeminstructionnameexists', 'local_coursegen');
        }

        $now = time();
        $record = (object) [
            'name' => $name,
            'content' => (string) ($data['content'] ?? ''),
            'deleted' => 0,
            'tenantid' => $tenantid,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $USER->id,
        ];

        $instruction = new system_instruction(0, $record);
        $instruction->create();

        return $instruction;
    }

    /**
     * Update a system instruction owned by the tenant.
     *
     * @param int $id Instruction id.
     * @param array $data Instruction data: name (string) and content (string).
     * @param int $tenantid Tenant performing the update; it must own the instruction.
     * @return system_instruction
     * @throws \moodle_exception When the instruction is not owned by the tenant or the name is taken.
     */
    public static function update(int $id, array $data, int $tenantid): system_instruction {
        global $USER;

        $instruction = self::require_owned($id, $tenantid);

        $name = trim((string) ($data['name'] ?? ''));
        if (!self::validate_unique_name($name, $tenantid, $id)) {
            throw new \moodle_exception('systeminstructionnameexists', 'local_coursegen');
        }

        $instruction->set('name', $name);
        $instruction->set('content', (string) ($data['content'] ?? ''));
        $instruction->set('timemodified', time());
        $instruction->set('usermodified', $USER->id);
        $instruction->update();

        return $instruction;
    }

    /**
     * Soft delete a system instruction owned by the tenant.
     *
     * @param int $id Instruction id.
     * @param int $tenantid Tenant performing the deletion; it must own the instruction.
     * @return bool
     * @throws \moodle_exception When the instruction is not owned by the tenant.
     */
    public static function delete(int $id, int $tenantid): bool {
        global $DB;

        self::require_owned($id, $tenantid);

        $DB->set_field(system_instruction::TABLE, 'deleted', 1, ['id' => $id]);
        return true;
    }

    /**
     * Active (non deleted) instructions owned by a tenant, newest first.
     *
     * @param int $tenantid Tenant id.
     * @return system_instruction[]
     */
    public static function get_all(int $tenantid): array {
        return system_instruction::get_records(['deleted' => 0, 'tenantid' => $tenantid], 'timecreated', 'DESC');
    }

    /**
     * Active instructions a tenant may use (its own), ordered by name.
     *
     * @param int $tenantid Tenant id.
     * @return \stdClass[] Records with every table column, keyed by instruction id.
     */
    public static function get_available(int $tenantid): array {
        global $DB;

        return $DB->get_records(
            system_instruction::TABLE,
            ['deleted' => 0, 'tenantid' => $tenantid],
            'name ASC, id ASC'
        );
    }

    /**
     * An active instruction owned by the tenant, or null.
     *
     * @param int $id Instruction id.
     * @param int $tenantid Tenant id.
     * @return system_instruction|null
     */
    public static function get_by_id(int $id, int $tenantid): ?system_instruction {
        $instruction = system_instruction::get_record(['id' => $id, 'deleted' => 0, 'tenantid' => $tenantid]);
        return $instruction ?: null;
    }

    /**
     * An active instruction owned by the tenant.
     *
     * @param int $id Instruction id.
     * @param int $tenantid Tenant that must own the instruction.
     * @return system_instruction
     * @throws \moodle_exception When the instruction does not exist, is deleted or belongs to someone else.
     */
    public static function require_owned(int $id, int $tenantid): system_instruction {
        $instruction = system_instruction::get_record(['id' => $id, 'deleted' => 0]);
        if (!$instruction || (int) $instruction->get('tenantid') !== $tenantid) {
            throw new \moodle_exception('invalidsysteminstruction', 'local_coursegen');
        }
        return $instruction;
    }

    /**
     * Ensure a tenant may use an instruction: it must be an active instruction of its own.
     *
     * @param int $id Instruction id.
     * @param int $tenantid Tenant id.
     * @return void
     * @throws \invalid_parameter_exception When the instruction is deleted, missing or belongs to another tenant.
     */
    public static function assert_accessible(int $id, int $tenantid): void {
        if (self::get_by_id($id, $tenantid) === null) {
            throw new \invalid_parameter_exception('The system instruction is not available for this tenant.');
        }
    }

    /**
     * Content of an instruction by id, empty when it does not exist or has no content.
     *
     * Callers resolve access first ({@see assert_accessible()}): the instruction
     * referenced by a course keeps being read by id whatever tenant reads it.
     *
     * @param int $id Instruction id.
     * @return string
     */
    public static function get_instruction_content(int $id): string {
        $instruction = system_instruction::get_record(['id' => $id, 'deleted' => 0]);
        if (!$instruction) {
            return '';
        }
        return (string) ($instruction->get('content') ?? '');
    }

    /**
     * Whether a name is free among the active instructions of a tenant.
     *
     * @param string $name Instruction name (trimmed before comparing).
     * @param int $tenantid Tenant id.
     * @param int|null $excludeid Instruction to ignore (the one being updated).
     * @return bool False for an empty name or a name already used in the tenant.
     */
    public static function validate_unique_name(string $name, int $tenantid, ?int $excludeid = null): bool {
        global $DB;

        $name = trim($name);
        if ($name === '') {
            return false;
        }

        $sql = 'name = :name AND deleted = 0 AND tenantid = :tenantid';
        $params = ['name' => $name, 'tenantid' => $tenantid];
        if ($excludeid) {
            $sql .= ' AND id <> :id';
            $params['id'] = $excludeid;
        }

        return !$DB->record_exists_select(system_instruction::TABLE, $sql, $params);
    }
}
