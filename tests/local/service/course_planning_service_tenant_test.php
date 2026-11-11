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

namespace local_coursegen\local\service;

use local_coursegen\local\tenant_config;

/**
 * Tests for per-tenant resolution of the subsections setting in course_planning_service.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\course_planning_service
 */
final class course_planning_service_tenant_test extends \advanced_testcase {
    use \local_coursegen\tests\requires_workplace;

    /**
     * Creates a tenant and a user allocated to it, then returns [tenantid, user].
     *
     * @return array{0:int,1:\stdClass}
     */
    private function create_tenant_with_user(): array {
        $this->require_tool_tenant();
        $generator = $this->getDataGenerator()->get_plugin_generator('tool_tenant');
        $tenantid = (int) $generator->create_tenant()->id;
        $user = $this->getDataGenerator()->create_user();
        $generator->allocate_user($user->id, $tenantid);
        return [$tenantid, $user];
    }

    /**
     * Enables mod_subsection (the feature requires it), skipping when the module is not installed.
     */
    private function require_mod_subsection(): void {
        $installedmods = \core_plugin_manager::instance()->get_installed_plugins('mod');
        if (!array_key_exists('subsection', $installedmods)) {
            $this->markTestSkipped('mod_subsection is not installed on this site.');
        }
        \core\plugininfo\mod::enable_plugin('subsection', 1);
    }

    /**
     * Each tenant decides on subsections independently; config_plugins is ignored.
     */
    public function test_each_tenant_decides_on_subsections(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->require_mod_subsection();

        set_config('enablesubsections', 1, 'local_coursegen');
        [$tenanta, $usera] = $this->create_tenant_with_user();
        [$tenantb, $userb] = $this->create_tenant_with_user();
        [, $userc] = $this->create_tenant_with_user();
        tenant_config::set('enablesubsections', 1, $tenanta);
        tenant_config::set('enablesubsections', 0, $tenantb);

        $this->setUser($usera);
        $this->assertTrue(course_planning_service::subsections_available());

        $this->setUser($userb);
        $this->assertFalse(course_planning_service::subsections_available());

        // A tenant without a value gets the default (disabled), whatever config_plugins holds.
        $this->setUser($userc);
        $this->assertFalse(course_planning_service::subsections_available());
    }
}
