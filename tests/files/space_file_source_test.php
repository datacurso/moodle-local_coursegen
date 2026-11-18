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
 * Tests for space_file_source.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\files\space_file_source
 */
final class space_file_source_test extends \advanced_testcase {
    protected function tearDown(): void {
        space_scope::leave();
        parent::tearDown();
    }

    /**
     * A stored file with an address.
     *
     * @param string $name
     * @param string $content
     * @return array{0: \stored_file, 1: string}
     */
    private function file_with_address(string $name, string $content): array {
        $context = \context_system::instance();
        $file = get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_resource', 'filearea' => 'content', 'itemid' => 0,
            'filepath' => '/', 'filename' => $name,
        ], $content);
        $address = \moodle_url::make_pluginfile_url($context->id, 'mod_resource', 'content', 0, '/', $name);
        return [$file, $address->out(false)];
    }

    /**
     * The address of the template's file of a filled space gives the teacher's file.
     */
    public function test_gives_the_teachers_file_for_the_template_file(): void {
        $this->resetAfterTest();
        [$template, $address] = $this->file_with_address('template.pdf', 'T');
        [$teacher] = $this->file_with_address('mine.pdf', 'M');
        space_scope::enter(new space_selection(
            [new file_space(5, 'Guide', '', false, [$template])],
            [5 => $teacher]
        ));

        $found = (new space_file_source())->find(new file_reference(file_reference::KIND_URL, $address));

        $this->assertSame($teacher, $found);
    }

    /**
     * Without a selection in scope nothing is found.
     */
    public function test_finds_nothing_without_a_scope(): void {
        $this->resetAfterTest();
        [, $address] = $this->file_with_address('template.pdf', 'T');

        $found = (new space_file_source())->find(new file_reference(file_reference::KIND_URL, $address));

        $this->assertNull($found);
    }

    /**
     * An unfilled space, a foreign address and the other kinds of reference give nothing.
     */
    public function test_finds_nothing_for_unfilled_foreign_or_other_kinds(): void {
        $this->resetAfterTest();
        [$template, $address] = $this->file_with_address('template.pdf', 'T');
        [, $foreign] = $this->file_with_address('foreign.pdf', 'F');
        space_scope::enter(new space_selection([new file_space(5, 'Guide', '', false, [$template])], []));
        $source = new space_file_source();

        $this->assertNull($source->find(new file_reference(file_reference::KIND_URL, $address)));
        $this->assertNull($source->find(new file_reference(file_reference::KIND_URL, $foreign)));
        $this->assertNull($source->find(new file_reference(file_reference::KIND_PLACEHOLDER, 'template.pdf')));
        $this->assertNull($source->find(new file_reference(file_reference::KIND_URL, 'https://example.com/a.pdf')));
    }
}
