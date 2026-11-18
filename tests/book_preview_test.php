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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/fixtures/preview_page_setup.php');

/**
 * Tests for the book preview, drawn from the tree its result carries.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\book_preview
 */
final class book_preview_test extends \advanced_testcase {
    use preview_page_setup;

    /**
     * The parameters of a finished book, with the tree its own result carries.
     *
     * @param array $chapters Chapter rows.
     * @return array
     */
    private function parameters_with_chapters(array $chapters): array {
        $book = ['id' => 7, 'course' => 1, 'name' => 'A book', 'numbering' => 1, 'navstyle' => 1, 'customtitles' => 0,
            'intro' => '', 'introformat' => 1, 'chapters' => [['chapter' => $chapters]]];
        return [
            'name' => 'A book',
            'structure' => ['book' => [$book]],
            'structure_tables' => ['book' => 'book', 'chapter' => 'book_chapters'],
            'structure_aliases' => [],
        ];
    }

    /**
     * One chapter row.
     *
     * @param int $id
     * @param int $pagenum
     * @param string $title
     * @param string $content
     * @return array
     */
    private function chapter(int $id, int $pagenum, string $title, string $content): array {
        return ['id' => $id, 'pagenum' => $pagenum, 'subchapter' => 0, 'title' => $title, 'content' => $content,
            'contentformat' => FORMAT_HTML, 'hidden' => 0];
    }

    /**
     * Two chapters that share a title each show their own content, and no marker.
     */
    public function test_chapters_with_the_same_title_each_show_their_own_content(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();
        $parameters = $this->parameters_with_chapters([
            $this->chapter(21, 1, 'Chapter', '<p>Alpha chapter body</p>'),
            $this->chapter(22, 2, 'Chapter', '<p>Beta chapter body</p>'),
        ]);

        $first = $this->text_of($this->preview_of('book', $parameters, 0)->render());
        $second = $this->text_of($this->preview_of('book', $parameters, 1)->render());

        $this->assertStringContainsString('Alpha chapter body', $first);
        $this->assertStringNotContainsString('Beta chapter body', $first);
        $this->assertStringContainsString('Beta chapter body', $second);
        $this->assertStringNotContainsString('Alpha chapter body', $second);
        $this->assertStringNotContainsString('[[coursegen:', $first . $second);
    }

    /**
     * A chapter the AI added sits in the tree under its own id.
     */
    public function test_a_chapter_added_by_the_ai_is_drawn_from_its_own_row(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();
        $parameters = $this->parameters_with_chapters([
            $this->chapter(21, 1, 'Chapter', '<p>Alpha chapter body</p>'),
            $this->chapter(900001, 2, 'Added', '<p>Added chapter body</p>'),
        ]);

        $html = $this->text_of($this->preview_of('book', $parameters, 1)->render());

        $this->assertStringContainsString('Added chapter body', $html);
    }

    /**
     * A book whose tree holds no chapter says so instead of failing.
     */
    public function test_a_book_without_chapters_does_not_fail(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();

        $html = $this->preview_of('book', $this->parameters_with_chapters([]), 0)->render();

        $this->assertIsString($html);
    }
}
