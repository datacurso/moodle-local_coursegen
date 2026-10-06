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
    /**
     * Returns the tool_tenant data generator.
     *
     * @return \tool_tenant_generator
     */
    private function tenant_generator(): \tool_tenant_generator {
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
}
