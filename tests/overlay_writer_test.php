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

use local_coursegen\local\backup\activity_reader;
use local_coursegen\local\structure\overlay_writer;
use local_coursegen\local\structure\tree_changes;

/**
 * Tests for overlay_writer against the real rows of a book.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\structure\overlay_writer
 * @covers     \local_coursegen\local\structure\overlay_result
 */
final class overlay_writer_test extends \advanced_testcase {
    /**
     * A book with two chapters, read as its module declares it.
     *
     * @return array {read: the tree with its tables and aliases, chapters: the two chapter records}
     */
    private function book_with_chapters(): array {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $book = $generator->create_module('book', ['course' => $course->id, 'intro' => '<p>Old intro</p>']);
        $bookgenerator = $generator->get_plugin_generator('mod_book');
        $first = $bookgenerator->create_chapter(['bookid' => $book->id, 'title' => 'One', 'content' => '<p>First</p>']);
        $second = $bookgenerator->create_chapter(['bookid' => $book->id, 'title' => 'Two', 'content' => '<p>Second</p>']);
        $modinfo = get_fast_modinfo($course);
        $cm = $modinfo->get_cm($book->cmid);
        return ['read' => activity_reader::read_with_sources($cm), 'chapters' => [$first, $second]];
    }

    /**
     * Write the changes between a tree and its rewritten version onto the rows the tree was read from.
     *
     * @param array $read The tree of the activity with its tables and aliases.
     * @param array $rewritten The rewritten tree.
     * @param array|null $copytree The tree whose rows are written; the same tree when null.
     * @return \local_coursegen\local\structure\overlay_result
     */
    private function overlay(array $read, array $rewritten, ?array $copytree = null) {
        $changes = tree_changes::between($read['tree'], $rewritten);
        $writer = new overlay_writer();
        $target = $copytree ?? $read['tree'];
        return $writer->apply($changes, $target, $read['tables'], $read['aliases']);
    }

    /**
     * The rewritten texts of the root row and of every chapter reach the rows.
     */
    public function test_rewritten_texts_are_written_to_their_rows(): void {
        global $DB;

        $made = $this->book_with_chapters();
        $rewritten = $made['read']['tree'];
        $rewritten['book'][0]['intro'] = '<p>New intro</p>';
        $rewritten['book'][0]['chapters'][0]['chapter'][0]['title'] = 'Uno';
        $rewritten['book'][0]['chapters'][0]['chapter'][1]['content'] = '<p>Segundo</p>';

        $result = $this->overlay($made['read'], $rewritten);

        $actual = $result->count_written();
        $this->assertSame(3, $actual);
        $actual = $result->skipped_paths();
        $this->assertSame([], $actual);
        $first = $made['chapters'][0];
        $second = $made['chapters'][1];
        $actual = $DB->get_field('book_chapters', 'title', ['id' => $first->id]);
        $this->assertSame('Uno', $actual);
        $actual = $DB->get_field('book_chapters', 'title', ['id' => $second->id]);
        $this->assertSame('One', $actual);
        $actual = $DB->get_field('book_chapters', 'content', ['id' => $second->id]);
        $this->assertSame('<p>Segundo</p>', $actual);
        $bookid = $made['read']['tree']['book'][0]['id'];
        $actual = $DB->get_field('book', 'intro', ['id' => $bookid]);
        $this->assertSame('<p>New intro</p>', $actual);
    }

    /**
     * A column that is not text is left as it is, and the texts around it are still written.
     */
    public function test_a_column_that_is_not_text_is_left_alone(): void {
        global $DB;

        $made = $this->book_with_chapters();
        $rewritten = $made['read']['tree'];
        $rewritten['book'][0]['chapters'][0]['chapter'][0]['pagenum'] = '9';
        $rewritten['book'][0]['chapters'][0]['chapter'][0]['title'] = 'Uno';
        $first = $made['chapters'][0];
        $before = $DB->get_field('book_chapters', 'pagenum', ['id' => $first->id]);

        $result = $this->overlay($made['read'], $rewritten);

        $actual = $result->count_written();
        $this->assertSame(1, $actual);
        $actual = $result->skipped_paths();
        $this->assertSame(['book/0/chapters/0/chapter/0/pagenum'], $actual);
        $actual = $DB->get_field('book_chapters', 'pagenum', ['id' => $first->id]);
        $this->assertEquals($before, $actual);
        $actual = $DB->get_field('book_chapters', 'title', ['id' => $first->id]);
        $this->assertSame('Uno', $actual);
    }

    /**
     * A text longer than its column is left as it is and does not stop the others.
     */
    public function test_a_text_longer_than_its_column_is_skipped(): void {
        global $DB;

        $made = $this->book_with_chapters();
        $rewritten = $made['read']['tree'];
        $rewritten['book'][0]['chapters'][0]['chapter'][0]['title'] = str_repeat('a', 400);
        $rewritten['book'][0]['chapters'][0]['chapter'][0]['content'] = '<p>Longer body</p>';
        $first = $made['chapters'][0];

        $result = $this->overlay($made['read'], $rewritten);

        $actual = $result->count_written();
        $this->assertSame(1, $actual);
        $actual = $result->skipped_paths();
        $this->assertSame(['book/0/chapters/0/chapter/0/title'], $actual);
        $actual = $DB->get_field('book_chapters', 'title', ['id' => $first->id]);
        $this->assertSame('One', $actual);
        $actual = $DB->get_field('book_chapters', 'content', ['id' => $first->id]);
        $this->assertSame('<p>Longer body</p>', $actual);
    }

    /**
     * A text whose row the copy does not have is left as it is.
     */
    public function test_a_text_without_a_row_in_the_copy_is_skipped(): void {
        $made = $this->book_with_chapters();
        $rewritten = $made['read']['tree'];
        $rewritten['book'][0]['chapters'][0]['chapter'][1]['title'] = 'Dos';
        $copytree = $made['read']['tree'];
        unset($copytree['book'][0]['chapters'][0]['chapter'][1]);

        $result = $this->overlay($made['read'], $rewritten, $copytree);

        $actual = $result->count_written();
        $this->assertSame(0, $actual);
        $actual = $result->skipped_paths();
        $this->assertSame(['book/0/chapters/0/chapter/1/title'], $actual);
    }

    /**
     * Without changes nothing is written and nothing is skipped.
     */
    public function test_no_changes_write_nothing(): void {
        $made = $this->book_with_chapters();

        $result = $this->overlay($made['read'], $made['read']['tree']);

        $actual = $result->count_written();
        $this->assertSame(0, $actual);
        $actual = $result->skipped_paths();
        $this->assertSame([], $actual);
    }

    /**
     * A column the table does not have is left as it is.
     */
    public function test_a_column_the_table_does_not_have_is_skipped(): void {
        $made = $this->book_with_chapters();
        $rewritten = $made['read']['tree'];
        $rewritten['book'][0]['chapters'][0]['chapter'][0]['nosuchcolumn'] = 'x';
        $tree = $made['read']['tree'];
        $tree['book'][0]['chapters'][0]['chapter'][0]['nosuchcolumn'] = 'y';
        $read = $made['read'];
        $read['tree'] = $tree;

        $result = $this->overlay($read, $rewritten);

        $actual = $result->count_written();
        $this->assertSame(0, $actual);
        $actual = $result->skipped_paths();
        $this->assertCount(1, $actual);
    }
}
