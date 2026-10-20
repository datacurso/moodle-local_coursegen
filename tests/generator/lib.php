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
     * @param array $record Column overrides: name (required), content, deleted.
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

    /**
     * Create a role holding the full "create a course with AI" capability set.
     *
     * The role takes the editingteacher archetype defaults (reset_role_capabilities)
     * plus the four extra capabilities creation_permissions requires, all as
     * role definitions at system level, so assigning it in any context grants
     * the full set there. Each call creates a new role with a unique shortname.
     *
     * @param array $record Optional overrides: name, shortname.
     * @return int The role id.
     */
    public function create_course_creator_role(array $record = []): int {
        static $counter = 0;
        $counter++;

        $name = $record['name'] ?? ('AI course creator ' . $counter);
        $shortname = $record['shortname'] ?? ('aicoursecreator' . $counter);
        $roleid = create_role($name, $shortname, '', \local_coursegen\local\service\creation_permissions::ARCHETYPE);
        reset_role_capabilities($roleid);

        $systemcontext = context_system::instance();
        foreach (\local_coursegen\local\service\creation_permissions::EXTRA_CAPABILITIES as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $systemcontext->id, true);
        }

        return $roleid;
    }
}
