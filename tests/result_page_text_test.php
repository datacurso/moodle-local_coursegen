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

namespace local_coursegen\local\preview;

/**
 * The text the AI wrote into a page, laid where the preview of the page reads it.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\result_page_text
 */
final class result_page_text_test extends \advanced_testcase {
    /**
     * The parameters of a page as the run echoes them: the template tree, and the text the AI wrote beside it.
     *
     * @param array $flat The text the AI wrote.
     * @return array
     */
    private function page_parameters(array $flat): array {
        return [
            'name' => 'Guide',
            'structure' => ['page' => [['id' => 7, 'name' => 'Guide', 'intro' => '<p>old intro</p>', 'content' => '<p>old</p>']]],
        ] + $flat;
    }

    /**
     * The text of the AI replaces the text of the template in the tree the preview reads.
     */
    public function test_the_text_the_ai_wrote_replaces_the_template_text_in_the_tree(): void {
        $parameters = $this->page_parameters(['content' => '<p>new</p>', 'intro' => '<p>new intro</p>']);

        $laid = result_page_text::laid_in('page', $parameters, []);

        $this->assertSame('<p>new</p>', $laid['structure']['page'][0]['content']);
        $this->assertSame('<p>new intro</p>', $laid['structure']['page'][0]['intro']);
        $this->assertSame(7, $laid['structure']['page'][0]['id']);
    }

    /**
     * A page the AI did not write keeps the tree as it came.
     */
    public function test_a_page_without_new_text_keeps_its_tree(): void {
        $parameters = $this->page_parameters([]);

        $this->assertSame($parameters, result_page_text::laid_in('page', $parameters, []));
    }

    /**
     * An empty text the AI wrote is a text: it empties the page.
     */
    public function test_an_empty_text_empties_the_page(): void {
        $parameters = $this->page_parameters(['content' => '']);

        $laid = result_page_text::laid_in('page', $parameters, []);

        $this->assertSame('', $laid['structure']['page'][0]['content']);
        $this->assertSame('<p>old intro</p>', $laid['structure']['page'][0]['intro']);
    }

    /**
     * Any other type is left alone.
     */
    public function test_another_type_is_left_alone(): void {
        $parameters = ['name' => 'Quiz', 'content' => 'x', 'structure' => ['quiz' => [['id' => 1]]]];

        $this->assertSame($parameters, result_page_text::laid_in('quiz', $parameters, []));
    }

    /**
     * The links the AI left as tokens point to the preview of the activity they name.
     */
    public function test_a_link_token_points_to_the_preview_of_its_activity(): void {
        $parameters = $this->page_parameters(['content' => '<a href="$@COURSEGENLINK*11340@$">guide</a>']);

        $laid = result_page_text::laid_in('page', $parameters, ['11340' => 'https://example.com/p?uid=11340']);

        $this->assertSame(
            '<a href="https://example.com/p?uid=11340">guide</a>',
            $laid['structure']['page'][0]['content']
        );
    }

    /**
     * A file the page shows inside itself is shown from the address of the file, not from the preview of its resource.
     */
    public function test_an_embedded_file_shows_the_file_of_its_resource(): void {
        $parameters = $this->page_parameters(['content' => '<iframe src="$@COURSEGENLINK*11340@$"></iframe>']);

        $laid = result_page_text::laid_in(
            'page',
            $parameters,
            ['11340' => 'https://example.com/p?uid=11340'],
            ['11340' => '/draftfile.php/5/user/draft/9/uid/guide.pdf']
        );

        $this->assertSame(
            '<iframe src="/draftfile.php/5/user/draft/9/uid/guide.pdf"></iframe>',
            $laid['structure']['page'][0]['content']
        );
    }

    /**
     * Without an address for the file, the embedded file keeps the preview of its resource.
     */
    public function test_an_embedded_file_without_an_address_keeps_the_preview_of_its_resource(): void {
        $parameters = $this->page_parameters(['content' => '<iframe src="$@COURSEGENLINK*11340@$"></iframe>']);

        $laid = result_page_text::laid_in('page', $parameters, ['11340' => 'https://example.com/p?uid=11340']);

        $this->assertSame(
            '<iframe src="https://example.com/p?uid=11340"></iframe>',
            $laid['structure']['page'][0]['content']
        );
    }
}
