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

use local_coursegen\local\preview\lesson_preview;
use moodle_url;

/**
 * Tests for the lesson preview.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\lesson_preview
 */
final class lesson_preview_test extends \advanced_testcase {
    /**
     * Opening a lesson page builds the lesson without a callable type error
     * and draws the page being read.
     */
    public function test_render_draws_the_page_being_read(): void {
        $this->resetAfterTest(true);

        $preview = $this->preview_opened_at(0);

        $html = $preview->render();

        $this->assertStringContainsString('First page body', $html);
    }

    /**
     * The page index in the address picks which page is drawn.
     */
    public function test_render_draws_the_page_at_the_requested_index(): void {
        $this->resetAfterTest(true);

        $preview = $this->preview_opened_at(1);

        $html = $preview->render();

        $this->assertStringContainsString('Second page body', $html);
        $this->assertStringNotContainsString('First page body', $html);
    }

    /**
     * A lesson without pages has nothing to draw and still does not fail.
     */
    public function test_render_without_pages_does_not_fail(): void {
        $this->resetAfterTest(true);

        $preview = $this->preview_opened_at(0, []);

        $html = $preview->render();

        $this->assertIsString($html);
        $this->assertSame([], $preview->side_blocks());
    }

    /**
     * Two pages that share a title each show their own content, and no marker.
     */
    public function test_pages_with_the_same_title_each_show_their_own_content(): void {
        $this->resetAfterTest(true);
        $pages = [
            ['id' => 11, 'title' => 'Week 7', 'contents' => 'Alpha body', 'qtype' => 20,
                'prevpageid' => 0, 'nextpageid' => 12, 'contentsformat' => FORMAT_HTML],
            ['id' => 12, 'title' => 'Week 7', 'contents' => 'Beta body', 'qtype' => 20,
                'prevpageid' => 11, 'nextpageid' => 0, 'contentsformat' => FORMAT_HTML],
        ];

        $first = $this->preview_opened_at(0, $pages)->render();
        $second = $this->preview_opened_at(1, $pages)->render();

        $this->assertStringContainsString('Alpha body', $first);
        $this->assertStringNotContainsString('Beta body', $first);
        $this->assertStringContainsString('Beta body', $second);
        $this->assertStringNotContainsString('Alpha body', $second);
        $this->assertStringNotContainsString('[[coursegen:', $first . $second);
    }

    /**
     * A page the AI added sits in the tree under its own id, after the pages of the template.
     */
    public function test_a_page_added_by_the_ai_is_drawn_from_its_own_row(): void {
        $this->resetAfterTest(true);
        $pages = [
            ['id' => 11, 'title' => 'First', 'contents' => 'First page body', 'qtype' => 20,
                'prevpageid' => 0, 'nextpageid' => 900001, 'contentsformat' => FORMAT_HTML],
            ['id' => 900001, 'title' => 'Added', 'contents' => 'Added page body', 'qtype' => 20,
                'prevpageid' => 11, 'nextpageid' => 0, 'contentsformat' => FORMAT_HTML],
        ];

        $html = $this->preview_opened_at(1, $pages)->render();

        $this->assertStringContainsString('Added page body', $html);
    }

    /**
     * The preview needs nothing but its own parameters: no template activity is handed to it.
     */
    public function test_the_preview_is_built_from_its_parameters_alone(): void {
        $this->resetAfterTest(true);
        $parameters = $this->parameters_with_pages([
            ['id' => 11, 'title' => 'Only', 'contents' => 'Only page body', 'qtype' => 20,
                'prevpageid' => 0, 'nextpageid' => 0, 'contentsformat' => FORMAT_HTML],
        ]);

        $preview = new lesson_preview($parameters);
        $preview->opened_at(new moodle_url('/local/coursegen/activity_preview.php', ['sessionid' => 'abc']), 0);

        $this->assertStringContainsString('Only page body', $preview->render());
    }

    /**
     * The parameters of a finished lesson, with the tree its own result carries.
     *
     * @param array $pages Page rows.
     * @return array
     */
    private function parameters_with_pages(array $pages): array {
        $lesson = ['id' => 5, 'course' => 1, 'name' => 'A lesson', 'pages' => [['page' => $pages]]];
        return [
            'name' => 'A lesson',
            'structure' => ['lesson' => [$lesson]],
            'structure_tables' => ['lesson' => 'lesson', 'page' => 'lesson_pages'],
            'structure_aliases' => [],
        ];
    }

    /**
     * Building the preview of a lesson, opened at a page of the session.
     *
     * @param int $index
     * @param array|null $pages Page rows; two content pages when omitted.
     * @return lesson_preview
     */
    private function preview_opened_at(int $index, ?array $pages = null): lesson_preview {
        if ($pages === null) {
            $pages = [
                ['id' => 11, 'title' => 'First', 'contents' => 'First page body', 'qtype' => 20,
                    'prevpageid' => 0, 'nextpageid' => 12, 'contentsformat' => FORMAT_HTML],
                ['id' => 12, 'title' => 'Second', 'contents' => 'Second page body', 'qtype' => 20,
                    'prevpageid' => 11, 'nextpageid' => 0, 'contentsformat' => FORMAT_HTML],
            ];
        }
        $preview = new lesson_preview($this->parameters_with_pages($pages));
        $here = new moodle_url('/local/coursegen/activity_preview.php', ['sessionid' => 'abc']);
        $preview->opened_at($here, $index);
        return $preview;
    }
}
