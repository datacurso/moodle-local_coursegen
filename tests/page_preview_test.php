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
 * Tests for the page preview, drawn from the row its result carries.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\page_preview
 */
final class page_preview_test extends \advanced_testcase {
    use preview_page_setup;

    /**
     * The parameters of a finished page whose tree holds the given content.
     *
     * @param string $content
     * @return array
     */
    private function parameters_with_content(string $content): array {
        $page = ['id' => 6, 'course' => 1, 'name' => 'A page', 'intro' => '', 'introformat' => 1, 'content' => $content,
            'contentformat' => FORMAT_HTML, 'revision' => 1, 'display' => 5, 'displayoptions' => 'a:0:{}',
            'timemodified' => 0];
        return [
            'name' => 'A page',
            'structure' => ['page' => [$page]],
            'structure_tables' => ['page' => 'page'],
            'structure_aliases' => [],
        ];
    }

    /**
     * The text of a page is the one its result carries, with nothing of the template.
     */
    public function test_the_page_shows_the_content_its_result_carries(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();
        $parameters = $this->parameters_with_content('<p>Generated page text</p>');

        $html = $this->text_of($this->preview_of('page', $parameters)->render());

        $this->assertStringContainsString('Generated page text', $html);
        $this->assertStringNotContainsString('[[coursegen:', $html);
    }

    /**
     * Without a row of its module a page has nothing to show and says so.
     */
    public function test_a_result_without_a_page_row_has_nothing_to_show(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();

        $html = $this->preview_of('page', ['name' => 'A page'])->render();

        $this->assertStringContainsString(get_string('courseai_preview_empty', 'local_coursegen'), $html);
    }
}
