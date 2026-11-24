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

use local_coursegen\local\link\syllabus_marker;

/**
 * The syllabus marker a template author writes as the address of a link.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\link\syllabus_marker
 */
final class syllabus_marker_test extends \basic_testcase {
    /**
     * A link whose address is the marker becomes a link marked for the syllabus, keeping its other attributes.
     */
    public function test_a_link_to_the_marker_is_marked_for_the_syllabus(): void {
        $text = '<p><a class="btn" href="[[coursegen:syllabus]]" target="_blank">Programa</a></p>';

        $this->assertSame(
            '<p><a class="btn" href="#" data-coursegen-syllabus="1" target="_blank">Programa</a></p>',
            syllabus_marker::to_link_mark($text)
        );
    }

    /**
     * The marker as the editor saves it in an address (brackets encoded) is read the same way.
     */
    public function test_an_encoded_marker_is_read_too(): void {
        $text = '<a href="%5B%5Bcoursegen:syllabus%5D%5D">Programa</a>';

        $this->assertSame('<a href="#" data-coursegen-syllabus="1">Programa</a>', syllabus_marker::to_link_mark($text));
    }

    /**
     * Every string of an activity's structure is converted, at any depth, and nothing else changes.
     */
    public function test_every_text_of_a_structure_is_converted(): void {
        $tree = ['page' => [['content' => '<a href="[[coursegen:syllabus]]">P</a>', 'name' => 'Programa', 'id' => 7]]];

        $converted = syllabus_marker::to_link_mark($tree);

        $this->assertSame('<a href="#" data-coursegen-syllabus="1">P</a>', $converted['page'][0]['content']);
        $this->assertSame('Programa', $converted['page'][0]['name']);
        $this->assertSame(7, $converted['page'][0]['id']);
    }

    /**
     * A text without the marker, and the other markers, are left as they are.
     */
    public function test_other_texts_are_untouched(): void {
        $text = '<a href="[[coursegen:aiprompt: la actividad H5P]]">H5P</a> [[coursegen:syllabus]]';

        $this->assertSame($text, syllabus_marker::to_link_mark($text));
    }

    /**
     * A frame whose address is the marker becomes an empty frame marked for the syllabus, keeping its size.
     */
    public function test_a_frame_to_the_marker_is_marked_for_the_syllabus(): void {
        $text = '<iframe src="[[coursegen:syllabus]]" width="100%" height="650px" style="border:none;"></iframe>';

        $this->assertSame(
            '<iframe src="about:blank" data-coursegen-syllabus="1" width="100%" height="650px" style="border:none;"></iframe>',
            syllabus_marker::to_link_mark($text)
        );
    }
}
