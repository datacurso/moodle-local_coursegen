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

use local_coursegen\external\manage_image_generation;
use local_coursegen\local\tenancy;
use local_coursegen\local\tenant_config;

/**
 * Permission and tenant contract of the image generation management web service.
 *
 * The admin page is gated by local/coursegen:manageimagegeneration, so the
 * save endpoint must accept exactly the same holders: gating the save on
 * moodle/site:config produced a visible-but-rejected page for managers.
 * The settings are always written to the tenant of the calling user: the
 * service accepts no tenant argument.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\manage_image_generation
 * @runTestsInSeparateProcesses
 */
final class manage_image_generation_permissions_test extends \advanced_testcase {
    use \local_coursegen\tests\requires_workplace;

    /**
     * A minimal valid payload for the service.
     *
     * @return array
     */
    private function minimal_payload(): array {
        return [
            'generationmode' => 'manual',
            'overridecourse' => 0,
            'overrideactivity' => 0,
            'activities' => [],
        ];
    }

    /**
     * Calls the service with the minimal payload.
     *
     * @return array
     */
    private function save(): array {
        $payload = $this->minimal_payload();
        return manage_image_generation::execute(
            $payload['overridecourse'],
            $payload['overrideactivity'],
            $payload['generationmode'],
            $payload['activities']
        );
    }

    /**
     * Creates a tenant and a user who administers it, and logs that user in.
     *
     * @return int Tenant id.
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
     * A user holding the plugin management capability can save the policy of their tenant.
     */
    public function test_holder_of_management_capability_can_save(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'imgmanager']);
        assign_capability('local/coursegen:manageimagegeneration', CAP_ALLOW, $roleid, $context->id);
        role_assign($roleid, $user->id, $context->id);
        $this->setUser($user);

        $result = $this->save();

        $this->assertTrue($result['success']);
        $this->assertSame('manual', tenant_config::get('generationmode', null, tenancy::get_tenant_id($user->id)));
    }

    /**
     * A user without the capability is rejected.
     */
    public function test_user_without_capability_is_rejected(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        $this->save();
    }

    /**
     * A tenant administrator saves the settings of their own tenant only; config_plugins is untouched.
     */
    public function test_tenant_admin_saves_own_tenant(): void {
        $this->require_tool_tenant();
        $this->resetAfterTest();
        $this->setAdminUser();
        $othertenantid = (int) $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_tenant()->id;
        $tenantid = $this->login_as_new_tenant_admin();

        $result = $this->save();

        $this->assertTrue($result['success']);
        $this->assertSame('manual', tenant_config::get_raw('generationmode', $tenantid));
        $this->assertNull(tenant_config::get_raw('generationmode', $othertenantid));
        $this->assertFalse(get_config('local_coursegen', 'generationmode'));
    }

    /**
     * A site administrator saves the settings of the tenant they are currently in.
     */
    public function test_site_admin_saves_current_tenant(): void {
        $this->require_tool_tenant();
        $this->resetAfterTest();
        $this->setAdminUser();
        $othertenantid = (int) $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_tenant()->id;

        $this->save();

        $this->assertSame('manual', tenant_config::get_raw('generationmode', tenancy::get_tenant_id()));
        $this->assertNull(tenant_config::get_raw('generationmode', $othertenantid));
        $this->assertFalse(get_config('local_coursegen', 'generationmode'));
    }

    /**
     * The service no longer accepts a tenant argument.
     */
    public function test_tenantid_argument_is_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);
        manage_image_generation::validate_parameters(
            manage_image_generation::execute_parameters(),
            $this->minimal_payload() + ['tenantid' => 0]
        );
    }
}
