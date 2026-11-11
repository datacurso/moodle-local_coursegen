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

namespace local_coursegen\tests;

/**
 * Skips the tests that need the Moodle Workplace plugins.
 *
 * The plugin declares Workplace as a dependency, but the CI runs the suite on
 * plain Moodle LMS, where tool_tenant and tool_wp do not exist. Tests that
 * create tenants or use the Workplace APIs call these helpers first. They check
 * the real classes, never the test seams of the plugin.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait requires_workplace {
    /**
     * Skips the current test when Moodle Workplace multi-tenancy (tool_tenant) is not installed.
     *
     * @return void
     */
    protected function require_tool_tenant(): void {
        if (!class_exists('\tool_tenant\tenancy')) {
            $this->markTestSkipped('Requires Moodle Workplace (tool_tenant).');
        }
    }

    /**
     * Skips the current test when the Moodle Workplace admin pages (tool_wp) are not installed.
     *
     * @return void
     */
    protected function require_tool_wp(): void {
        if (!class_exists('\tool_wp\admin_externalpage')) {
            $this->markTestSkipped('Requires Moodle Workplace (tool_wp).');
        }
    }
}
