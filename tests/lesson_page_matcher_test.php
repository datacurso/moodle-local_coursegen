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

use local_coursegen\local\preview\lesson_page_matcher;

/**
 * Tests for the matching of drafted lesson pages to the mould's page rows.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\lesson_page_matcher
 */
final class lesson_page_matcher_test extends \basic_testcase {
    /**
     * A page row as the mould's store holds it.
     *
     * @param int $id
     * @param string $title
     * @param int $prev
     * @param int $next
     * @return \stdClass
     */
    private function row(int $id, string $title, int $prev, int $next): \stdClass {
        return (object) ['id' => $id, 'title' => $title, 'prevpageid' => $prev, 'nextpageid' => $next];
    }

    /**
     * Pages with distinct titles each find their own row.
     */
    public function test_distinct_titles_match_their_rows(): void {
        $rows = [$this->row(1, 'A', 0, 2), $this->row(2, 'B', 1, 0)];
        $pages = [['title' => 'B'], ['title' => 'A']];

        $matched = lesson_page_matcher::match($rows, $pages);

        $this->assertSame([2, 1], array_keys($matched));
    }

    /**
     * Two pages sharing a title each take their own row, in the order the
     * mould is walked, instead of both landing on the first.
     */
    public function test_repeated_titles_take_rows_in_walk_order(): void {
        $rows = [
            $this->row(10, 'Same', 0, 11),
            $this->row(11, 'Same', 10, 0),
        ];
        $pages = [['title' => 'Same', 'content_html' => 'one'], ['title' => 'Same', 'content_html' => 'two']];

        $matched = lesson_page_matcher::match($rows, $pages);

        $this->assertSame([10, 11], array_keys($matched));
        $this->assertSame('one', $matched[10]['content_html']);
        $this->assertSame('two', $matched[11]['content_html']);
    }

    /**
     * The walk follows the chain, not the order the rows happen to be listed in.
     */
    public function test_walk_order_follows_the_chain_not_the_listing(): void {
        $rows = [
            $this->row(11, 'Same', 10, 0),
            $this->row(10, 'Same', 0, 11),
        ];
        $pages = [['title' => 'Same', 'content_html' => 'one'], ['title' => 'Same', 'content_html' => 'two']];

        $matched = lesson_page_matcher::match($rows, $pages);

        $this->assertSame('one', $matched[10]['content_html']);
        $this->assertSame('two', $matched[11]['content_html']);
    }

    /**
     * A page that names its row by id keeps it, whatever its title.
     */
    public function test_explicit_id_wins_over_title(): void {
        $rows = [$this->row(1, 'A', 0, 2), $this->row(2, 'B', 1, 0)];
        $pages = [['id' => 2, 'title' => 'A']];

        $matched = lesson_page_matcher::match($rows, $pages);

        $this->assertSame([2], array_keys($matched));
    }

    /**
     * A page with no row for its title is left out, and a title used more
     * often than the mould has rows for does not reuse a row.
     */
    public function test_unmatched_and_surplus_pages_are_left_out(): void {
        $rows = [$this->row(1, 'A', 0, 0)];
        $pages = [['title' => 'A'], ['title' => 'A'], ['title' => 'Z']];

        $matched = lesson_page_matcher::match($rows, $pages);

        $this->assertSame([1], array_keys($matched));
    }

    /**
     * Titles are compared trimmed, and a broken chain still yields its rows.
     */
    public function test_titles_are_trimmed_and_orphans_are_matched(): void {
        $rows = [$this->row(1, 'A', 0, 0), $this->row(7, ' B ', 5, 6)];
        $pages = [['title' => 'B '], ['title' => ' A']];

        $matched = lesson_page_matcher::match($rows, $pages);

        $this->assertSame([7, 1], array_keys($matched));
    }

    /**
     * No rows, no matches.
     */
    public function test_no_rows_matches_nothing(): void {
        $this->assertSame([], lesson_page_matcher::match([], [['title' => 'A']]));
    }
}
