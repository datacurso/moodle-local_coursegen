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

use local_coursegen\local\ai_activity_types;

/**
 * The activity types a template works with: every AI-supported module that is
 * installed and enabled, for every template alike.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\ai_activity_types
 * @covers     \local_coursegen\external\get_template_structure
 *
 * @runTestsInSeparateProcesses
 */
final class ai_activity_types_test extends \advanced_testcase {
    /**
     * Only modules the AI service has a contract for are listed.
     */
    public function test_installed_lists_only_ai_supported_modules(): void {
        $this->resetAfterTest();

        $installed = ai_activity_types::installed();

        $this->assertNotEmpty($installed);
        $unsupported = array_diff($installed, ai_activity_types::MODNAMES);
        $this->assertSame([], $unsupported);
        $this->assertContains('page', $installed);
        $this->assertContains('forum', $installed);
    }

    /**
     * The list is sorted, so the professor-side catalog is stable.
     */
    public function test_installed_is_sorted(): void {
        $this->resetAfterTest();

        $installed = ai_activity_types::installed();
        $sorted = $installed;
        sort($sorted);

        $this->assertSame($sorted, $installed);
    }

    /**
     * The supported list is written in alphabetical order, because the
     * installed list follows it instead of sorting again.
     */
    public function test_the_supported_list_is_kept_alphabetical(): void {
        $supported = ai_activity_types::MODNAMES;
        $sorted = $supported;
        sort($sorted);

        $this->assertSame($sorted, $supported);
    }

    /**
     * Every supported module is listed once, and only once.
     */
    public function test_installed_lists_each_module_once(): void {
        $this->resetAfterTest();

        $installed = ai_activity_types::installed();

        $unique = array_unique($installed);
        $expected = array_values($unique);
        $this->assertSame($expected, $installed);
    }

    /**
     * A module an administrator has hidden is not available on the site, so
     * it is not listed even though the AI service supports it.
     */
    public function test_a_hidden_module_is_not_installed(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();

        $DB->set_field('modules', 'visible', 0, ['name' => 'wiki']);
        get_module_types_names(false, true);
        $installed = ai_activity_types::installed();

        $this->assertNotContains('wiki', $installed);
        $this->assertContains('page', $installed);
    }
}
