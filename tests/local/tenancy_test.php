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

namespace local_coursegen\local;

/**
 * Tests for the Workplace tenancy wrapper.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\tenancy
 */
final class tenancy_test extends \advanced_testcase {
    use \local_coursegen\tests\requires_workplace;

    #[\Override]
    protected function tearDown(): void {
        tenancy::reset_for_testing();
        parent::tearDown();
    }

    /**
     * Returns the tool_tenant data generator.
     *
     * @return \tool_tenant_generator
     */
    private function tenant_generator(): \tool_tenant_generator {
        $this->require_tool_tenant();
        return $this->getDataGenerator()->get_plugin_generator('tool_tenant');
    }

    /**
     * Creates a user allocated to the given tenant.
     *
     * @param int $tenantid Tenant the user belongs to.
     * @return \stdClass
     */
    private function create_tenant_user(int $tenantid): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $this->tenant_generator()->allocate_user($user->id, $tenantid);
        return $user;
    }

    /**
     * The tenant id of an explicitly allocated user is returned.
     */
    public function test_get_tenant_id_returns_tenant_of_user(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $othertenantid = (int) $this->tenant_generator()->create_tenant()->id;
        $user = $this->create_tenant_user($othertenantid);

        $this->assertSame($othertenantid, tenancy::get_tenant_id($user->id));
    }

    /**
     * A user without an explicit allocation belongs to the default tenant.
     */
    public function test_get_tenant_id_returns_default_tenant_when_not_allocated(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->require_tool_tenant();
        $defaulttenantid = \tool_tenant\tenancy::get_default_tenant_id();
        $this->tenant_generator()->create_tenant();
        $user = $this->getDataGenerator()->create_user();

        $this->assertSame($defaulttenantid, tenancy::get_tenant_id($user->id));
    }

    /**
     * Without a user id the current user's tenant is returned.
     */
    public function test_get_tenant_id_defaults_to_current_user(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $othertenantid = (int) $this->tenant_generator()->create_tenant()->id;
        $user = $this->create_tenant_user($othertenantid);
        $this->setUser($user);

        $this->assertSame($othertenantid, tenancy::get_tenant_id());
    }

    /**
     * The tenant category id is returned, null when the tenant has no category or does not exist.
     */
    public function test_get_tenant_categoryid(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $category = $this->getDataGenerator()->create_category();
        $withcategory = (int) $this->tenant_generator()->create_tenant(['categoryid' => $category->id])->id;
        $withoutcategory = (int) $this->tenant_generator()->create_tenant()->id;

        $this->assertSame((int) $category->id, tenancy::get_tenant_categoryid($withcategory));
        $this->assertNull(tenancy::get_tenant_categoryid($withoutcategory));
        $this->assertNull(tenancy::get_tenant_categoryid(999999));
    }

    /**
     * The formatted tenant name is returned, an empty string for an unknown tenant.
     */
    public function test_get_tenant_name(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $tenantid = (int) $this->tenant_generator()->create_tenant(['name' => 'Acme & Learning'])->id;

        $this->assertSame(format_string('Acme & Learning'), tenancy::get_tenant_name($tenantid));
        $this->assertSame('', tenancy::get_tenant_name(999999));
    }

    /**
     * Tenancy is available exactly when tool_tenant is installed.
     */
    public function test_is_available_matches_tool_tenant(): void {
        $this->assertSame(class_exists('\tool_tenant\tenancy'), tenancy::is_available());
    }

    /**
     * Without tool_tenant the site is a single tenant: id 0, no category and no name.
     */
    public function test_unavailable_resolves_single_tenant(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        tenancy::simulate_unavailable_for_testing();

        $this->assertFalse(tenancy::is_available());
        $this->assertSame(0, tenancy::get_tenant_id());
        $this->assertSame(0, tenancy::get_tenant_id((int) $user->id));
        $this->assertSame(0, tenancy::get_default_tenant_id());
        $this->assertNull(tenancy::get_tenant_categoryid(0));
        $this->assertNull(tenancy::get_tenant_name(0));
    }

    /**
     * The test seam is undone by the reset method.
     */
    public function test_reset_for_testing_restores_detection(): void {
        tenancy::simulate_unavailable_for_testing();
        tenancy::reset_for_testing();

        $this->assertSame(class_exists('\tool_tenant\tenancy'), tenancy::is_available());
    }

    /**
     * Without tool_tenant the capabilities are not granted to any tenant administrator role.
     */
    public function test_add_plugin_capabilities_skipped_when_unavailable(): void {
        tenancy::simulate_unavailable_for_testing();

        $this->assertFalse(tenancy::add_plugin_capabilities_to_tenant_admin_role());
    }

    /**
     * With tool_tenant the plugin capabilities are granted to the tenant administrator role.
     */
    public function test_add_plugin_capabilities_granted_when_available(): void {
        global $DB;
        $this->resetAfterTest();
        $this->require_tool_tenant();

        $roleid = \tool_tenant\manager::get_tenant_admin_role();
        $systemcontextid = \context_system::instance()->id;
        unassign_capability('local/coursegen:managetenantsettings', $roleid, $systemcontextid);

        $this->assertTrue(tenancy::add_plugin_capabilities_to_tenant_admin_role());
        $this->assertTrue($DB->record_exists('role_capabilities', [
            'roleid' => $roleid,
            'contextid' => $systemcontextid,
            'capability' => 'local/coursegen:managetenantsettings',
            'permission' => CAP_ALLOW,
        ]));
    }
}
