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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Tests for the Workplace admin tree registration done in settings.php.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class admin_tree_test extends \advanced_testcase {
    use \local_coursegen\tests\requires_workplace;

    #[\Override]
    protected function tearDown(): void {
        \local_coursegen\admin\external_page::reset_for_testing();
        parent::tearDown();
    }

    /**
     * Creates a tenant (with its course category) and a tenant administrator, and logs in as that administrator.
     *
     * @return int Tenant id.
     */
    private function login_as_new_tenant_admin(): int {
        $this->require_tool_tenant();
        $this->setAdminUser();
        \tool_tenant\tenancy::add_plugin_capabilities_to_tenant_admin_role('local_coursegen');
        $generator = $this->getDataGenerator()->get_plugin_generator('tool_tenant');
        $category = $this->getDataGenerator()->create_category();
        $tenantid = (int) $generator->create_tenant(['categoryid' => $category->id])->id;
        $tenantadmin = $this->getDataGenerator()->create_user();
        $generator->allocate_user($tenantadmin->id, $tenantid);
        (new \tool_tenant\manager())->assign_tenant_admin_roles([$tenantadmin->id], $tenantid);
        $this->setUser($tenantadmin);
        return $tenantid;
    }

    /**
     * A tenant administrator (no site:config) finds every plugin configuration page in the tree and may access it.
     */
    public function test_tenant_admin_can_access_tenant_pages(): void {
        $this->require_tool_wp();
        $this->resetAfterTest();
        $this->login_as_new_tenant_admin();

        $root = admin_get_root(true, false);

        $this->assertNull($root->locate('localplugins'));
        $this->assertInstanceOf(\admin_category::class, $root->locate('local_coursegen'));
        $this->assertNull($root->locate('local_coursegen_tenantsettings'), 'The former tenant settings page is gone.');

        foreach (['local_coursegen_settings', 'local_coursegen_devsettings'] as $section) {
            $page = $root->locate($section);
            $this->assertInstanceOf(\admin_settingpage::class, $page, $section);
            $this->assertTrue($page->check_access(), $section);
        }

        $externalpages = [
            'local_coursegen_addnewcourseai',
            'local_coursegen_manage_image_generation',
            'local_coursegen_manage_system_instructions',
        ];
        foreach ($externalpages as $section) {
            $page = $root->locate($section);
            $this->assertInstanceOf(\tool_wp\admin_externalpage::class, $page, $section);
            $this->assertTrue($page->check_access(), $section);
        }
    }

    /**
     * A site administrator finds the plugin category under "Local plugins" with the settings pages.
     */
    public function test_site_admin_finds_category_under_local_plugins(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $root = admin_get_root(true, false);

        $localplugins = $root->locate('localplugins');
        $this->assertInstanceOf(\admin_category::class, $localplugins);
        $this->assertInstanceOf(\admin_category::class, $localplugins->locate('local_coursegen'));
        foreach (['local_coursegen_settings', 'local_coursegen_devsettings'] as $section) {
            $page = $localplugins->locate($section);
            $this->assertInstanceOf(\admin_settingpage::class, $page, $section);
            $this->assertTrue($page->check_access(), $section);
        }
    }

    /**
     * A regular user finds the settings pages registered but may not access them.
     */
    public function test_regular_user_cannot_access_settings_pages(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $root = admin_get_root(true, false);

        foreach (['local_coursegen_settings', 'local_coursegen_devsettings'] as $section) {
            $page = $root->locate($section);
            $this->assertInstanceOf(\admin_settingpage::class, $page, $section);
            $this->assertFalse($page->check_access(), $section);
        }
    }

    /**
     * The admin search still works and finds the plugin settings for a site administrator.
     */
    public function test_admin_search_finds_plugin_settings(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url(new \moodle_url('/admin/search.php'));

        $found = admin_search_settings_html(get_string('datacurso_service_url', 'local_coursegen'));

        $this->assertStringContainsString('s_local_coursegen_datacurso_service_url', $found);
    }

    /**
     * Saving the development settings page as a tenant administrator (the admin/settings.php path) stores the tenant value.
     */
    public function test_tenant_admin_saves_settings_page_for_own_tenant(): void {
        $this->resetAfterTest();
        $tenantid = $this->login_as_new_tenant_admin();

        $count = admin_write_settings((object) [
            's_local_coursegen_datacurso_service_url' => 'https://tenant.example.com',
        ]);

        $this->assertSame(1, $count);
        $this->assertEmpty(admin_get_root()->errors);
        $this->assertSame(
            'https://tenant.example.com',
            \local_coursegen\local\tenant_config::get_raw('datacurso_service_url', $tenantid)
        );
        $this->assertFalse(get_config('local_coursegen', 'datacurso_service_url'));
    }

    /**
     * Without tool_wp the external pages are core admin pages gated by the plugin capabilities.
     */
    public function test_external_pages_fall_back_to_core_pages_without_workplace(): void {
        $this->resetAfterTest();
        \local_coursegen\admin\external_page::simulate_workplace_unavailable_for_testing();
        $sections = [
            'local_coursegen_addnewcourseai',
            'local_coursegen_manage_image_generation',
            'local_coursegen_manage_system_instructions',
            'local_coursegen_edit_system_instruction',
        ];

        $this->setAdminUser();
        $root = admin_get_root(true, false);
        foreach ($sections as $section) {
            $page = $root->locate($section);
            $this->assertSame(\admin_externalpage::class, get_class($page), $section);
            $this->assertTrue($page->check_access(), $section);
        }

        $this->setUser($this->getDataGenerator()->create_user());
        $root = admin_get_root(true, false);
        foreach ($sections as $section) {
            $this->assertFalse($root->locate($section)->check_access(), $section);
        }
    }
}
