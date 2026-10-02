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

namespace local_coursegen\local\service;

/**
 * The reference markers of a template source activity and the file each one points at.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_reference_scanner
 */
final class template_reference_scanner_test extends \basic_testcase {
    /** @var string A marker. */
    private const MARKER = '[[coursegen:reference: a new picture]]';

    /**
     * Export parameters holding one text and a file entry.
     *
     * @param string $html
     * @return array
     */
    private function parameters(string $html): array {
        return [
            'name' => 'Forum',
            'structure' => ['forum' => [['intro' => $html]]],
            'files' => [['filename' => 'Publicidad.jpg', 'mimetype' => 'image/jpeg', 'isdir' => false]],
        ];
    }

    /**
     * An image below the marker is the target and its file is the reference.
     */
    public function test_the_image_below_the_marker_is_the_reference(): void {
        $html = '<p>' . self::MARKER . '</p><p><img src="@@PLUGINFILE@@/Publicidad.jpg" alt="x"></p>';

        $slots = template_reference_scanner::slots($this->parameters($html), 'mold-1');

        $this->assertCount(1, $slots);
        $this->assertSame('mold-1.1', $slots[0]['key']);
        $this->assertSame('a new picture', $slots[0]['instruction']);
        $this->assertSame('Publicidad.jpg', $slots[0]['filename']);
        $this->assertSame('image/jpeg', $slots[0]['mimetype']);
    }

    /**
     * The address the export gives a file of the template is a reference too.
     */
    public function test_a_pluginfile_address_names_its_file(): void {
        $html = self::MARKER . '<img src="https://campus.test/pluginfile.php/10/mod_forum/intro/Mi%20foto.png?x=1">';

        $slots = template_reference_scanner::slots($this->parameters($html), 'mold-1');

        $this->assertSame('Mi foto.png', $slots[0]['filename']);
        $this->assertSame('', $slots[0]['mimetype']);
    }

    /**
     * A block with a heading and a frame is the target when it carries one file.
     */
    public function test_a_whole_block_is_the_target(): void {
        $html = self::MARKER . '<div><h3>Ad</h3><iframe src="@@PLUGINFILE@@/Publicidad.jpg"></iframe></div>';

        $slots = template_reference_scanner::slots($this->parameters($html), 'mold-1');

        $this->assertSame('Publicidad.jpg', $slots[0]['filename']);
    }

    /**
     * Markers are numbered across the texts in key order, and inside a text in document order.
     */
    public function test_slots_are_numbered_across_texts_in_order(): void {
        $parameters = [
            'structure' => [
                'forum' => [[
                    'intro' => self::MARKER . '<img src="@@PLUGINFILE@@/a.png">'
                        . '[[coursegen:reference: second]]<img src="@@PLUGINFILE@@/b.png">',
                    'discussions' => [['message' => '⟦coursegen:reference: third⟧<img src="@@PLUGINFILE@@/c.png">']],
                ]],
            ],
        ];

        $slots = template_reference_scanner::slots($parameters, 'mold-1');

        $this->assertSame(['mold-1.1', 'mold-1.2', 'mold-1.3'], array_column($slots, 'key'));
        $this->assertSame(['a.png', 'b.png', 'c.png'], array_column($slots, 'filename'));
        $this->assertSame(['a new picture', 'second', 'third'], array_column($slots, 'instruction'));
    }

    /**
     * A text without a marker yields nothing.
     */
    public function test_texts_without_markers_have_no_slots(): void {
        $this->assertSame([], template_reference_scanner::slots($this->parameters('<p>Hello</p>'), 'mold-1'));
    }

    /**
     * Data that is not text is ignored.
     */
    public function test_values_that_are_not_texts_are_ignored(): void {
        $parameters = ['a' => 1, 'b' => null, 'c' => true, 'd' => [1.5, 2]];

        $this->assertSame([], template_reference_scanner::slots($parameters, 'mold-1'));
    }

    /**
     * Cases that must be refused, with the language string each one names.
     *
     * @return array
     */
    public static function refusals(): array {
        $file = '<img src="@@PLUGINFILE@@/Publicidad.jpg">';
        return [
            'no element at all' => ['<p>' . self::MARKER . '</p>', 'template_reference_no_element'],
            'element before only' => [$file . self::MARKER, 'template_reference_no_element'],
            'another marker first' => [self::MARKER . '[[coursegen:reference: other]]' . $file, 'template_reference_no_element'],
            'no file' => [self::MARKER . '<p>text</p>', 'template_reference_no_file'],
            'file of another site' => [self::MARKER . '<img src="https://x.test/a.png">', 'template_reference_no_file'],
            'two files' => [
                self::MARKER . '<div>' . $file . '<img src="@@PLUGINFILE@@/b.png"></div>',
                'template_reference_many_files',
            ],
            'marker inside the target' => [
                self::MARKER . '<div>[[coursegen:reference: inner]]' . $file . '</div>',
                'template_reference_nested',
            ],
        ];
    }

    /**
     * A marker that cannot be bound is refused with a message that names it.
     *
     * @dataProvider refusals
     * @param string $html
     * @param string $identifier
     */
    public function test_a_marker_without_a_usable_target_is_refused(string $html, string $identifier): void {
        try {
            template_reference_scanner::slots($this->parameters($html), 'mold-1');
            $this->fail('A moodle_exception was expected.');
        } catch (\moodle_exception $exception) {
            $this->assertSame($identifier, $exception->errorcode);
        }
    }
}
