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

use local_coursegen\local\placeholder\marker_scanner;

/**
 * Finding the placeholders of a text the way the strict template source parser reads them.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\placeholder\marker_scanner
 * @covers     \local_coursegen\local\placeholder\marker_scan
 */
final class marker_scanner_test extends \basic_testcase {
    /**
     * Texts that carry placeholders and how many the scan counts.
     *
     * @return array
     */
    public static function placeholder_provider(): array {
        $tenslots = str_repeat('<p>[[coursegen:aiprompt: x]]</p>', 10);
        return [
            'one slot' => ['<p>[[coursegen:aiprompt: course title]]</p>', 1],
            'a slot and a repeat block' => [
                '[[coursegen:repeat: one paragraph]]<p>[[coursegen:aiprompt: text]]</p>[[/coursegen:repeat]]',
                2,
            ],
            'a reference' => ['[[coursegen:reference: the syllabus file]]<a href="x">file</a>', 1],
            'the angle bracket dialect' => ['<p>⟦coursegen:aiprompt: intro⟧</p>', 1],
            'a video slot in an attribute' => ['<iframe src="[[coursegen:aiprompt: a video]]"></iframe>', 1],
            'a link slot in an attribute' => ['<a href="[[coursegen:aiprompt: other activity]]">go</a>', 1],
            'spaces around the marker body' => ['[[ coursegen:aiprompt : spaced ]]', 1],
            'an instruction with entities and markup' => ['[[coursegen:aiprompt: a &amp; b <em>c</em>]]', 1],
            'an instruction with unicode and emoji' => ['[[coursegen:aiprompt: título del curso 📚]]', 1],
            'ten slots' => [$tenslots, 10],
            'a repeat marker with a line break in the instruction' => [
                "[[coursegen:repeat: first line\nsecond line]]<p>x</p>[[/coursegen:repeat]]",
                1,
            ],
        ];
    }

    /**
     * A text with well formed markers counts them and reports no problem.
     *
     * @dataProvider placeholder_provider
     * @param string $text The text to scan.
     * @param int $expected How many placeholders it carries.
     */
    public function test_counts_the_well_formed_placeholders(string $text, int $expected): void {
        $scan = marker_scanner::scan($text);

        $this->assertSame($expected, $scan->placeholders);
        $has = $scan->has_placeholders();
        $this->assertTrue($has);
        $this->assertSame([], $scan->problems);
    }

    /**
     * Texts without any placeholder.
     *
     * @return array
     */
    public static function plain_provider(): array {
        return [
            'an empty text' => [''],
            'plain html' => ['<p>Hello</p>'],
            'a wiki page link' => ['[[Main page]]'],
            'a link of the angle dialect that is not a marker' => ['⟦Main page⟧'],
            'brackets that never close' => ['[[ not closed'],
            'a closer alone' => [']] and ⟧'],
        ];
    }

    /**
     * A text without markers has no placeholders and no problems.
     *
     * @dataProvider plain_provider
     * @param string $text The text to scan.
     */
    public function test_a_plain_text_has_no_placeholders(string $text): void {
        $scan = marker_scanner::scan($text);

        $this->assertSame(0, $scan->placeholders);
        $has = $scan->has_placeholders();
        $this->assertFalse($has);
        $this->assertSame([], $scan->problems);
    }

    /**
     * Malformed markers.
     *
     * @return array
     */
    public static function malformed_provider(): array {
        return [
            'an unterminated marker' => ['<p>[[coursegen:aiprompt: unterminated</p>', 'malformed or unclosed'],
            'a marker without an instruction' => ['[[coursegen:aiprompt:]]', 'without an instruction'],
            'a marker with a blank instruction' => ['[[coursegen:aiprompt:    ]]', 'without an instruction'],
            'an unknown marker' => ['[[coursegen:image: a picture]]', 'unknown coursegen marker'],
            'a marker with a wrong case' => ['[[CourseGen:aiprompt: x]]', 'unknown coursegen marker'],
            'a repeat that never closes' => ['[[coursegen:repeat: x]]<p>y</p>', 'unbalanced'],
            'a closer without its opener' => ['<p>y</p>[[/coursegen:repeat]]', 'unbalanced'],
        ];
    }

    /**
     * A malformed marker is reported and never counted as a placeholder it is not.
     *
     * @dataProvider malformed_provider
     * @param string $text The text to scan.
     * @param string $fragment What the problem has to say.
     */
    public function test_reports_a_malformed_marker(string $text, string $fragment): void {
        $scan = marker_scanner::scan($text);

        $joined = implode(' | ', $scan->problems);
        $this->assertNotEmpty($scan->problems);
        $this->assertStringContainsString($fragment, $joined);
    }

    /**
     * A malformed marker next to a good one leaves the good one counted.
     */
    public function test_a_good_marker_next_to_a_bad_one_still_counts(): void {
        $scan = marker_scanner::scan('[[coursegen:aiprompt: good]] and [[coursegen:aiprompt: unterminated');

        $this->assertSame(1, $scan->placeholders);
        $this->assertNotEmpty($scan->problems);
    }

    /**
     * A marker inside a comment, a script or a style is counted and reported as hidden.
     */
    public function test_counts_the_markers_hidden_in_a_comment_script_or_style(): void {
        $text = '<!-- [[coursegen:aiprompt: a]] --><script>var x = "[[coursegen:aiprompt: b]]";</script>'
            . '<style>/* [[coursegen:aiprompt: c]] */</style><p>[[coursegen:aiprompt: d]]</p>';

        $scan = marker_scanner::scan($text);

        $this->assertSame(4, $scan->placeholders);
        $this->assertSame(3, $scan->hidden);
    }

    /**
     * A text with no hidden marker reports none.
     */
    public function test_a_visible_marker_is_not_hidden(): void {
        $scan = marker_scanner::scan('<p>[[coursegen:aiprompt: shown]]</p>');

        $this->assertSame(0, $scan->hidden);
    }

    /**
     * A very large text is scanned in one pass without trouble.
     */
    public function test_scans_a_very_large_text(): void {
        $text = str_repeat('<p>Lorem ipsum dolor sit amet [[coursegen:aiprompt: filler]] consectetur.</p>', 20000);

        $scan = marker_scanner::scan($text);

        $this->assertSame(20000, $scan->placeholders);
        $this->assertSame([], $scan->problems);
    }
}
