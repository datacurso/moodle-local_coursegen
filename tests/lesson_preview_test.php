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
        $lesson = ['id' => 5, 'course' => 1, 'name' => 'A lesson', 'pages' => [['page' => $pages]]];
        $source = [
            'uid' => 'lesson-uid',
            'cmid' => 0,
            'parameters' => [
                'structure' => ['lesson' => [$lesson]],
                'structure_tables' => ['lesson' => 'lesson', 'page' => 'lesson_pages'],
                'structure_aliases' => [],
            ],
        ];
        $preview = new lesson_preview(['name' => 'A lesson'], $source);
        $here = new moodle_url('/local/coursegen/activity_preview.php', ['sessionid' => 'abc']);
        $preview->opened_at($here, $index);
        return $preview;
    }
}
