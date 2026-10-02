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

use local_coursegen\local\preview\drafted_row_matcher;

/**
 * Tests for the matching of drafted pieces to the mould's rows, in the order given.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\drafted_row_matcher
 */
final class drafted_row_matcher_test extends \basic_testcase {
    /**
     * Chapters sharing a title take their rows in the order given, each once.
     */
    public function test_repeated_titles_take_rows_in_the_order_given(): void {
        $rows = [(object) ['id' => 5, 'title' => 'Intro'], (object) ['id' => 3, 'title' => 'Intro']];
        $drafts = [['title' => 'Intro', 'content' => 'one'], ['title' => 'Intro', 'content' => 'two']];

        $matched = drafted_row_matcher::match_ordered($rows, $drafts);

        $this->assertSame([5, 3], array_keys($matched));
        $this->assertSame('two', $matched[3]['content']);
    }

    /**
     * A draft without a title nor an id has no row.
     */
    public function test_draft_without_title_has_no_row(): void {
        $rows = [(object) ['id' => 1, 'title' => 'A']];

        $this->assertSame([], drafted_row_matcher::match_ordered($rows, [['content' => 'x']]));
    }
}
