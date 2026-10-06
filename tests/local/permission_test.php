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
 * Tests for the AI course creation permission helper.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\permission
 */
final class permission_test extends \advanced_testcase {
    /**
     * Creates a user holding the given capabilities through a new role in the given context.
     *
     * @param string[] $capabilities Capability names to allow.
     * @param \context $context Context of the role assignment.
     * @return \stdClass
     */
    private function create_user_with_capabilities(array $capabilities, \context $context): \stdClass {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $roleid = $generator->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        }
        role_assign($roleid, $user->id, $context->id);
        return $user;
    }

    /**
     * Both capabilities at system level allow creating courses with AI.
     */
    public function test_system_capabilities_allow(): void {
        $this->resetAfterTest();
        $user = $this->create_user_with_capabilities(
            ['moodle/course:create', 'local/coursegen:createcoursewithai'],
            \context_system::instance()
        );
        $this->setUser($user);

        $this->assertTrue(permission::can_create_course_with_ai());
        permission::require_create_course_with_ai();
    }

    /**
     * Both capabilities granted in a single category only are enough.
     */
    public function test_category_level_capabilities_allow(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $user = $this->create_user_with_capabilities(
            ['moodle/course:create', 'local/coursegen:createcoursewithai'],
            \context_coursecat::instance($category->id)
        );
        $this->setUser($user);

        $this->assertTrue(permission::can_create_course_with_ai());
    }

    /**
     * A Workplace tenant administrator (role granted by tool_tenant) may create courses with AI.
     */
    public function test_tenant_admin_allowed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        \tool_tenant\tenancy::add_plugin_capabilities_to_tenant_admin_role('local_coursegen');
        $category = $this->getDataGenerator()->create_category();
        $tenantgenerator = $this->getDataGenerator()->get_plugin_generator('tool_tenant');
        $tenantid = (int) $tenantgenerator->create_tenant(['categoryid' => $category->id])->id;
        $tenantadmin = $this->getDataGenerator()->create_user();
        $tenantgenerator->allocate_user($tenantadmin->id, $tenantid);
        (new \tool_tenant\manager())->assign_tenant_admin_roles([$tenantadmin->id], $tenantid);
        $this->setUser($tenantadmin);

        $this->assertTrue(permission::can_create_course_with_ai());
        permission::require_create_course_with_ai();
    }

    /**
     * The AI capability alone, without moodle/course:create anywhere, denies.
     */
    public function test_without_course_create_denies(): void {
        $this->resetAfterTest();
        $user = $this->create_user_with_capabilities(
            ['local/coursegen:createcoursewithai'],
            \context_system::instance()
        );
        $this->setUser($user);

        $this->assertFalse(permission::can_create_course_with_ai());
        $this->expectException(\required_capability_exception::class);
        permission::require_create_course_with_ai();
    }

    /**
     * moodle/course:create alone, without the AI capability anywhere, denies.
     */
    public function test_without_createcoursewithai_denies(): void {
        $this->resetAfterTest();
        $user = $this->create_user_with_capabilities(['moodle/course:create'], \context_system::instance());
        $this->setUser($user);

        $this->assertFalse(permission::can_create_course_with_ai());
    }

    /**
     * Guests are always denied.
     */
    public function test_guest_denied(): void {
        $this->resetAfterTest();
        $this->setGuestUser();

        $this->assertFalse(permission::can_create_course_with_ai());
        $this->expectException(\required_capability_exception::class);
        permission::require_create_course_with_ai();
    }

    /**
     * Another user can be checked explicitly, with the same category-aware rules.
     */
    public function test_checks_other_user_by_id(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $allowed = $this->create_user_with_capabilities(
            ['moodle/course:create', 'local/coursegen:createcoursewithai'],
            \context_coursecat::instance($category->id)
        );
        $denied = $this->getDataGenerator()->create_user();

        $this->assertTrue(permission::can_create_course_with_ai((int) $allowed->id));
        $this->assertFalse(permission::can_create_course_with_ai((int) $denied->id));
    }
}
