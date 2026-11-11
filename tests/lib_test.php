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
require_once($CFG->dirroot . '/local/coursegen/lib.php');

/**
 * Tests for the Workplace launcher callback in lib.php.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class lib_test extends \advanced_testcase {
    /**
     * Skip the test when the Datacurso AI provider, whose enable gate the tested code calls, is not installed.
     *
     * The CI pipeline does not install declared dependencies.
     */
    private function require_datacurso_provider(): void {
        if (!class_exists(\aiprovider_datacurso\local\plugin_settings::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed.');
        }
    }

    /**
     * A user allowed to create courses with AI gets the launcher entry.
     */
    public function test_workplace_menu_items_for_allowed_user(): void {
        $this->require_datacurso_provider();
        $this->resetAfterTest();
        $this->setAdminUser();

        $items = local_coursegen_theme_workplace_menu_items();

        $this->assertCount(1, $items);
        $item = reset($items);
        $this->assertInstanceOf(\moodle_url::class, $item['url']);
        $this->assertStringEndsWith('/local/coursegen/aicoursecreation.php', $item['url']->out(false));
        $this->assertSame(get_string('createwithai', 'local_coursegen'), $item['name']);
        $this->assertStringContainsString('local_coursegen', $item['imageurl']);
        $this->assertArrayNotHasKey('isglobal', $item);
    }

    /**
     * A user who cannot create courses with AI gets no launcher entry.
     */
    public function test_workplace_menu_items_for_denied_user(): void {
        $this->require_datacurso_provider();
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertSame([], local_coursegen_theme_workplace_menu_items());
    }

    /**
     * While the plugin is disabled in the Datacurso AI provider not even an admin gets the launcher entry.
     *
     * The enabled path is test_workplace_menu_items_for_allowed_user().
     *
     * @return void
     */
    public function test_workplace_menu_items_when_plugin_disabled(): void {
        $this->require_datacurso_provider();
        $this->resetAfterTest();
        $this->setAdminUser();
        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_coursegen', false);

        $this->assertSame([], local_coursegen_theme_workplace_menu_items());
    }

    /**
     * The provider switch is per tenant: tenant A disabled hides the entry only for tenant A.
     *
     * @return void
     */
    public function test_workplace_menu_items_follow_the_tenant_switch(): void {
        $this->require_datacurso_provider();
        if (!class_exists('\tool_tenant\tenancy')) {
            $this->markTestSkipped('tool_tenant is not installed.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();
        \tool_tenant\tenancy::add_plugin_capabilities_to_tenant_admin_role('local_coursegen');

        $admina = $this->create_tenant_admin();
        $adminb = $this->create_tenant_admin();
        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_coursegen', false, $admina['tenantid']);

        $this->setUser($admina['userid']);
        $this->assertSame([], local_coursegen_theme_workplace_menu_items());

        $this->setUser($adminb['userid']);
        $this->assertCount(1, local_coursegen_theme_workplace_menu_items());
    }

    /**
     * Creates a tenant (with its course category) and its tenant administrator.
     *
     * @return int[] With keys tenantid and userid.
     */
    private function create_tenant_admin(): array {
        $generator = $this->getDataGenerator()->get_plugin_generator('tool_tenant');
        $category = $this->getDataGenerator()->create_category();
        $tenantid = (int) $generator->create_tenant(['categoryid' => $category->id])->id;
        $user = $this->getDataGenerator()->create_user();
        $generator->allocate_user($user->id, $tenantid);
        (new \tool_tenant\manager())->assign_tenant_admin_roles([$user->id], $tenantid);
        return ['tenantid' => $tenantid, 'userid' => (int) $user->id];
    }
}
