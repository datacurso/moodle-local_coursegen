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

use local_coursegen\local\service\template_placeholder_scanner;

/**
 * Unit tests for the text-level placeholder scanner.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_placeholder_scanner
 */
final class template_placeholder_scanner_test extends \basic_testcase {
    /**
     * Every text and dialect setting the scanner is asked about, with the answer.
     *
     * @return array
     */
    public static function text_provider(): array {
        return [
            'bracket marker' => ['A [[coursegen:aiprompt: write a title]] B', true, true],
            'angle marker' => ['A ⟦coursegen:aiprompt: write a title⟧ B', true, true],
            'angle marker where brackets are off' => ['A ⟦coursegen:aiprompt: write a title⟧ B', false, true],
            'bracket marker where brackets are off' => ['A [[coursegen:aiprompt: write a title]] B', false, false],
            'marker without a space after the colon' => ['[[coursegen:aiprompt:write]]', true, true],
            'marker with a multiline instruction' => ["[[coursegen:aiprompt: first\nsecond]]", true, true],
            'marker inside markup' => ['<p>[[coursegen:aiprompt: <b>write</b> it]]</p>', true, true],
            'empty bracket marker' => ['[[coursegen:aiprompt:]]', true, false],
            'blank bracket marker' => ['[[coursegen:aiprompt:   ]]', true, false],
            'empty angle marker' => ['⟦coursegen:aiprompt:⟧', true, false],
            'marker holding only markup' => ['[[coursegen:aiprompt: <br> &nbsp; ]]', true, false],
            'marker holding only a non breaking space' => ["[[coursegen:aiprompt:\xC2\xA0]]", true, false],
            'empty marker followed by a valid one' => ['[[coursegen:aiprompt:]] [[coursegen:aiprompt: ok]]', true, true],
            'valid marker followed by an empty one' => ['[[coursegen:aiprompt: ok]] [[coursegen:aiprompt:]]', true, true],
            'plain bracket text' => ['A [[title]] B', true, false],
            'plain angle text' => ['A ⟦title⟧ B', true, false],
            'another coursegen marker' => ['[[coursegen:image: a banner]] ⟦coursegen:image: a banner⟧', true, false],
            'marker name only' => ['coursegen:aiprompt: write a title', true, false],
            'half opened marker' => ['[[coursegen:aiprompt: write a title', true, false],
            'mixed delimiters' => ['[[coursegen:aiprompt: write a title⟧', true, false],
            'bracket repeat block' => ['[[coursegen:repeat: one per unit]]<li>x</li>[[/coursegen:repeat]]', true, true],
            'angle repeat block' => ['⟦coursegen:repeat: one per unit⟧<li>x</li>⟦/coursegen:repeat⟧', true, true],
            'angle repeat block where brackets are off' => [
                '⟦coursegen:repeat: one per unit⟧x⟦/coursegen:repeat⟧',
                false,
                true,
            ],
            'bracket repeat block where brackets are off' => [
                '[[coursegen:repeat: one per unit]]x[[/coursegen:repeat]]',
                false,
                false,
            ],
            'repeat block over several lines' => ["[[coursegen:repeat: one per unit]]\n<li>x</li>\n[[/coursegen:repeat]]", true, true],
            'repeat block with an empty body' => ['[[coursegen:repeat: one per unit]][[/coursegen:repeat]]', true, true],
            'repeat block with an empty instruction' => ['[[coursegen:repeat:]]x[[/coursegen:repeat]]', true, false],
            'repeat block with a blank instruction' => ['⟦coursegen:repeat:  ⟧x⟦/coursegen:repeat⟧', true, false],
            'repeat opening without a closing' => ['[[coursegen:repeat: one per unit]]<li>x</li>', true, false],
            'repeat closing without an opening' => ['<li>x</li>[[/coursegen:repeat]]', true, false],
            'empty repeat block followed by a valid one' => [
                '[[coursegen:repeat:]]a[[/coursegen:repeat]] [[coursegen:repeat: ok]]b[[/coursegen:repeat]]',
                true,
                true,
            ],
            'empty text' => ['', true, false],
            'text with no marker' => ['<p>Just some lesson text.</p>', true, false],
        ];
    }

    /**
     * The scanner counts exactly the markers with an instruction, in the dialects it is allowed to read.
     *
     * @dataProvider text_provider
     * @param string $text The text to scan.
     * @param bool $allowbrackets Whether the double bracket dialect is read.
     * @param bool $expected Whether the text holds a valid placeholder.
     */
    public function test_scanner_counts_only_markers_with_an_instruction(
        string $text,
        bool $allowbrackets,
        bool $expected
    ): void {
        $found = template_placeholder_scanner::contains_placeholder($text, $allowbrackets);

        $this->assertSame($expected, $found);
    }
}
