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
     * A user allowed to create courses with AI gets the launcher entry.
     */
    public function test_workplace_menu_items_for_allowed_user(): void {
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
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertSame([], local_coursegen_theme_workplace_menu_items());
    }
}
