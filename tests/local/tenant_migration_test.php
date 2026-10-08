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
 * Tests for the upgrade migration of the former site-wide data to the Workplace default tenant.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\tenant_migration
 */
final class tenant_migration_test extends \advanced_testcase {
    use \local_coursegen\tests\requires_workplace;

    #[\Override]
    protected function tearDown(): void {
        tenancy::reset_for_testing();
        parent::tearDown();
    }

    /**
     * Inserts a system instruction row directly, as the former site rows were stored.
     *
     * @param string $name Instruction name.
     * @param int $tenantid Owning tenant id (0 for a former site instruction).
     * @return int Instruction id.
     */
    private function insert_instruction(string $name, int $tenantid): int {
        global $DB;

        return (int) $DB->insert_record('local_coursegen_system_instruction', (object) [
            'name' => $name,
            'content' => '',
            'deleted' => 0,
            'tenantid' => $tenantid,
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => 2,
        ]);
    }

    /**
     * Tenant-scoped settings move from config_plugins to the default tenant and are removed from config_plugins.
     */
    public function test_moves_tenant_settings_to_default_tenant(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->require_tool_tenant();
        $defaulttenantid = \tool_tenant\tenancy::get_default_tenant_id();

        $settings = [
            'enablesubsections' => '1',
            'datacurso_service_url' => 'https://us.example.com',
            'datacurso_service_url_eu' => 'https://eu.example.com',
            'generationmode' => 'manual',
            'overridecourse' => '1',
            'overrideactivity' => '0',
            'enableimgassign' => '1',
            'enableimgassign_intro' => '1',
            'maximgassign_intro' => '3',
            'enableimgretiredmodule' => '1',
        ];
        foreach ($settings as $name => $value) {
            set_config($name, $value, 'local_coursegen');
        }

        tenant_migration::migrate_site_data_to_default_tenant();

        $migrated = array_intersect_key(tenant_config::get_all($defaulttenantid), $settings);
        ksort($settings);
        ksort($migrated);
        $this->assertSame($settings, $migrated);
        foreach (array_keys($settings) as $name) {
            $this->assertFalse(get_config('local_coursegen', $name), $name);
        }
    }

    /**
     * Keys that are not tenant settings (such as the plugin version) stay in config_plugins.
     */
    public function test_leaves_non_setting_keys_untouched(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->require_tool_tenant();
        $defaulttenantid = \tool_tenant\tenancy::get_default_tenant_id();
        $version = get_config('local_coursegen', 'version');
        set_config('someinternalflag', 'x', 'local_coursegen');

        tenant_migration::migrate_site_data_to_default_tenant();

        $this->assertSame($version, get_config('local_coursegen', 'version'));
        $this->assertSame('x', get_config('local_coursegen', 'someinternalflag'));
        $this->assertArrayNotHasKey('version', tenant_config::get_all($defaulttenantid));
        $this->assertArrayNotHasKey('someinternalflag', tenant_config::get_all($defaulttenantid));
    }

    /**
     * A value the default tenant already stores wins over the former site value, which is still removed.
     */
    public function test_existing_default_tenant_value_wins(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->require_tool_tenant();
        $defaulttenantid = \tool_tenant\tenancy::get_default_tenant_id();
        tenant_config::set('datacurso_service_url', 'https://tenant.example.com', $defaulttenantid);
        set_config('datacurso_service_url', 'https://site.example.com', 'local_coursegen');

        tenant_migration::migrate_site_data_to_default_tenant();

        $this->assertSame('https://tenant.example.com', tenant_config::get_raw('datacurso_service_url', $defaulttenantid));
        $this->assertFalse(get_config('local_coursegen', 'datacurso_service_url'));
    }

    /**
     * Former site instructions (tenant 0) move to the default tenant; other tenants keep theirs.
     */
    public function test_moves_site_instructions_to_default_tenant(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->require_tool_tenant();
        $defaulttenantid = \tool_tenant\tenancy::get_default_tenant_id();
        $othertenantid = (int) $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_tenant()->id;

        $siteid = $this->insert_instruction('Site rule', 0);
        $otherid = $this->insert_instruction('Other rule', $othertenantid);

        tenant_migration::migrate_site_data_to_default_tenant();

        $this->assertEquals($defaulttenantid, $DB->get_field('local_coursegen_system_instruction', 'tenantid', ['id' => $siteid]));
        $this->assertEquals($othertenantid, $DB->get_field('local_coursegen_system_instruction', 'tenantid', ['id' => $otherid]));
        $this->assertSame(0, $DB->count_records('local_coursegen_system_instruction', ['tenantid' => 0]));
    }

    /**
     * Running the migration twice leaves the same result.
     */
    public function test_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->require_tool_tenant();
        $defaulttenantid = \tool_tenant\tenancy::get_default_tenant_id();
        set_config('generationmode', 'auto', 'local_coursegen');
        $siteid = $this->insert_instruction('Site rule', 0);

        tenant_migration::migrate_site_data_to_default_tenant();
        $afterfirst = tenant_config::get_all($defaulttenantid);
        tenant_migration::migrate_site_data_to_default_tenant();

        $this->assertSame($afterfirst, tenant_config::get_all($defaulttenantid));
        $this->assertSame('auto', tenant_config::get_raw('generationmode', $defaulttenantid));
        $this->assertSame(1, $DB->count_records('local_coursegen_tenant_config', [
            'tenantid' => $defaulttenantid,
            'name' => 'generationmode',
        ]));
        $this->assertEquals($defaulttenantid, $DB->get_field('local_coursegen_system_instruction', 'tenantid', ['id' => $siteid]));
    }

    /**
     * Without tenancy the site data stays with the single implicit tenant 0.
     */
    public function test_targets_tenant_zero_without_tenancy(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        tenancy::simulate_unavailable_for_testing();
        set_config('datacurso_service_url', 'https://site.example.com', 'local_coursegen');
        $siteid = $this->insert_instruction('Site rule', 0);

        tenant_migration::migrate_site_data_to_default_tenant();

        $this->assertSame('https://site.example.com', tenant_config::get_raw('datacurso_service_url', 0));
        $this->assertFalse(get_config('local_coursegen', 'datacurso_service_url'));
        $this->assertEquals(0, $DB->get_field('local_coursegen_system_instruction', 'tenantid', ['id' => $siteid]));
    }
}
