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

use local_coursegen\local\template\plain_text;

/**
 * Tests for the cleaning of the free text an admin types.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\template\plain_text
 */
final class template_plain_text_test extends \advanced_testcase {
    /**
     * Texts and what they become.
     *
     * @return array[]
     */
    public static function text_provider(): array {
        return [
            'null stays null' => [null, null],
            'empty is null' => ['', null],
            'spaces only is null' => ['   ', null],
            'tabs and newlines only is null' => ["\t\n \r\n", null],
            'text is trimmed' => ["  Write it for beginners \n", 'Write it for beginners'],
            'windows newlines become newlines' => ["one\r\ntwo\rthree", "one\ntwo\nthree"],
            'inner newlines and tabs are kept' => ["a\n\n\tb", "a\n\n\tb"],
            'html is stored as typed' => ['<b>bold</b> & "quotes"', '<b>bold</b> & "quotes"'],
            'script is stored as typed' => ['<script>alert(1)</script>', '<script>alert(1)</script>'],
            'control characters are removed' => ["a\x00b\x07c\x1Fd\x7Fe", 'abcde'],
            'unicode is kept' => ['Explica «ñandú» con áéíóú', 'Explica «ñandú» con áéíóú'],
            'emoji is kept' => ['Use a friendly tone 😀👍🏽', 'Use a friendly tone 😀👍🏽'],
            'right to left text is kept' => ['مرحبا بالعالم', 'مرحبا بالعالم'],
        ];
    }

    /**
     * A text is cleaned for storage.
     *
     * @dataProvider text_provider
     * @param string|null $input Text typed by the admin.
     * @param string|null $expected What is stored.
     */
    public function test_normalize(?string $input, ?string $expected): void {
        $normalized = plain_text::normalize($input);
        $this->assertSame($expected, $normalized);
    }

    /**
     * Invalid UTF-8 bytes are removed instead of breaking the save.
     */
    public function test_invalid_utf8_is_repaired(): void {
        $result = plain_text::normalize("good \xC3\x28 text");

        $this->assertIsString($result);
        $valid = mb_check_encoding($result, 'UTF-8');
        $this->assertTrue($valid);
        $this->assertStringContainsString('good', $result);
        $this->assertStringContainsString('text', $result);
    }

    /**
     * A text of exactly the limit is accepted.
     */
    public function test_text_of_the_limit_is_accepted(): void {
        $text = str_repeat('a', plain_text::MAX_LENGTH);

        $normalized = plain_text::normalize($text);
        $this->assertSame($text, $normalized);
    }

    /**
     * A text one character over the limit is rejected.
     */
    public function test_text_over_the_limit_is_rejected(): void {
        $this->expectException(\moodle_exception::class);
        $message = get_string('error_text_too_long', 'local_coursegen', plain_text::MAX_LENGTH);
        $this->expectExceptionMessage($message);

        $text = str_repeat('a', plain_text::MAX_LENGTH + 1);
        plain_text::normalize($text);
    }

    /**
     * The limit counts characters, not bytes, so multibyte text is not cut early.
     */
    public function test_limit_counts_characters_not_bytes(): void {
        $text = str_repeat('😀', plain_text::MAX_LENGTH);

        $normalized = plain_text::normalize($text);
        $this->assertSame($text, $normalized);
    }

    /**
     * The limit fits the column: the longest text stays under the 65535 bytes of a TEXT column.
     */
    public function test_longest_text_fits_a_text_column(): void {
        $text = str_repeat('😀', plain_text::MAX_LENGTH);

        $length = strlen($text);
        $this->assertLessThanOrEqual(65535, $length);
    }
}
