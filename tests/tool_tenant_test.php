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

/**
 * Tests for the tool_tenant callback exporting the tenant administrator capabilities.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\tool_tenant
 */
final class tool_tenant_test extends \advanced_testcase {
    use \local_coursegen\tests\requires_workplace;

    /** @var string[] Capabilities the tenant administrator must receive. */
    private const EXPECTED = [
        'local/coursegen:createcoursewithai',
        'local/coursegen:managesysteminstructions',
        'local/coursegen:manageimagegeneration',
        'local/coursegen:managetenantsettings',
    ];

    /**
     * Every exported capability is declared by this plugin in db/access.php and allowed.
     */
    public function test_exported_capabilities_are_declared_by_the_plugin(): void {
        global $CFG;

        $capabilities = [];
        require($CFG->dirroot . '/local/coursegen/db/access.php');

        $exported = tool_tenant::get_tenant_admin_capabilities();

        $this->assertSame(self::EXPECTED, array_keys($exported));
        foreach ($exported as $capability => $permission) {
            $this->assertStringStartsWith('local/coursegen:', $capability);
            $this->assertArrayHasKey($capability, $capabilities, $capability . ' must be declared in db/access.php');
            $this->assertSame(CAP_ALLOW, $permission);
        }
    }

    /**
     * The new tenant settings capability is a system-level configuration capability.
     */
    public function test_managetenantsettings_capability_definition(): void {
        $info = get_capability_info('local/coursegen:managetenantsettings');

        $this->assertNotNull($info);
        $this->assertEquals(CONTEXT_SYSTEM, $info->contextlevel);
        $this->assertEquals(RISK_CONFIG, $info->riskbitmask);
    }

    /**
     * After the install/upgrade hook the tenant administrator role holds every exported capability.
     */
    public function test_tenant_admin_role_receives_the_capabilities(): void {
        $this->require_tool_tenant();
        global $DB;
        $this->resetAfterTest();

        \tool_tenant\tenancy::add_plugin_capabilities_to_tenant_admin_role('local_coursegen');

        $roleid = \tool_tenant\manager::get_tenant_admin_role();
        $systemcontext = \context_system::instance();
        $allowed = $DB->get_fieldset_select(
            'role_capabilities',
            'capability',
            'roleid = :roleid AND contextid = :contextid AND permission = :permission',
            ['roleid' => $roleid, 'contextid' => $systemcontext->id, 'permission' => CAP_ALLOW]
        );

        foreach (self::EXPECTED as $capability) {
            $this->assertContains($capability, $allowed);
        }
    }
}
