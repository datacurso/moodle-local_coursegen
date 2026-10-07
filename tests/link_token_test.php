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

use local_coursegen\local\link\link_token;

/**
 * The activity link token: what it replaces and what it never touches.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\link\link_token
 */
final class link_token_test extends \basic_testcase {
    /**
     * A token that is the whole value of an href is replaced by the URL.
     */
    public function test_replaces_a_token_that_is_an_href_value(): void {
        $text = '<p><a href="$@COURSEGENLINK*uid-1@$">Go</a></p>';

        $result = link_token::replace($text, ['uid-1' => 'https://example.com/mod/page/view.php?id=5']);

        $this->assertSame('<p><a href="https://example.com/mod/page/view.php?id=5">Go</a></p>', $result);
    }

    /**
     * A token that is the whole value of a src is replaced, with single quotes too.
     */
    public function test_replaces_a_token_that_is_a_src_value_in_single_quotes(): void {
        $text = "<img src='\$@COURSEGENLINK*uid-1@\$'>";

        $result = link_token::replace($text, ['uid-1' => 'https://example.com/a']);

        $this->assertSame("<img src='https://example.com/a'>", $result);
    }

    /**
     * A src takes the URL meant for embedding when the uid has one, and an href keeps the page URL.
     */
    public function test_a_src_takes_the_embed_url_and_an_href_keeps_the_page_url(): void {
        $text = '<a href="$@COURSEGENLINK*uid-1@$">Go</a><iframe src="$@COURSEGENLINK*uid-1@$"></iframe>';

        $result = link_token::replace($text, ['uid-1' => 'https://example.com/view'], ['uid-1' => 'https://example.com/file.pdf']);

        $expected = '<a href="https://example.com/view">Go</a><iframe src="https://example.com/file.pdf"></iframe>';
        $this->assertSame($expected, $result);
    }

    /**
     * A src whose uid has no embed URL falls back to the page URL, as before.
     */
    public function test_a_src_without_an_embed_url_falls_back_to_the_page_url(): void {
        $text = '<iframe src="$@COURSEGENLINK*uid-1@$"></iframe>';

        $result = link_token::replace($text, ['uid-1' => 'https://example.com/view'], ['uid-2' => 'https://example.com/other']);

        $this->assertSame('<iframe src="https://example.com/view"></iframe>', $result);
    }

    /**
     * The URL lands in an attribute, so quotes and ampersands are escaped.
     */
    public function test_escapes_the_url_for_the_attribute_context(): void {
        $text = '<a href="$@COURSEGENLINK*uid-1@$">x</a>';

        $result = link_token::replace($text, ['uid-1' => 'https://example.com/a?x=1&y="2"']);

        $this->assertSame('<a href="https://example.com/a?x=1&amp;y=&quot;2&quot;">x</a>', $result);
    }

    /**
     * Every occurrence is replaced, each by the URL of its own uid.
     */
    public function test_replaces_every_occurrence_with_its_own_url(): void {
        $text = '<a href="$@COURSEGENLINK*a@$">1</a><a href="$@COURSEGENLINK*b@$">2</a><a href="$@COURSEGENLINK*a@$">3</a>';

        $result = link_token::replace($text, ['a' => 'https://x/a', 'b' => 'https://x/b']);

        $this->assertSame('<a href="https://x/a">1</a><a href="https://x/b">2</a><a href="https://x/a">3</a>', $result);
    }

    /**
     * A uid that is not in the map stays in place, to be reported.
     */
    public function test_leaves_a_token_with_an_unknown_uid_in_place(): void {
        $text = '<a href="$@COURSEGENLINK*other@$">x</a>';

        $result = link_token::replace($text, ['uid-1' => 'https://x/a']);

        $this->assertSame($text, $result);
    }

    /**
     * Other $@...@$ forms are never decoded.
     */
    public function test_leaves_other_dollar_at_forms_untouched(): void {
        $text = '<a href="$@SOMETHINGELSE*uid-1@$">x</a> $@COURSEGENIMG*uid-1@$';

        $result = link_token::replace($text, ['uid-1' => 'https://x/a']);

        $this->assertSame($text, $result);
        $this->assertNull(link_token::first_remaining_uid($text));
    }

    /**
     * A token outside an href or src value is not replaced.
     */
    public function test_does_not_replace_a_token_outside_an_attribute_value(): void {
        $text = '<p>See $@COURSEGENLINK*uid-1@$ and <a title="$@COURSEGENLINK*uid-1@$">x</a></p>';

        $result = link_token::replace($text, ['uid-1' => 'https://x/a']);

        $this->assertSame($text, $result);
    }

    /**
     * A token that is only part of an attribute value is not replaced.
     */
    public function test_does_not_replace_a_token_that_is_part_of_a_longer_value(): void {
        $text = '<a href="https://x/$@COURSEGENLINK*uid-1@$">x</a>';

        $result = link_token::replace($text, ['uid-1' => 'https://x/a']);

        $this->assertSame($text, $result);
    }

    /**
     * An attribute that only ends in href is not an href.
     */
    public function test_does_not_replace_a_token_in_a_look_alike_attribute(): void {
        $text = '<a data-href="$@COURSEGENLINK*uid-1@$">x</a>';

        $result = link_token::replace($text, ['uid-1' => 'https://x/a']);

        $this->assertSame($text, $result);
    }

    /**
     * The first token still present is reported by its uid.
     */
    public function test_first_remaining_uid_names_the_leftover_token(): void {
        $text = '<a href="x">1</a> $@COURSEGENLINK*left-over@$ $@COURSEGENLINK*second@$';

        $this->assertSame('left-over', link_token::first_remaining_uid($text));
    }

    /**
     * A broken token is reported too, with what follows the prefix.
     */
    public function test_first_remaining_uid_reports_a_malformed_token(): void {
        $this->assertSame('', link_token::first_remaining_uid('<a href="$@COURSEGENLINK*">x</a>'));
    }

    /**
     * Text with no token reports none.
     */
    public function test_first_remaining_uid_is_null_without_a_token(): void {
        $this->assertNull(link_token::first_remaining_uid('<p>No links</p>'));
    }
}
