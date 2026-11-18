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

namespace local_coursegen\local\space;

/**
 * Tests for space_selection.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\space\space_selection
 * @covers     \local_coursegen\local\space\file_space
 */
final class space_selection_test extends \advanced_testcase {
    /**
     * A stored file in the course of a test.
     *
     * @param string $name
     * @param string $content
     * @return \stored_file
     */
    private function stored(string $name, string $content): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_coursegen',
            'filearea' => 'testarea',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $name,
        ], $content);
    }

    /**
     * A space holding a file.
     *
     * @param int $cmid
     * @param \stored_file $file
     * @param bool $required
     * @return file_space
     */
    private function space(int $cmid, \stored_file $file, bool $required = false): file_space {
        return new file_space($cmid, 'Guide ' . $cmid, 'Guide of the subject', $required, [$file]);
    }

    /**
     * The teacher's file replaces the template file of a filled space.
     */
    public function test_a_filled_space_answers_with_the_teachers_file(): void {
        $this->resetAfterTest();
        $template = $this->stored('template.pdf', 'T');
        $teacher = $this->stored('mine.pdf', 'M');
        $selection = new space_selection([$this->space(10, $template)], [10 => $teacher]);

        $this->assertTrue($selection->owns($template));
        $this->assertSame($teacher, $selection->teacher_file_for($template));
        $this->assertFalse($selection->is_unfilled($template));
        $this->assertSame([10], $selection->filled_cmids());
    }

    /**
     * A space without a file owns the template file and has no answer.
     */
    public function test_an_unfilled_space_has_no_teacher_file(): void {
        $this->resetAfterTest();
        $template = $this->stored('template.pdf', 'T');
        $selection = new space_selection([$this->space(10, $template)], []);

        $this->assertTrue($selection->owns($template));
        $this->assertNull($selection->teacher_file_for($template));
        $this->assertTrue($selection->is_unfilled($template));
        $this->assertSame([], $selection->filled_cmids());
    }

    /**
     * A file that no space holds belongs to nobody.
     */
    public function test_a_foreign_file_is_not_owned(): void {
        $this->resetAfterTest();
        $template = $this->stored('template.pdf', 'T');
        $other = $this->stored('other.pdf', 'O');
        $selection = new space_selection([$this->space(10, $template)], []);

        $this->assertFalse($selection->owns($other));
        $this->assertNull($selection->teacher_file_for($other));
        $this->assertFalse($selection->is_unfilled($other));
    }

    /**
     * Each space answers with its own file.
     */
    public function test_two_spaces_keep_their_own_files(): void {
        $this->resetAfterTest();
        $first = $this->stored('first.pdf', '1');
        $second = $this->stored('second.pdf', '2');
        $mine = $this->stored('mine.pdf', 'M');
        $selection = new space_selection(
            [$this->space(10, $first), $this->space(11, $second)],
            [11 => $mine]
        );

        $this->assertNull($selection->teacher_file_for($first));
        $this->assertSame($mine, $selection->teacher_file_for($second));
        $this->assertSame([11], $selection->filled_cmids());
        $this->assertSame([10], array_map(static fn($space) => $space->cmid, $selection->unfilled()));
    }

    /**
     * The required spaces without a file are the ones that block a generation.
     */
    public function test_missing_required_lists_only_required_spaces_without_file(): void {
        $this->resetAfterTest();
        $first = $this->stored('first.pdf', '1');
        $second = $this->stored('second.pdf', '2');
        $third = $this->stored('third.pdf', '3');
        $mine = $this->stored('mine.pdf', 'M');
        $selection = new space_selection(
            [$this->space(10, $first, true), $this->space(11, $second, true), $this->space(12, $third, false)],
            [11 => $mine]
        );

        $missing = $selection->missing_required();

        $this->assertSame([10], array_map(static fn($space) => $space->cmid, $missing));
    }

    /**
     * A selection without spaces owns nothing.
     */
    public function test_an_empty_selection_owns_nothing(): void {
        $this->resetAfterTest();
        $file = $this->stored('a.pdf', 'A');
        $selection = new space_selection([], []);

        $this->assertFalse($selection->owns($file));
        $this->assertSame([], $selection->missing_required());
    }
}
