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

use local_coursegen\form\system_instruction_form;
use local_coursegen\local\service\system_instruction_service;
use local_coursegen\local\tenancy;
use local_coursegen\output\system_instruction_list;

/**
 * Tenant boundaries of the system instruction management pages.
 *
 * The pages always work on the tenant of the current user and hand it to the
 * service, so the contract is exercised at the service, form and renderable
 * level: a tenant administrator manages only the rows of their tenant and can
 * neither see, edit nor delete the rows of another tenant.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\system_instruction_service
 * @covers     \local_coursegen\form\system_instruction_form
 * @covers     \local_coursegen\output\system_instruction_list
 */
final class manage_system_instructions_permissions_test extends \advanced_testcase {
    use \local_coursegen\tests\requires_workplace;

    /**
     * Creates a tenant and a user who administers it, logs that user in and returns the tenant id.
     *
     * @return int
     */
    private function login_as_new_tenant_admin(): int {
        $this->require_tool_tenant();
        $generator = $this->getDataGenerator()->get_plugin_generator('tool_tenant');
        $tenantid = (int) $generator->create_tenant()->id;
        $tenantadmin = $this->getDataGenerator()->create_user();
        $generator->allocate_user($tenantadmin->id, $tenantid);
        (new \tool_tenant\manager())->assign_tenant_admin_roles([$tenantadmin->id], $tenantid);
        $this->setUser($tenantadmin);
        return $tenantid;
    }

    /**
     * Creates an instruction in a new tenant other than the one of the user logged in afterwards.
     *
     * @param string $name Instruction name.
     * @return \local_coursegen\local\models\system_instruction
     */
    private function create_other_tenant_instruction(string $name): \local_coursegen\local\models\system_instruction {
        $this->require_tool_tenant();
        $this->setAdminUser();
        $othertenantid = (int) $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_tenant()->id;
        return system_instruction_service::create(['name' => $name, 'content' => ''], $othertenantid);
    }

    /**
     * A tenant administrator lists only the rows of their own tenant.
     */
    public function test_tenant_admin_lists_own_rows_only(): void {
        $this->resetAfterTest();
        $this->create_other_tenant_instruction('Other rule');

        $tenantid = $this->login_as_new_tenant_admin();
        system_instruction_service::create(['name' => 'Own rule', 'content' => ''], tenancy::get_tenant_id());

        $this->assertSame($tenantid, tenancy::get_tenant_id());
        $own = system_instruction_service::get_all($tenantid);
        $this->assertCount(1, $own);
        $this->assertSame('Own rule', reset($own)->get('name'));
        $available = system_instruction_service::get_available($tenantid);
        $this->assertSame(['Own rule'], array_values(array_column($available, 'name')));
    }

    /**
     * The list renderable shows every row as editable, with links that carry no tenant parameter.
     */
    public function test_list_rows_are_editable_without_tenant_parameter(): void {
        global $PAGE;
        $output = $PAGE->get_renderer('core');
        $this->resetAfterTest();
        $tenantid = $this->login_as_new_tenant_admin();
        $own = system_instruction_service::create(['name' => 'Own rule', 'content' => ''], $tenantid);

        $list = new system_instruction_list(system_instruction_service::get_available($tenantid));
        $exported = $list->export_for_template($output);
        $rows = array_column($exported['instructions'], null, 'name');

        $this->assertTrue($exported['hasinstructions']);
        $this->assertArrayNotHasKey('issitebadge', $rows['Own rule']);
        $this->assertStringNotContainsString('tenantid', $exported['addurl']);
        $this->assertStringContainsString('id=' . $own->get('id'), $rows['Own rule']['editurl']);
        $this->assertStringNotContainsString('tenantid', $rows['Own rule']['editurl']);
        $this->assertStringNotContainsString('tenantid', $rows['Own rule']['deleteurl']);

        $html = $output->render($list);
        $this->assertStringContainsString('Own rule', $html);
        $this->assertStringContainsString($rows['Own rule']['editurl'], str_replace('&amp;', '&', $html));
    }

    /**
     * Editing another tenant's row throws.
     */
    public function test_update_of_other_tenant_row_throws(): void {
        $this->resetAfterTest();
        $other = $this->create_other_tenant_instruction('Other rule');
        $tenantid = $this->login_as_new_tenant_admin();

        $this->expectException(\moodle_exception::class);
        system_instruction_service::update((int) $other->get('id'), ['name' => 'Changed', 'content' => ''], $tenantid);
    }

    /**
     * Deleting another tenant's row throws and leaves the row untouched.
     */
    public function test_delete_of_other_tenant_row_throws(): void {
        global $DB;
        $this->resetAfterTest();
        $other = $this->create_other_tenant_instruction('Other rule');
        $tenantid = $this->login_as_new_tenant_admin();

        try {
            system_instruction_service::delete((int) $other->get('id'), $tenantid);
            $this->fail('Deleting another tenant instruction must throw.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(get_string('invalidsysteminstruction', 'local_coursegen'), $e->getMessage());
        }
        $this->assertEquals(0, $DB->get_field('local_coursegen_system_instruction', 'deleted', ['id' => $other->get('id')]));
    }

    /**
     * The form validates name uniqueness within the current tenant only and carries no tenant field.
     */
    public function test_form_validates_uniqueness_per_tenant(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->create_other_tenant_instruction('Policy');
        $tenantid = $this->login_as_new_tenant_admin();
        $own = system_instruction_service::create(['name' => 'Tone', 'content' => ''], $tenantid);

        $form = new system_instruction_form(null, ['tenantid' => $tenantid]);

        // Another tenant already uses "Policy": this tenant may still use it.
        $this->assertSame([], $form->validation(['id' => 0, 'name' => 'Policy', 'content_editor' => []], []));
        // The tenant already uses "Tone": a second one is rejected, the record itself is not.
        $errors = $form->validation(['id' => 0, 'name' => 'Tone', 'content_editor' => []], []);
        $this->assertSame(get_string('systeminstructionnameexists', 'local_coursegen'), $errors['name']);
        $this->assertSame([], $form->validation(['id' => $own->get('id'), 'name' => 'Tone', 'content_editor' => []], []));
        // An empty name is required.
        $this->assertArrayHasKey('name', $form->validation(['id' => 0, 'name' => '  ', 'content_editor' => []], []));

        $PAGE->set_url(new \moodle_url('/local/coursegen/edit_system_instruction.php'));
        ob_start();
        $form->display();
        $html = ob_get_clean();
        $this->assertStringNotContainsString('name="tenantid"', $html);
    }
}
