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

use local_coursegen\local\link\syllabus_link;

/**
 * The syllabus link: pointing at the uploaded file, or taken out when there is none.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\link\syllabus_link
 */
final class syllabus_link_test extends \basic_testcase {
    /**
     * A text with no syllabus link is returned as it came, with or without a file.
     */
    public function test_a_text_without_a_syllabus_link_is_untouched(): void {
        $text = '<p><a href="#">Guía</a> <a data-coursegen-embed="1" href="#">H5P</a></p>';

        $this->assertFalse(syllabus_link::present($text));
        $this->assertSame($text, syllabus_link::apply($text, 'guia.pdf'));
        $this->assertSame($text, syllabus_link::apply($text, null));
    }

    /**
     * The link points at the file in the file area of its text, keeping its other attributes and its content.
     */
    public function test_the_link_points_at_the_file_kept_with_the_text(): void {
        $text = '<p><a class="btn" data-coursegen-syllabus="1" href="#" target="_blank"><strong>Guía</strong></a></p>';

        $result = syllabus_link::apply($text, 'guia.pdf');

        $this->assertSame(
            '<p><a class="btn" data-coursegen-syllabus="1" href="@@PLUGINFILE@@/guia.pdf" target="_blank">'
                . '<strong>Guía</strong></a></p>',
            $result
        );
    }

    /**
     * A link without href gets one.
     */
    public function test_a_link_without_href_gets_one(): void {
        $result = syllabus_link::apply("<a data-coursegen-syllabus='1'>Guía</a>", 'guia.pdf');

        $this->assertSame('<a href="@@PLUGINFILE@@/guia.pdf" data-coursegen-syllabus=\'1\'>Guía</a>', $result);
    }

    /**
     * A file name with spaces and accents is written as a URL path.
     */
    public function test_the_file_name_is_encoded(): void {
        $result = syllabus_link::apply('<a data-coursegen-syllabus="1" href="#">Guía</a>', 'GUÍA DIDÁCTICA.pdf');

        $this->assertStringContainsString('href="@@PLUGINFILE@@/GU%C3%8DA%20DID%C3%81CTICA.pdf"', $result);
    }

    /**
     * Without a file the link is taken out and the rest of the text stays.
     */
    public function test_without_a_file_the_link_is_taken_out(): void {
        $text = '<p>Antes <a data-coursegen-syllabus="1" href="#">Guía</a> después</p>';

        $this->assertSame('<p>Antes  después</p>', syllabus_link::apply($text, null));
        $this->assertSame('<p>Antes  después</p>', syllabus_link::apply($text, ''));
    }

    /**
     * Every syllabus link of a text is handled, and a value other than 1 is not a syllabus link.
     */
    public function test_every_link_is_handled_and_only_the_marked_ones(): void {
        $text = '<a data-coursegen-syllabus="1" href="#">A</a><a data-coursegen-syllabus="0" href="#">B</a>'
            . '<a data-coursegen-syllabus="1" href="x">C</a>';

        $result = syllabus_link::apply($text, 'g.pdf');

        $this->assertSame(2, substr_count($result, '@@PLUGINFILE@@/g.pdf'));
        $this->assertStringContainsString('<a data-coursegen-syllabus="0" href="#">B</a>', $result);
    }
}
