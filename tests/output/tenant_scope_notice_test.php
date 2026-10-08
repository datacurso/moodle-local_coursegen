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

namespace local_coursegen\output;

/**
 * Tests for the read-only notice naming the tenant the configuration pages apply to.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\output\tenant_scope_notice
 */
final class tenant_scope_notice_test extends \advanced_testcase {
    use \local_coursegen\tests\requires_workplace;

    #[\Override]
    protected function tearDown(): void {
        \local_coursegen\local\tenancy::reset_for_testing();
        parent::tearDown();
    }

    /**
     * Creates a tenant with the given name, allocates a new user to it and logs that user in.
     *
     * @param string $name Tenant name.
     * @return int Tenant id.
     */
    private function login_into_new_tenant(string $name): int {
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
     * The notice names the tenant of the current user and offers no tenant selector.
     */
    public function test_names_current_tenant_without_selector(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->login_into_new_tenant('Tenant, Alpha');
        $output = $PAGE->get_renderer('core');

        $notice = new tenant_scope_notice();
        $exported = $notice->export_for_template($output);
        $html = $output->render($notice);

        $expected = get_string('tenantscopenotice', 'local_coursegen', 'Tenant, Alpha');
        $this->assertSame($expected, $exported['message']);
        $this->assertTrue($exported['hasmessage']);
        $this->assertFalse($exported['hasdescription']);
        $this->assertStringContainsString($expected, $html);
        $this->assertStringNotContainsString('<select', $html);
        $this->assertStringNotContainsString('tenantid', $html);
    }

    /**
     * An optional page description is shown together with the notice.
     */
    public function test_optional_description(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->login_into_new_tenant('Beta');
        $output = $PAGE->get_renderer('core');

        $exported = (new tenant_scope_notice('Instructions of this tenant.'))->export_for_template($output);

        $this->assertTrue($exported['hasdescription']);
        $this->assertSame('Instructions of this tenant.', $exported['description']);
        $this->assertSame(get_string('tenantscopenotice', 'local_coursegen', 'Beta'), $exported['message']);
    }

    /**
     * Without tenancy there is no tenant to name: the notice renders nothing.
     */
    public function test_renders_nothing_without_tenancy(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        \local_coursegen\local\tenancy::simulate_unavailable_for_testing();
        $output = $PAGE->get_renderer('core');

        $notice = new tenant_scope_notice();
        $exported = $notice->export_for_template($output);
        $html = $output->render($notice);

        $this->assertFalse($exported['hasmessage']);
        $this->assertFalse($exported['hascontent']);
        $this->assertStringNotContainsString('tenant-scope-notice', $html);
        $this->assertStringNotContainsString('alert', $html);
    }

    /**
     * Without tenancy the page description is still shown, without the tenant notice.
     */
    public function test_description_without_tenancy(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        \local_coursegen\local\tenancy::simulate_unavailable_for_testing();
        $output = $PAGE->get_renderer('core');

        $html = $output->render(new tenant_scope_notice('Instructions of this site.'));

        $this->assertStringContainsString('Instructions of this site.', $html);
        $this->assertStringNotContainsString('alert', $html);
    }
}
