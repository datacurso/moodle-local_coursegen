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

/**
 * The "Course files" section of the template screen, as the template draws it.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen
 */
final class template_reference_slots_template_test extends \advanced_testcase {
    /**
     * Render the section.
     *
     * @param array $context
     * @return string
     */
    private function render(array $context): string {
        global $OUTPUT;
        return $OUTPUT->render_from_template('local_coursegen/template_reference_slots', $context);
    }

    /**
     * A row for a place that has no file yet.
     *
     * @return array
     */
    private function empty_row(): array {
        return [
            'key' => '12.1',
            'activityname' => 'Unit one',
            'instruction' => 'Didactic guide',
            'accept' => '.pdf,.docx',
            'filename' => '',
            'hasfile' => false,
        ];
    }

    /**
     * A template without places renders nothing.
     */
    public function test_no_places_render_nothing(): void {
        $this->resetAfterTest();

        $html = $this->render(['hasslots' => false, 'slots' => []]);

        $this->assertSame('', trim($html));
    }

    /**
     * A place shows its instruction, its activity, a single file input and the hint for leaving it empty.
     */
    public function test_a_place_without_file_shows_the_input_and_the_hint(): void {
        $this->resetAfterTest();
        $title = get_string('courseai_reference_title', 'local_coursegen');
        $hint = get_string('courseai_reference_hint', 'local_coursegen');

        $html = $this->render(['hasslots' => true, 'slots' => [$this->empty_row()]]);

        $this->assertStringContainsString($title, $html);
        $this->assertStringContainsString('Didactic guide', $html);
        $this->assertStringContainsString('Unit one', $html);
        $this->assertStringContainsString($hint, $html);
        $this->assertSame(1, substr_count($html, 'type="file"'));
        $this->assertStringContainsString('accept=".pdf,.docx"', $html);
        $this->assertStringContainsString('data-slot-key="12.1"', $html);
        $this->assertStringNotContainsString('remove-reference', $html);
    }

    /**
     * A place with a file shows its name and a way to remove it instead of the hint.
     */
    public function test_a_place_with_a_file_shows_the_name_and_the_remove_button(): void {
        $this->resetAfterTest();
        $row = $this->empty_row();
        $row['filename'] = 'guide.pdf';
        $row['hasfile'] = true;
        $hint = get_string('courseai_reference_hint', 'local_coursegen');

        $html = $this->render(['hasslots' => true, 'slots' => [$row]]);

        $this->assertStringContainsString('guide.pdf', $html);
        $this->assertStringContainsString('local_coursegen/template/remove-reference', $html);
        $this->assertStringNotContainsString($hint, $html);
    }

    /**
     * A marker with no instruction is labelled with the name of its activity.
     */
    public function test_a_place_without_instruction_is_labelled_with_its_activity(): void {
        $this->resetAfterTest();
        $row = $this->empty_row();
        $row['instruction'] = '';

        $html = $this->render(['hasslots' => true, 'slots' => [$row]]);

        $this->assertStringContainsString('Unit one', $html);
        $this->assertStringNotContainsString('Didactic guide', $html);
    }

    /**
     * Names are escaped and the markup carries no inline style.
     */
    public function test_names_are_escaped_and_there_is_no_inline_style(): void {
        $this->resetAfterTest();
        $row = $this->empty_row();
        $row['instruction'] = '<script>alert(1)</script>';

        $html = $this->render(['hasslots' => true, 'slots' => [$row]]);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('style=', $html);
    }

    /**
     * Two places give two inputs with their own ids.
     */
    public function test_each_place_has_its_own_input(): void {
        $this->resetAfterTest();
        $second = $this->empty_row();
        $second['key'] = '12.2';

        $html = $this->render(['hasslots' => true, 'slots' => [$this->empty_row(), $second]]);

        $this->assertSame(2, substr_count($html, 'type="file"'));
        $this->assertStringContainsString('-12.1"', $html);
        $this->assertStringContainsString('-12.2"', $html);
    }
}
