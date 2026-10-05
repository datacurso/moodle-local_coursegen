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

namespace local_coursegen\local\space;

/**
 * Tests for space_scope.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\space\space_scope
 */
final class space_scope_test extends \advanced_testcase {
    protected function tearDown(): void {
        space_scope::leave();
        parent::tearDown();
    }

    /**
     * Nothing is in scope until a selection enters.
     */
    public function test_nothing_in_scope_by_default(): void {
        $this->assertNull(space_scope::current());
    }

    /**
     * The selection that entered is the one in scope until it leaves.
     */
    public function test_the_entered_selection_is_current_until_it_leaves(): void {
        $selection = new space_selection([], []);

        space_scope::enter($selection);
        $this->assertSame($selection, space_scope::current());

        space_scope::leave();
        $this->assertNull(space_scope::current());
    }

    /**
     * A second selection cannot enter on top of the first.
     */
    public function test_a_second_selection_cannot_enter(): void {
        space_scope::enter(new space_selection([], []));

        $this->expectException(\coding_exception::class);
        space_scope::enter(new space_selection([], []));
    }
}
