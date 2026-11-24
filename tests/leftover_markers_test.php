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

use local_coursegen\local\service\leftover_markers;

/**
 * Taking out of a text the markers nobody filled, so they never reach a student.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\leftover_markers
 */
final class leftover_markers_test extends \basic_testcase {
    /**
     * An unfilled marker is taken out, and a paragraph that held only a marker goes with it.
     */
    public function test_unfilled_markers_and_their_empty_paragraphs_are_taken_out(): void {
        $text = '<h2>Bienvenida a [[coursegen:aiprompt: nombre del curso]]</h2>'
            . '<p>[[coursegen:aiprompt: un párrafo]]</p><p>Fijo</p>';

        $this->assertSame('<h2>Bienvenida a </h2><p>Fijo</p>', leftover_markers::strip($text));
    }

    /**
     * The markers of a repeat block and the wiki dialect are taken out too; the content between them stays once.
     */
    public function test_repeat_markers_and_the_wiki_dialect_are_taken_out(): void {
        $text = '[[coursegen:repeat: un resultado]]<div class="card">Resultado</div>[[/coursegen:repeat]]⟦coursegen:aiprompt: x⟧';

        $this->assertSame('<div class="card">Resultado</div>', leftover_markers::strip($text));
    }

    /**
     * A text without markers is returned as it came.
     */
    public function test_a_text_without_markers_is_untouched(): void {
        $text = '<p>Un texto con [[dobles corchetes]] que no es un marcador.</p>';

        $this->assertSame($text, leftover_markers::strip($text));
    }
}
