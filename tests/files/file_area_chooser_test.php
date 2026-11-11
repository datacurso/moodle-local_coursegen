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

/**
 * Which file area of a row a text column keeps its files in.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\files\file_area_chooser
 */
final class file_area_chooser_test extends \advanced_testcase {
    /**
     * A row of a table with the given areas of one component.
     *
     * @param string $table
     * @param string $component
     * @param string[] $fileareas
     * @return text_carrier
     */
    private function carrier(string $table, string $component, array $fileareas): text_carrier {
        $areas = [];
        foreach ($fileareas as $filearea) {
            $areas[] = new file_area(5, $component, $filearea, 9);
        }
        return new text_carrier($table, 9, $areas);
    }

    /**
     * Where a column's files go, as the name of the area.
     *
     * @param text_carrier $carrier
     * @param string $column
     * @param \stored_file|null $source
     * @return string
     */
    private function chosen(text_carrier $carrier, string $column, ?\stored_file $source = null): string {
        $chooser = new file_area_chooser();
        return $chooser->choose($carrier, $column, $source, 'here', 'a.png')->filearea;
    }

    /**
     * The area is the column's own when a row declares several.
     *
     * @dataProvider named_provider
     * @param string $table
     * @param string $component
     * @param string[] $fileareas
     * @param string $column
     * @param string $expected
     */
    public function test_the_area_named_after_the_column_is_chosen(
        string $table,
        string $component,
        array $fileareas,
        string $column,
        string $expected
    ): void {
        $carrier = $this->carrier($table, $component, $fileareas);

        $this->assertSame($expected, $this->chosen($carrier, $column));
    }

    /**
     * Rows with several areas, and the column each text is in.
     *
     * @return array
     */
    public static function named_provider(): array {
        return [
            'page intro' => ['page', 'mod_page', ['intro', 'content'], 'intro', 'intro'],
            'page content' => ['page', 'mod_page', ['intro', 'content'], 'content', 'content'],
            'workshop instructions' => [
                'workshop', 'mod_workshop', ['intro', 'instructauthors', 'instructreviewers', 'conclusion'],
                'instructreviewers', 'instructreviewers',
            ],
            'assign activity' => [
                'assign', 'mod_assign', ['intro', 'introattachment', 'activityattachment'], 'activity', 'activityattachment',
            ],
            'assign intro is not its attachment' => [
                'assign', 'mod_assign', ['intro', 'introattachment', 'activityattachment'], 'intro', 'intro',
            ],
            'lesson answer' => ['lesson_answers', 'mod_lesson', ['page_answers', 'page_responses'], 'answer', 'page_answers'],
            'lesson response' => ['lesson_answers', 'mod_lesson', ['page_answers', 'page_responses'], 'response', 'page_responses'],
            'answer feedback' => ['question_answers', 'question', ['answer', 'answerfeedback'], 'feedback', 'answerfeedback'],
            'feedback final page' => [
                'feedback', 'mod_feedback', ['intro', 'page_after_submit'], 'page_after_submit', 'page_after_submit',
            ],
        ];
    }

    /**
     * The two columns whose area does not carry their name are registered.
     */
    public function test_registered_columns_use_their_registered_area(): void {
        $post = $this->carrier('forum_posts', 'mod_forum', ['post', 'attachment']);
        $entry = $this->carrier('glossary_entries', 'mod_glossary', ['entry', 'attachment']);

        $this->assertSame('post', $this->chosen($post, 'message'));
        $this->assertSame('entry', $this->chosen($entry, 'definition'));
    }

    /**
     * A row with one area keeps every text there, whatever the column is called.
     */
    public function test_a_row_with_one_area_uses_it(): void {
        $chapter = $this->carrier('book_chapters', 'mod_book', ['chapter']);
        $item = $this->carrier('feedback_item', 'mod_feedback', ['item']);

        $this->assertSame('chapter', $this->chosen($chapter, 'content'));
        $this->assertSame('item', $this->chosen($item, 'presentation'));
    }

    /**
     * When the name does not decide, the area the source file was in does.
     */
    public function test_the_area_of_the_source_file_decides_when_the_name_does_not(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $label = $this->getDataGenerator()->create_module('label', ['course' => $course->id]);
        $context = \context_module::instance($label->cmid);
        $source = get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_x', 'filearea' => 'second', 'itemid' => 0,
            'filepath' => '/', 'filename' => 'a.png',
        ], 'A');
        $carrier = new text_carrier('x', 1, [
            new file_area(5, 'mod_x', 'first', 0),
            new file_area(5, 'mod_x', 'second', 0),
        ]);

        $this->assertSame('second', $this->chosen($carrier, 'body', $source));
    }

    /**
     * Without anything to decide, the choice is refused and says where.
     */
    public function test_an_undecidable_column_raises(): void {
        $carrier = new text_carrier('x', 1, [
            new file_area(5, 'mod_x', 'first', 0),
            new file_area(5, 'mod_x', 'second', 0),
        ]);

        try {
            $this->chosen($carrier, 'body');
            $this->fail('The choice had to fail');
        } catch (file_copy_exception $exception) {
            $this->assertSame('error_file_area_unknown', $exception->errorcode);
            $this->assertSame('here', $exception->a['where']);
            $this->assertSame('a.png', $exception->a['file']);
        }
    }

    /**
     * A row that declares no area has nowhere to keep a file.
     */
    public function test_a_row_without_areas_raises(): void {
        $this->expectException(file_copy_exception::class);

        $this->chosen(new text_carrier('x', 1, []), 'body');
    }

    /**
     * The templates of a database are shown as written, so no file can be served from them.
     */
    public function test_data_templates_hold_no_files(): void {
        $chooser = new file_area_chooser();

        $this->assertFalse($chooser->holds_files('data', 'singletemplate'));
        $this->assertFalse($chooser->holds_files('data', 'jstemplate'));
        $this->assertTrue($chooser->holds_files('data', 'intro'));
        $this->assertTrue($chooser->holds_files('page', 'singletemplate'));
    }
}
