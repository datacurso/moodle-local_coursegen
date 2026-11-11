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
 * Tests for the per-tenant plugin configuration store.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\tenant_config
 */
final class tenant_config_test extends \advanced_testcase {
    use \local_coursegen\tests\requires_workplace;

    /**
     * Creates a tenant and returns its id.
     *
     * @return int
     */
    private function create_tenant(): int {
        $this->require_tool_tenant();
        return (int) $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_tenant()->id;
    }

    /**
     * Without a tenant value the default is returned; config_plugins is never consulted.
     */
    public function test_falls_back_to_default_ignoring_config_plugins(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenantid = $this->create_tenant();

        $this->assertSame('fallback', tenant_config::get('somesetting', 'fallback', $tenantid));
        $this->assertNull(tenant_config::get('somesetting', null, $tenantid));

        set_config('somesetting', 'sitevalue', 'local_coursegen');
        tenant_config::reset_cache();

        $this->assertSame('fallback', tenant_config::get('somesetting', 'fallback', $tenantid));
    }

    /**
     * A tenant value applies only to that tenant; an empty string counts as set.
     */
    public function test_tenant_values_are_independent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenanta = $this->create_tenant();
        $tenantb = $this->create_tenant();

        tenant_config::set('somesetting', 'tenantvalue', $tenanta);

        $this->assertSame('tenantvalue', tenant_config::get('somesetting', null, $tenanta));
        $this->assertSame('fallback', tenant_config::get('somesetting', 'fallback', $tenantb));

        tenant_config::set('somesetting', '', $tenanta);
        $this->assertSame('', tenant_config::get('somesetting', 'fallback', $tenanta));
    }

    /**
     * Without an explicit tenant id the current user's tenant is used.
     */
    public function test_tenantid_defaults_to_current_tenant(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenantid = $this->create_tenant();
        tenant_config::set('somesetting', 'tenantvalue', $tenantid);

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->get_plugin_generator('tool_tenant')->allocate_user($user->id, $tenantid);
        $this->setUser($user);

        $this->assertSame('tenantvalue', tenant_config::get('somesetting'));
    }

    /**
     * Writes always go to the tenant table, never to config_plugins.
     */
    public function test_set_never_writes_config_plugins(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenantid = $this->create_tenant();

        tenant_config::set('somesetting', 'tenantvalue', $tenantid);

        $this->assertFalse(get_config('local_coursegen', 'somesetting'));
        $this->assertSame(1, $DB->count_records('local_coursegen_tenant_config', ['tenantid' => $tenantid]));
    }

    /**
     * get_raw() exposes only the stored tenant value, null when the tenant has none.
     */
    public function test_get_raw_returns_stored_value_only(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenantid = $this->create_tenant();
        set_config('somesetting', 'sitevalue', 'local_coursegen');

        $this->assertNull(tenant_config::get_raw('somesetting', $tenantid));

        tenant_config::set('somesetting', 'tenantvalue', $tenantid);
        $this->assertSame('tenantvalue', tenant_config::get_raw('somesetting', $tenantid));
    }

    /**
     * Removing a tenant value makes the default apply again.
     */
    public function test_unset_restores_default(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenantid = $this->create_tenant();
        tenant_config::set('somesetting', 'tenantvalue', $tenantid);

        tenant_config::unset('somesetting', $tenantid);

        $this->assertSame('fallback', tenant_config::get('somesetting', 'fallback', $tenantid));
        $this->assertNull(tenant_config::get_raw('somesetting', $tenantid));
    }

    /**
     * get_all() returns the values of one tenant and delete_all() removes only that tenant's rows.
     */
    public function test_get_all_and_delete_all(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenanta = $this->create_tenant();
        $tenantb = $this->create_tenant();
        tenant_config::set('first', 'a1', $tenanta);
        tenant_config::set('second', 'a2', $tenanta);
        tenant_config::set('first', 'b1', $tenantb);

        $this->assertSame(['first' => 'a1', 'second' => 'a2'], tenant_config::get_all($tenanta));
        $this->assertSame(['first' => 'b1'], tenant_config::get_all($tenantb));

        tenant_config::delete_all($tenanta);

        $this->assertSame([], tenant_config::get_all($tenanta));
        $this->assertSame(['first' => 'b1'], tenant_config::get_all($tenantb));
        $this->assertSame('b1', tenant_config::get('first', null, $tenantb));
    }

    /**
     * Setting the same name twice updates the single row, and the table rejects duplicates.
     */
    public function test_unique_tenant_name(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenantid = $this->create_tenant();

        tenant_config::set('somesetting', 'one', $tenantid);
        tenant_config::set('somesetting', 'two', $tenantid);

        $this->assertSame(1, $DB->count_records('local_coursegen_tenant_config', ['tenantid' => $tenantid]));
        $this->assertSame('two', tenant_config::get('somesetting', null, $tenantid));

        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('local_coursegen_tenant_config', (object) [
            'tenantid' => $tenantid,
            'name' => 'somesetting',
            'value' => 'three',
        ]);
    }
}
