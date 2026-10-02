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

use local_coursegen\local\reference\reference_file_token;

/**
 * The reference token: what it replaces and what it never touches.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\reference\reference_file_token
 */
final class reference_file_token_test extends \basic_testcase {
    /**
     * The attributes that hold a file, each replaced when the token is the whole value.
     *
     * @return array
     */
    public static function attribute_provider(): array {
        return [
            'src' => ['iframe', 'src'],
            'href' => ['a', 'href'],
            'data' => ['object', 'data'],
            'poster' => ['video', 'poster'],
        ];
    }

    /**
     * A token that is the whole value of a file attribute becomes the address of the file.
     *
     * @dataProvider attribute_provider
     * @param string $tag
     * @param string $attribute
     */
    public function test_a_token_that_is_an_attribute_value_is_replaced(string $tag, string $attribute): void {
        $token = new reference_file_token(['uid-1.1' => 'https://example.com/f/guide.pdf']);
        $text = '<' . $tag . ' ' . $attribute . '="$@COURSEGENFILE*uid-1.1@$"></' . $tag . '>';

        $result = $token->replace($text);

        $this->assertSame('<' . $tag . ' ' . $attribute . '="https://example.com/f/guide.pdf"></' . $tag . '>', $result);
    }

    /**
     * Single quotes work too.
     */
    public function test_single_quotes_are_kept(): void {
        $token = new reference_file_token(['uid-1.2' => 'https://example.com/a.png']);

        $result = $token->replace("<img src='\$@COURSEGENFILE*uid-1.2@\$'>");

        $this->assertSame("<img src='https://example.com/a.png'>", $result);
    }

    /**
     * The address is escaped for the attribute it lands in.
     */
    public function test_the_address_is_escaped(): void {
        $token = new reference_file_token(['uid-1.1' => 'https://example.com/a.pdf?x=1&y="2"']);

        $result = $token->replace('<a href="$@COURSEGENFILE*uid-1.1@$">x</a>');

        $this->assertStringNotContainsString('"2"', $result);
        $this->assertStringContainsString('&amp;', $result);
    }

    /**
     * A token for a place with no file stays where it is, for the caller to report.
     */
    public function test_a_token_without_a_file_stays(): void {
        $token = new reference_file_token(['uid-1.1' => 'https://example.com/a.pdf']);
        $text = '<img src="$@COURSEGENFILE*uid-2.1@$">';

        $result = $token->replace($text);

        $this->assertSame($text, $result);
    }

    /**
     * A token that is only part of a value, or sits in plain text, is not replaced.
     */
    public function test_a_token_that_is_not_the_whole_value_is_not_replaced(): void {
        $token = new reference_file_token(['uid-1.1' => 'https://example.com/a.pdf']);
        $partial = '<a href="x/$@COURSEGENFILE*uid-1.1@$">x</a>';
        $plain = '<p>$@COURSEGENFILE*uid-1.1@$</p>';

        $this->assertSame($partial, $token->replace($partial));
        $this->assertSame($plain, $token->replace($plain));
    }

    /**
     * Two tokens in one text are each replaced by their own file.
     */
    public function test_each_token_gets_its_own_file(): void {
        $token = new reference_file_token(['u.1' => 'https://e.com/one.pdf', 'u.2' => 'https://e.com/two.png']);
        $text = '<a href="$@COURSEGENFILE*u.1@$">1</a><img src="$@COURSEGENFILE*u.2@$">';

        $result = $token->replace($text);

        $this->assertSame('<a href="https://e.com/one.pdf">1</a><img src="https://e.com/two.png">', $result);
    }

    /**
     * The first token still present is reported by its place.
     */
    public function test_the_first_remaining_token_is_reported(): void {
        $remaining = reference_file_token::first_remaining('<p>$@COURSEGENFILE*uid-9.3@$</p>');

        $this->assertSame('uid-9.3', $remaining);
    }

    /**
     * A text with no token reports none, and a token with no readable place reports an empty one.
     */
    public function test_no_token_reports_null_and_a_broken_one_an_empty_place(): void {
        $none = reference_file_token::first_remaining('<p>Nothing here</p>');
        $broken = reference_file_token::first_remaining('<p>$@COURSEGENFILE*@$</p>');

        $this->assertNull($none);
        $this->assertSame('', $broken);
    }
}
