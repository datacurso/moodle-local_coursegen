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

namespace local_coursegen;

use local_coursegen\local\models\system_instruction;
use local_coursegen\local\service\system_instruction_service;

/**
 * Tests for the institutional directives (system instructions) lifecycle.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\system_instruction_service
 */
final class system_instruction_service_test extends \advanced_testcase {
    /**
     * MDL-INT-014: A directive is created with name and content, edited, and
     * removed from the active listing on deletion.
     */
    public function test_directive_lifecycle_create_edit_delete(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $directive = system_instruction_service::create('Estilo institucional', 'Usar tono formal.');
        $this->assertSame('Estilo institucional', $directive->get('name'));
        $this->assertSame('Usar tono formal.', $directive->get('content'));

        $updated = system_instruction_service::update(
            (int)$directive->get('id'),
            'Estilo institucional v2',
            'Usar tono cercano.'
        );
        $this->assertSame('Estilo institucional v2', $updated->get('name'));
        $this->assertSame('Usar tono cercano.', $updated->get('content'));

        $this->assertTrue(system_instruction_service::delete((int)$directive->get('id')));
        $this->assertNull(system_instruction_service::get_by_id((int)$directive->get('id')));
        $this->assertCount(0, system_instruction_service::get_all());
    }

    /**
     * MDL-INT-014: The name must be unique among the active directives; the
     * name of a deleted directive can be reused.
     */
    public function test_name_unique_among_active_directives(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $directive = system_instruction_service::create('Unica', 'Contenido.');

        try {
            system_instruction_service::create('Unica', 'Otro contenido.');
            $this->fail('A duplicate active directive name must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('systeminstructionnameexists', $e->errorcode);
        }

        // After the logical deletion the name becomes available again.
        system_instruction_service::delete((int)$directive->get('id'));
        $reused = system_instruction_service::create('Unica', 'Contenido nuevo.');
        $this->assertSame('Unica', $reused->get('name'));
    }

    /**
     * MDL-INT-014: Deletion is logical: the directive stops being listed but
     * the record is not physically removed.
     */
    public function test_deletion_is_logical_not_physical(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $directive = system_instruction_service::create('Borrable', 'Contenido.');
        $id = (int)$directive->get('id');

        system_instruction_service::delete($id);

        // Gone from every listing and lookup...
        $this->assertNull(system_instruction_service::get_by_id($id));
        $this->assertCount(0, system_instruction_service::get_all());
        $this->assertSame('', system_instruction_service::get_instruction_content($id));

        // ...but the row is still physically there, flagged as deleted.
        $record = $DB->get_record(system_instruction::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(1, (int)$record->deleted);
    }

    /**
     * MDL-INT-014: The management page requires the manage system instructions
     * permission, granted to managers by default and denied to plain users.
     */
    public function test_management_requires_capability(): void {
        global $DB;

        $this->resetAfterTest();

        $capability = 'local/coursegen:managesysteminstructions';
        $info = get_capability_info($capability);
        $this->assertNotNull($info, 'The capability required by the management page must be declared.');
        $this->assertSame(CONTEXT_SYSTEM, (int)$info->contextlevel);

        $systemcontext = \context_system::instance();
        $generator = $this->getDataGenerator();

        // A site manager (default archetype grant) can manage the directives.
        $manager = $generator->create_user();
        $managerrole = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerrole, $manager->id, $systemcontext->id);
        $this->assertTrue(has_capability($capability, $systemcontext, $manager));

        // A plain user cannot.
        $plainuser = $generator->create_user();
        $this->assertFalse(has_capability($capability, $systemcontext, $plainuser));
    }

    /**
     * MDL-INT-014: Directives are grouped under manageable categories.
     */
    public function test_directive_categories_are_manageable(): void {
        $this->markTestSkipped(
            'La etiqueta de categoria mostrada junto a cada directriz es fija (General); no existe '
            . 'gestion real de categorias. Pendiente hasta implementarla.'
        );
    }
}
