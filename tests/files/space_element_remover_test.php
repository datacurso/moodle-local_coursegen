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

namespace local_coursegen\local\files;

use local_coursegen\local\space\file_space;
use local_coursegen\local\space\space_scope;
use local_coursegen\local\space\space_selection;

/**
 * Tests for space_element_remover.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\files\space_element_remover
 */
final class space_element_remover_test extends \advanced_testcase {
    /** @var string The address of the template file of the space. */
    private string $address = '';

    protected function tearDown(): void {
        space_scope::leave();
        parent::tearDown();
    }

    /**
     * Put a space in scope and keep the address of its template file.
     *
     * @param bool $filled Whether the teacher brought a file.
     */
    private function scope(bool $filled): void {
        $context = \context_system::instance();
        $fs = get_file_storage();
        $template = $fs->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_resource', 'filearea' => 'content', 'itemid' => 0,
            'filepath' => '/', 'filename' => 'GD Guide.pdf',
        ], 'T');
        $files = [];
        if ($filled) {
            $files[5] = $fs->create_file_from_string([
                'contextid' => $context->id, 'component' => 'local_coursegen', 'filearea' => 'spacefile', 'itemid' => 1,
                'filepath' => '/5/', 'filename' => 'mine.pdf',
            ], 'M');
        }
        space_scope::enter(new space_selection([new file_space(5, 'Guide', '', false, [$template])], $files));
        $url = \moodle_url::make_pluginfile_url($context->id, 'mod_resource', 'content', 1, '/', 'GD Guide.pdf');
        $this->address = $url->out(false);
    }

    /**
     * Every element that points at the file of an unfilled space goes, and nothing else.
     */
    public function test_removes_only_the_elements_that_point_at_the_unfilled_file(): void {
        $this->resetAfterTest();
        $this->scope(false);
        $a = $this->address;
        $text = '<h3>Guide</h3><p>Read:</p><iframe src="' . $a . '" width="100%"></iframe>'
            . '<p><a href="' . $a . '">Download <b>it</b></a></p>'
            . '<img src="' . $a . '" alt="x"><embed src="' . $a . '" type="application/pdf">'
            . '<object data="' . $a . '"><embed src="' . $a . '"></object>'
            . '<p>Keep <a href="https://example.com/x">this</a> <img src="https://example.com/i.png"></p>';

        $result = (new space_element_remover())->strip($text);

        $expected = '<h3>Guide</h3><p>Read:</p><p></p>'
            . '<p>Keep <a href="https://example.com/x">this</a> <img src="https://example.com/i.png"></p>';
        $this->assertSame($expected, $result);
    }

    /**
     * An address written with entities is the same address.
     */
    public function test_reads_the_address_with_entities(): void {
        $this->resetAfterTest();
        $this->scope(false);
        $encoded = str_replace('&', '&amp;', $this->address . '?forcedownload=1&x=2');
        $text = '<p>a</p><iframe src="' . $encoded . '"></iframe><p>b</p>';

        $result = (new space_element_remover())->strip($text);

        $this->assertSame('<p>a</p><p>b</p>', $result);
    }

    /**
     * A filled space keeps its elements: the file pass points them at the teacher's file.
     */
    public function test_keeps_the_elements_of_a_filled_space(): void {
        $this->resetAfterTest();
        $this->scope(true);
        $text = '<iframe src="' . $this->address . '"></iframe>';

        $result = (new space_element_remover())->strip($text);

        $this->assertSame($text, $result);
    }

    /**
     * Without a selection in scope, or without a pluginfile address, the text is returned as it came.
     */
    public function test_returns_the_text_untouched_when_there_is_nothing_to_remove(): void {
        $this->resetAfterTest();
        $plain = '<p>Hello <iframe src="https://example.com/v"></iframe></p>';
        $remover = new space_element_remover();

        $this->assertSame($plain, $remover->strip($plain));

        $this->scope(false);
        $this->assertSame($plain, $remover->strip($plain));
        $this->assertSame('', $remover->strip(''));
    }

    /**
     * A pluginfile address of a file that is not a space's stays.
     */
    public function test_keeps_the_elements_of_other_files(): void {
        $this->resetAfterTest();
        $this->scope(false);
        $context = \context_system::instance();
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_resource', 'filearea' => 'content', 'itemid' => 0,
            'filepath' => '/', 'filename' => 'other.pdf',
        ], 'O');
        $other = \moodle_url::make_pluginfile_url($context->id, 'mod_resource', 'content', 1, '/', 'other.pdf');
        $text = '<iframe src="' . $other->out(false) . '"></iframe>';

        $result = (new space_element_remover())->strip($text);

        $this->assertSame($text, $result);
    }
}
