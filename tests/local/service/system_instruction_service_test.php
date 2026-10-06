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

/**
 * Tests for the tenant-aware system instruction service.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\system_instruction_service
 */
final class system_instruction_service_test extends \advanced_testcase {
    /**
     * Creates a tenant and returns its id.
     *
     * @return int
     */
    private function create_tenant(): int {
        return (int) $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_tenant()->id;
    }

    /**
     * Returns the names of a list of instructions, in order.
     *
     * @param array $instructions Persistents or records.
     * @return string[]
     */
    private function names_of(array $instructions): array {
        return array_values(array_map(
            fn($instruction) => $instruction instanceof \core\persistent ? $instruction->get('name') : $instruction->name,
            $instructions
        ));
    }

    /**
     * A created instruction stores the tenant it belongs to.
     */
    public function test_create_stores_tenantid(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenantid = $this->create_tenant();

        $instruction = system_instruction_service::create(['name' => 'Tone', 'content' => 'Be concise.'], $tenantid);

        $record = $DB->get_record('local_coursegen_system_instruction', ['id' => $instruction->get('id')], '*', MUST_EXIST);
        $this->assertEquals($tenantid, $record->tenantid);
        $this->assertSame('Tone', $record->name);
        $this->assertSame('Be concise.', $record->content);
    }

    /**
     * get_available() returns only the tenant's own active rows, ordered by name; legacy tenant 0 rows are never shared.
     */
    public function test_get_available_returns_only_tenant_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenanta = $this->create_tenant();
        $tenantb = $this->create_tenant();

        $DB->insert_record('local_coursegen_system_instruction', (object) [
            'name' => 'Legacy site', 'content' => '', 'deleted' => 0, 'tenantid' => 0,
            'timecreated' => time(), 'timemodified' => time(), 'usermodified' => 2,
        ]);
        system_instruction_service::create(['name' => 'Zeta own', 'content' => ''], $tenanta);
        system_instruction_service::create(['name' => 'Alpha own', 'content' => ''], $tenanta);
        system_instruction_service::create(['name' => 'Beta other', 'content' => ''], $tenantb);
        $deleted = system_instruction_service::create(['name' => 'Deleted own', 'content' => ''], $tenanta);
        system_instruction_service::delete((int) $deleted->get('id'), $tenanta);

