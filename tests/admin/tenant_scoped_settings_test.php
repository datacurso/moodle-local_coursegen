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

namespace local_coursegen\admin;

use local_coursegen\local\tenancy;
use local_coursegen\local\tenant_config;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Tests for the admin settings that read and write the configuration of the current tenant.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\admin\tenant_scoped_setting
 * @covers     \local_coursegen\admin\setting_https_url
 * @covers     \local_coursegen\admin\setting_enablesubsections
 * @covers     \local_coursegen\admin\setting_tenant_scope_notice
 */
final class tenant_scoped_settings_test extends \advanced_testcase {
    use \local_coursegen\tests\requires_workplace;

    #[\Override]
    protected function tearDown(): void {
        tenancy::reset_for_testing();
        parent::tearDown();
    }

    /**
     * Creates a tenant with a user allocated to it and logs in as that user.
     *
     * @param string $name Tenant name.
     * @return int Tenant id.
     */
    private function login_into_new_tenant(string $name = 'Tenant'): int {
        $this->require_tool_tenant();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('tool_tenant');
        $tenantid = (int) $generator->create_tenant(['name' => $name])->id;
        $user = $this->getDataGenerator()->create_user();
        $generator->allocate_user($user->id, $tenantid);
        $this->setUser($user);
        return $tenantid;
    }

    /**
     * Enables or disables mod_subsection, skipping the test when it is not installed.
     *
     * @param bool $enabled Whether the module should be enabled.
     * @return void
     */
    private function set_mod_subsection_enabled(bool $enabled): void {
        $installedmods = \core_plugin_manager::instance()->get_installed_plugins('mod');
        if (!array_key_exists('subsection', $installedmods)) {
            $this->markTestSkipped('mod_subsection is not installed on this site.');
        }
        \core\plugininfo\mod::enable_plugin('subsection', $enabled ? 1 : 0);
    }

    /**
     * Builds the service URL setting as settings.php does.
     *
     * @return setting_https_url
     */
    private function make_url_setting(): setting_https_url {
        return new setting_https_url('local_coursegen/datacurso_service_url', 'Service URL', '', '', PARAM_URL);
    }

    /**
     * Builds the subsections checkbox as settings.php does.
     *
     * @return setting_enablesubsections
     */
    private function make_subsections_setting(): setting_enablesubsections {
        return new setting_enablesubsections('local_coursegen/enablesubsections', 'Subsections', '', 0);
    }

    /**
     * A tenant without a stored value reads the default, never config_plugins, and is never reported as a new setting.
     */
    public function test_get_setting_returns_default_when_tenant_has_no_value(): void {
        $this->resetAfterTest();
        $this->login_into_new_tenant();
        set_config('datacurso_service_url', 'https://site.example.com', 'local_coursegen');
        set_config('enablesubsections', '1', 'local_coursegen');

        $this->assertSame('', $this->make_url_setting()->get_setting());
        $this->assertSame('0', $this->make_subsections_setting()->get_setting());
    }

    /**
     * Saving writes the current tenant's configuration only; other tenants and config_plugins are untouched.
     */
    public function test_write_setting_stores_value_for_current_tenant_only(): void {
        $this->resetAfterTest();
        $othertenantid = $this->login_into_new_tenant('Other');
        $tenantid = $this->login_into_new_tenant('Mine');

        $setting = $this->make_url_setting();
        $this->assertSame('', $setting->write_setting('https://tenant.example.com'));

        $this->assertSame('https://tenant.example.com', $setting->get_setting());
        $this->assertSame('https://tenant.example.com', tenant_config::get_raw('datacurso_service_url', $tenantid));
        $this->assertNull(tenant_config::get_raw('datacurso_service_url', $othertenantid));
        $this->assertFalse(get_config('local_coursegen', 'datacurso_service_url'));
    }

    /**
     * Saving the default while the tenant has no value stores nothing (as on install, when defaults are applied).
     */
    public function test_write_setting_skips_default_when_tenant_has_no_value(): void {
        global $DB;
        $this->resetAfterTest();
        $tenantid = $this->login_into_new_tenant();

        $this->assertSame('', $this->make_url_setting()->write_setting(''));
        $this->assertSame('', $this->make_subsections_setting()->write_setting('0'));

        $this->assertSame(0, $DB->count_records('local_coursegen_tenant_config', ['tenantid' => $tenantid]));
    }

    /**
     * Admin defaults applied on install or upgrade never write tenant rows nor config_plugins.
     */
    public function test_apply_default_settings_writes_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $before = $DB->count_records('local_coursegen_tenant_config');

        admin_apply_default_settings(admin_get_root(true, true)->locate('local_coursegen'), true);

        $this->assertSame($before, $DB->count_records('local_coursegen_tenant_config'));
        $this->assertFalse(get_config('local_coursegen', 'enablesubsections'));
        $this->assertFalse(get_config('local_coursegen', 'datacurso_service_url'));
    }

    /**
     * The HTTPS policy still rejects remote plain HTTP URLs.
     */
    public function test_url_setting_keeps_https_validation(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->login_into_new_tenant();
        $CFG->debugdeveloper = false;

        $this->assertIsString($this->make_url_setting()->validate('http://remote.example.com'));
    }

    /**
     * Enabling subsections stores it for the tenant while mod_subsection is enabled.
     */
    public function test_enablesubsections_saved_for_tenant_when_module_enabled(): void {
        $this->resetAfterTest();
        $this->set_mod_subsection_enabled(true);
        $tenantid = $this->login_into_new_tenant();

        $setting = $this->make_subsections_setting();
        $this->assertSame('', $setting->write_setting('1'));

        $this->assertSame('1', $setting->get_setting());
        $this->assertSame('1', tenant_config::get_raw('enablesubsections', $tenantid));
        $this->assertFalse(get_config('local_coursegen', 'enablesubsections'));
    }

    /**
     * Enabling subsections while mod_subsection is disabled stores it as disabled and reports why.
     */
    public function test_enablesubsections_forced_off_when_module_disabled(): void {
        $this->resetAfterTest();
        $this->set_mod_subsection_enabled(true);
        $tenantid = $this->login_into_new_tenant();
        $setting = $this->make_subsections_setting();
        $setting->write_setting('1');
        $this->set_mod_subsection_enabled(false);

        $this->assertSame('', $setting->write_setting('1'));

        $this->assertSame('0', tenant_config::get_raw('enablesubsections', $tenantid));
        $messages = array_map(fn($n) => $n->get_message(), \core\notification::fetch());
        $this->assertContains(get_string('enablesubsections_error_moddisabled', 'local_coursegen'), $messages);
    }

    /**
     * The scope notice names the current tenant.
     */
    public function test_scope_notice_names_current_tenant(): void {
        $this->resetAfterTest();
        $this->login_into_new_tenant('Acme');

        $notice = new setting_tenant_scope_notice('local_coursegen/tenantscopenotice');
        $html = $notice->output_html($notice->get_setting());

        $this->assertStringContainsString(get_string('tenantscopenotice', 'local_coursegen', 'Acme'), $html);
        $this->assertTrue($notice->get_setting());
        $this->assertSame('', $notice->write_setting('anything'));
    }

    /**
     * Without tenancy the settings scope notice renders nothing.
     */
    public function test_scope_notice_hidden_without_tenancy(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        tenancy::simulate_unavailable_for_testing();

        $notice = new setting_tenant_scope_notice('local_coursegen/tenantscopenotice');

        $this->assertSame('', $notice->output_html($notice->get_setting()));
    }
}
