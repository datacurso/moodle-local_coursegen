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
 * Tests for template_mold_parser.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\utils\template_mold_parser
 */
final class template_mold_parser_test extends \basic_testcase {
    /**
     * A mold without markers is one literal.
     */
    public function test_mold_without_markers_is_one_literal(): void {
        $program = template_mold_parser::parse('<p>Hola</p><script>go()</script>');
        $this->assertSame([['lit', '<p>Hola</p><script>go()</script>']], $program);
    }

    /**
     * A slot between tags is a text gap between the author's literals.
     */
    public function test_slot_between_tags_is_a_text_gap(): void {
        $program = template_mold_parser::parse('<p>[[coursegen:aiprompt: a title]]</p>');
        $this->assertSame([['lit', '<p>'], ['gap', 'text'], ['lit', '</p>']], $program);
    }

    /**
     * Both dialects and the older plain label read as slots.
     *
     * @dataProvider slot_markers
     * @param string $marker
     */
    public function test_every_marker_dialect_is_a_slot(string $marker): void {
        $program = template_mold_parser::parse('<b>' . $marker . '</b>');
        $this->assertSame([['lit', '<b>'], ['gap', 'text'], ['lit', '</b>']], $program);
    }

    /**
     * Markers in each dialect.
     *
     * @return array
     */
    public static function slot_markers(): array {
        return [
            'ascii' => ['[[coursegen:aiprompt: x]]'],
            'alias' => ['⟦coursegen:aiprompt: x⟧'],
            'plain label' => ['[[some label]]'],
            'multiline' => ["[[coursegen:aiprompt: line one\nline two]]"],
        ];
    }

    /**
     * A repeat is a split that may skip it, a start, its body and an end that goes back to the split.
     */
    public function test_repeat_is_a_loop(): void {
        $program = template_mold_parser::parse('<ul>[[coursegen:repeat: items]]<li>[[x]]</li>[[/coursegen:repeat]]</ul>');
        $this->assertSame([
            ['lit', '<ul>'],
            ['split', 7],
            ['start', '0:1'],
            ['lit', '<li>'],
            ['gap', 'text'],
            ['lit', '</li>'],
            ['end', '0:1', 1],
            ['lit', '</ul>'],
        ], $program);
    }

    /**
     * An optional block is a split that may skip it, a start, its body and an end that goes on past itself, never back.
     */
    public function test_optional_block_runs_at_most_once(): void {
        $program = template_mold_parser::parse('<ul>[[coursegen:optional: a note]]<li>[[x]]</li>[[/coursegen:optional]]</ul>');
        $this->assertSame([
            ['lit', '<ul>'],
            ['split', 7],
            ['start', '0:1'],
            ['lit', '<li>'],
            ['gap', 'text'],
            ['lit', '</li>'],
            ['end', '0:1', 7],
            ['lit', '</ul>'],
        ], $program);
    }

    /**
     * The angle bracket dialect and stray brackets read an optional block the same way.
     */
    public function test_optional_block_in_the_angle_dialect(): void {
        $angle = template_mold_parser::parse('<ul>⟦coursegen:optional: a note⟧<li>⟦x⟧</li>⟦/coursegen:optional⟧</ul>');
        $stray = template_mold_parser::parse(
            '<ul>[[ [coursegen:optional: a note] ]]<li>[[x]]</li>[[ [/coursegen:optional] ]]</ul>'
        );
        $square = template_mold_parser::parse('<ul>[[coursegen:optional: a note]]<li>[[x]]</li>[[/coursegen:optional]]</ul>');
        $this->assertSame($square, $angle);
        $this->assertSame($square, $stray);
    }

    /**
     * Nested repeats each get their own loop, and stray brackets around a repeat label are tolerated.
     */
    public function test_nested_repeats_and_stray_brackets(): void {
        $mold = '[[ [coursegen:repeat: a] ]]<div>[[coursegen:repeat: b]]<i>[[y]]</i>[[/coursegen:repeat]]</div>'
            . '[[ [/coursegen:repeat] ]]';
        $program = template_mold_parser::parse($mold);
        $this->assertNotNull($program);
        $ends = array_values(array_filter($program, static fn(array $i): bool => $i[0] === 'end'));
        $this->assertCount(2, $ends);
        $this->assertSame(count($program), $program[0][1]);
    }

    /**
     * Markers that do not pair, or a slot where nothing can be kept safely, give no program.
     *
     * @dataProvider unusable_molds
     * @param string $mold
     */
    public function test_unusable_molds_give_no_program(string $mold): void {
        $this->assertNull(template_mold_parser::parse($mold));
    }

    /**
     * Molds that cannot be matched safely.
     *
     * @return array
     */
    public static function unusable_molds(): array {
        return [
            'close without open' => ['<p>x</p>[[/coursegen:repeat]]'],
            'open without close' => ['[[coursegen:repeat: a]]<p>x</p>'],
            'optional close without open' => ['<p>x</p>[[/coursegen:optional]]'],
            'optional open without close' => ['[[coursegen:optional: a]]<p>x</p>'],
            'optional closed by a repeat closer' => ['[[coursegen:optional: a]]<p>x</p>[[/coursegen:repeat]]'],
            'optional crossing a repeat' => [
                '[[coursegen:repeat: a]][[coursegen:optional: b]]<p>x</p>[[/coursegen:repeat]][[/coursegen:optional]]',
            ],
            'slot in a script' => ['<script>var a = "[[x]]";</script>'],
            'slot in a style block' => ['<style>.a{color:[[x]]}</style>'],
            'slot in a comment' => ['<!-- [[x]] -->'],
            'slot in an unquoted attribute' => ['<div title=[[x]]>a</div>'],
            'slot as an attribute name' => ['<div [[x]]="1">a</div>'],
        ];
    }

    /**
     * A slot after a closed script, style block or comment is text again; one in an attribute names it.
     */
    public function test_slot_after_raw_blocks_and_in_attributes(): void {
        $program = template_mold_parser::parse('<script>a()</script><!-- c --><p data-k="[[k]]">[[t]]</p>');
        $gaps = array_values(array_filter($program, static fn(array $i): bool => $i[0] === 'gap'));
        $this->assertSame([['gap', 'attr:data-k'], ['gap', 'text']], $gaps);
    }
}
