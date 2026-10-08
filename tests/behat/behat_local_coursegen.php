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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Moodle\BehatExtension\Exception\SkippedException;

/**
 * Behat steps for local_coursegen.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_coursegen extends behat_base {
    /**
     * Skips the scenario when Moodle Workplace multi-tenancy (tool_tenant) is not installed.
     *
     * The CI runs the acceptance tests on plain Moodle LMS, where scenarios that
     * create tenants cannot run.
     *
     * @Given /^Moodle Workplace multi-tenancy is available$/
     * @return void
     * @throws SkippedException
     */
    public function moodle_workplace_multi_tenancy_is_available(): void {
        if (!class_exists('\tool_tenant\tenancy')) {
            throw new SkippedException('Requires Moodle Workplace (tool_tenant).');
        }
    }
}