        $this->assertSame(['Alpha own', 'Zeta own'], $this->names_of(system_instruction_service::get_available($tenanta)));
        $this->assertSame(['Beta other'], $this->names_of(system_instruction_service::get_available($tenantb)));
    }

    /**
     * get_all() returns only the rows owned by the tenant.
     */
    public function test_get_all_excludes_other_tenants(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenanta = $this->create_tenant();
        $tenantb = $this->create_tenant();

        system_instruction_service::create(['name' => 'Own', 'content' => ''], $tenanta);
        system_instruction_service::create(['name' => 'Other', 'content' => ''], $tenantb);

        $this->assertSame(['Own'], $this->names_of(system_instruction_service::get_all($tenanta)));
        $this->assertSame(['Other'], $this->names_of(system_instruction_service::get_all($tenantb)));
    }

    /**
     * A name must be unique within a tenant but may be reused by another tenant.
     */
    public function test_name_unique_per_tenant_reusable_across(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenanta = $this->create_tenant();
        $tenantb = $this->create_tenant();

        $own = system_instruction_service::create(['name' => 'Policy', 'content' => ''], $tenanta);
        system_instruction_service::create(['name' => 'Policy', 'content' => ''], $tenantb);

        $this->assertFalse(system_instruction_service::validate_unique_name('Policy', $tenanta));
        $this->assertFalse(system_instruction_service::validate_unique_name(' policy ', $tenanta));
        $this->assertTrue(system_instruction_service::validate_unique_name('Policy', $tenanta, (int) $own->get('id')));
        $this->assertTrue(system_instruction_service::validate_unique_name('Other policy', $tenanta));
        $this->assertFalse(system_instruction_service::validate_unique_name('', $tenanta));

        $this->expectException(\moodle_exception::class);
        system_instruction_service::create(['name' => 'Policy', 'content' => ''], $tenanta);
    }

    /**
     * get_by_id() returns own rows, but not the rows of another tenant nor deleted rows.
     */
    public function test_get_by_id_denies_other_tenant(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenanta = $this->create_tenant();
        $tenantb = $this->create_tenant();

        $own = system_instruction_service::create(['name' => 'Own', 'content' => ''], $tenanta);
        $other = system_instruction_service::create(['name' => 'Other', 'content' => ''], $tenantb);

        $this->assertSame('Own', system_instruction_service::get_by_id((int) $own->get('id'), $tenanta)->get('name'));
        $this->assertNull(system_instruction_service::get_by_id((int) $other->get('id'), $tenanta));
        $this->assertNull(system_instruction_service::get_by_id((int) $own->get('id'), $tenantb));

        system_instruction_service::delete((int) $own->get('id'), $tenanta);
        $this->assertNull(system_instruction_service::get_by_id((int) $own->get('id'), $tenanta));
    }

    /**
     * update() changes an owned row and refuses another tenant's row.
     */
    public function test_update_requires_ownership(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenanta = $this->create_tenant();
        $tenantb = $this->create_tenant();

        $own = system_instruction_service::create(['name' => 'Own', 'content' => 'v1'], $tenanta);
        $other = system_instruction_service::create(['name' => 'Other', 'content' => ''], $tenantb);

        $updated = system_instruction_service::update((int) $own->get('id'), ['name' => 'Own 2', 'content' => 'v2'], $tenanta);
        $this->assertSame('Own 2', $updated->get('name'));
        $this->assertSame('v2', $updated->get('content'));

        $this->expectException(\moodle_exception::class);
        system_instruction_service::update((int) $other->get('id'), ['name' => 'Hijacked', 'content' => ''], $tenanta);
    }

    /**
     * delete() soft deletes an owned row and refuses another tenant's row.
     */
    public function test_delete_requires_ownership(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenanta = $this->create_tenant();
        $tenantb = $this->create_tenant();

        $own = system_instruction_service::create(['name' => 'Own', 'content' => ''], $tenanta);
        $other = system_instruction_service::create(['name' => 'Other', 'content' => ''], $tenantb);

        $this->assertTrue(system_instruction_service::delete((int) $own->get('id'), $tenanta));
        $this->assertEquals(1, $DB->get_field('local_coursegen_system_instruction', 'deleted', ['id' => $own->get('id')]));

        $this->expectException(\moodle_exception::class);
        system_instruction_service::delete((int) $other->get('id'), $tenanta);
    }

    /**
     * assert_accessible() accepts own rows and rejects legacy tenant 0, other tenants' and deleted rows.
     */
    public function test_assert_accessible(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenanta = $this->create_tenant();
        $tenantb = $this->create_tenant();

        $own = system_instruction_service::create(['name' => 'Own', 'content' => ''], $tenanta);
        $other = system_instruction_service::create(['name' => 'Other', 'content' => ''], $tenantb);
        $legacy = (int) $DB->insert_record('local_coursegen_system_instruction', (object) [
            'name' => 'Legacy site', 'content' => '', 'deleted' => 0, 'tenantid' => 0,
            'timecreated' => time(), 'timemodified' => time(), 'usermodified' => 2,
        ]);

        system_instruction_service::assert_accessible((int) $own->get('id'), $tenanta);

        $rejected = 0;
        foreach ([(int) $other->get('id'), $legacy, 999999] as $id) {
            try {
                system_instruction_service::assert_accessible($id, $tenanta);
            } catch (\invalid_parameter_exception $e) {
                $rejected++;
            }
        }
        $this->assertSame(3, $rejected);

        system_instruction_service::delete((int) $own->get('id'), $tenanta);
        $this->expectException(\invalid_parameter_exception::class);
        system_instruction_service::assert_accessible((int) $own->get('id'), $tenanta);
    }

    /**
     * get_instruction_content() still resolves content by id (callers assert access first).
     */
    public function test_get_instruction_content(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenantid = $this->create_tenant();

        $own = system_instruction_service::create(['name' => 'Own', 'content' => 'Follow the rules.'], $tenantid);

        $this->assertSame('Follow the rules.', system_instruction_service::get_instruction_content((int) $own->get('id')));
        $this->assertSame('', system_instruction_service::get_instruction_content(999999));
    }
}
