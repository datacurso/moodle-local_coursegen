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

namespace local_coursegen\utils;

/**
 * Tests for template_mold_matcher.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\utils\template_mold_matcher
 */
final class template_mold_matcher_test extends \basic_testcase {
    /** @var string A list whose items repeat, each with a title and a hidden message. */
    private const LIST_MOLD = '<ul>[[coursegen:repeat: items]]<li><b>[[t]]</b><span style="display:none">ok</span></li>'
        . '[[/coursegen:repeat]]</ul>';

    /**
     * The gaps a match read, in order.
     *
     * @param string $mold
     * @param string $text
     * @return array|null
     */
    private function gaps(string $mold, string $text): ?array {
        $program = template_mold_parser::parse($mold);
        $parts = template_mold_matcher::match($program, $text);
        if ($parts === null) {
            return null;
        }
        $gaps = array_filter($parts, static fn(array $part): bool => $part[0] === 'gap');
        return array_values(array_column($gaps, 1));
    }

    /**
     * A text equal to a mold without markers matches with no gap.
     */
    public function test_exact_text_matches(): void {
        $this->assertSame([], $this->gaps('<p id="a">x</p>', '<p id="a">x</p>'));
    }

    /**
     * Each slot reads what the AI wrote in its place, empty included.
     */
    public function test_slots_read_their_values(): void {
        $mold = '<h3>[[a]]</h3><p>[[b]]</p>';
        $this->assertSame(['Título', 'Uno &amp; dos'], $this->gaps($mold, '<h3>Título</h3><p>Uno &amp; dos</p>'));
        $this->assertSame(['', ''], $this->gaps($mold, '<h3></h3><p></p>'));
    }

    /**
     * A repeat may run zero, one or many times.
     *
     * @dataProvider repeat_counts
     * @param int $count
     */
    public function test_repeat_runs_any_number_of_times(int $count): void {
        $items = '';
        $expected = [];
        for ($i = 0; $i < $count; $i++) {
            $items .= '<li><b>T' . $i . '</b><span style="display:none">ok</span></li>';
            $expected[] = 'T' . $i;
        }
        $this->assertSame($expected, $this->gaps(self::LIST_MOLD, '<ul>' . $items . '</ul>'));
    }

    /**
     * Repeat counts.
     *
     * @return array
     */
    public static function repeat_counts(): array {
        return ['none' => [0], 'one' => [1], 'three' => [3], 'many' => [400]];
    }

    /**
     * Nested repeats match their own runs.
     */
    public function test_nested_repeats(): void {
        $mold = '[[coursegen:repeat: g]]<div>[[coursegen:repeat: i]]<i>[[x]]</i>[[/coursegen:repeat]]</div>[[/coursegen:repeat]]';
        $this->assertSame(['a', 'b', 'c'], $this->gaps($mold, '<div><i>a</i><i>b</i></div><div><i>c</i></div>'));
    }

    /**
     * A text that changes, adds or drops any of the author's text does not match.
     *
     * @dataProvider mismatches
     * @param string $text
     */
    public function test_changed_author_text_does_not_match(string $text): void {
        $this->assertNull($this->gaps(self::LIST_MOLD, $text));
    }

    /**
     * Texts that differ from the list mold outside its slots.
     *
     * @return array
     */
    public static function mismatches(): array {
        return [
            'hidden message shown' => ['<ul><li><b>a</b><span>ok</span></li></ul>'],
            'script added at the end' => ['<ul></ul><script>alert(1)</script>'],
            'script added at the start' => ['<script>alert(1)</script><ul></ul>'],
            'list dropped' => ['<b>a</b>'],
            'empty text' => [''],
        ];
    }

    /**
     * An attribute slot only matches a value that is safe there.
     */
    public function test_attribute_slot_needs_a_safe_value(): void {
        $mold = '<div data-keys="[[k]]">x</div>';
        $this->assertSame(['media, moda'], $this->gaps($mold, '<div data-keys="media, moda">x</div>'));
        $this->assertNull($this->gaps($mold, '<div data-keys="a" onclick="go()">x</div>'));
    }

    /**
     * A slot followed by another slot takes the shortest value that lets the rest match.
     */
    public function test_adjacent_slots(): void {
        $this->assertSame(['', 'ab'], $this->gaps('<p>[[a]][[b]]</p>', '<p>ab</p>'));
    }

    /**
     * A repeat whose body can be empty does not loop forever.
     */
    public function test_repeat_with_an_empty_body_ends(): void {
        $gaps = $this->gaps('<p>[[coursegen:repeat: r]][[x]][[/coursegen:repeat]]</p>', '<p>abc</p>');
        $this->assertNotNull($gaps);
        $this->assertSame('abc', implode('', $gaps));
        $this->assertNotContains('', $gaps);
    }

    /**
     * A match that needs more steps than allowed gives up.
     */
    public function test_match_gives_up_after_its_steps(): void {
        $program = template_mold_parser::parse(self::LIST_MOLD);
        $text = '<ul>' . str_repeat('<li><b>T</b><span style="display:none">ok</span></li>', 50) . '</ul>';
        $this->assertNull(template_mold_matcher::match($program, $text, 10));
        $this->assertNotNull(template_mold_matcher::match($program, $text));
    }
}
