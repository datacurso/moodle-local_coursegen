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

use local_coursegen\local\preview\kept_activity;

/**
 * Unit tests for kept_activity::to_parameters().
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\kept_activity
 */
final class kept_activity_test extends \advanced_testcase {
    /**
     * A non-lesson activity's parameters are its own intro, in the shape
     * intro_preview::render() reads.
     */
    public function test_non_lesson_activity_returns_its_intro(): void {
        $this->resetAfterTest(true);

        $activity = $this->page_activity('My Page', '<p>Welcome</p>');

        $parameters = kept_activity::to_parameters($activity);

        $this->assertSame('My Page', $parameters['name']);
        $this->assertSame('<p>Welcome</p>', $parameters['page']);
        $this->assertSame('<p>Welcome</p>', $parameters['introeditor']['text']);
    }

    /**
     * A non-lesson activity with no intro at all still returns the shape,
     * with an empty string rather than failing.
     */
    public function test_non_lesson_activity_with_no_intro(): void {
        $this->resetAfterTest(true);

        $activity = $this->page_activity('Empty Page', null);

        $parameters = kept_activity::to_parameters($activity);

        $this->assertSame('', $parameters['page']);
    }

    /**
     * A lesson's pages come back walked in prevpageid/nextpageid order, not
     * in whatever order the backup happened to list them.
     */
    public function test_lesson_pages_are_walked_in_chain_order(): void {
        $this->resetAfterTest(true);

        $first = $this->lesson_page(101, 'First', '0', '102');
        $second = $this->lesson_page(102, 'Second', '101', '0');
        // Listed backwards on purpose, to prove the chain - not the list
        // order - decides the result.
        $activity = $this->lesson_activity('My Lesson', [$second, $first]);

        $parameters = kept_activity::to_parameters($activity);
        $pages = $parameters['mod_settings']['pages'];

        $this->assertSame('First', $pages[0]['title']);
        $this->assertSame('Second', $pages[1]['title']);
    }

    /**
     * A page whose chain is broken - nothing points to it, and it is not
     * the head either - is still shown, appended after the walked pages.
     */
    public function test_orphan_page_is_appended_after_the_chain(): void {
        $this->resetAfterTest(true);

        $first = $this->lesson_page(101, 'First', '0', '0');
        $orphan = $this->lesson_page(999, 'Orphan', '500', '600');
        $activity = $this->lesson_activity('My Lesson', [$first, $orphan]);

        $parameters = kept_activity::to_parameters($activity);
        $pages = $parameters['mod_settings']['pages'];

        $this->assertCount(2, $pages);
        $this->assertSame('First', $pages[0]['title']);
        $this->assertSame('Orphan', $pages[1]['title']);
    }

    /**
     * A lesson with no pages at all returns an empty page list, not an
     * error.
     */
    public function test_lesson_with_no_pages_returns_empty_list(): void {
        $this->resetAfterTest(true);

        $activity = $this->lesson_activity('Empty Lesson', []);

        $parameters = kept_activity::to_parameters($activity);

        $this->assertSame([], $parameters['mod_settings']['pages']);
    }

    /**
     * A page's answers become its navigation buttons, each carrying the
     * answer's own text and jump target.
     */
    public function test_page_answers_become_buttons(): void {
        $this->resetAfterTest(true);

        $page = $this->lesson_page(101, 'First', '0', '0');
        $page['answers'] = [
            ['answer' => [
                ['answer_text' => 'Continue', 'jumpto' => 102],
            ]],
        ];
        $activity = $this->lesson_activity('My Lesson', [$page]);

        $parameters = kept_activity::to_parameters($activity);
        $buttons = $parameters['mod_settings']['pages'][0]['buttons'];

        $this->assertSame('Continue', $buttons[0]['text']);
        $this->assertSame(102, $buttons[0]['jumpto']);
    }

    /**
     * A raw lesson backup page node, with no answers.
     *
     * @param int $id
     * @param string $title
     * @param string $prevpageid
     * @param string $nextpageid
     * @return array
     */
    private function lesson_page(int $id, string $title, string $prevpageid, string $nextpageid): array {
        return [
            'id' => $id,
            'title' => $title,
            'contents' => "<p>{$title}</p>",
            'layout' => 1,
            'qtype' => 20,
            'display' => 1,
            'prevpageid' => $prevpageid,
            'nextpageid' => $nextpageid,
            'answers' => [],
        ];
    }

    /**
     * A payload activity entry for a kept lesson, wrapping the given raw
     * pages in the backup's own group.
     *
     * @param string $name
     * @param array $pages Raw page nodes, from lesson_page().
     * @return array
     */
    private function lesson_activity(string $name, array $pages): array {
        return [
            'resource_type' => 'lesson',
            'parameters' => [
                'name' => $name,
                'structure' => [
                    'lesson' => [
                        ['pages' => [['page' => $pages]]],
                    ],
                ],
            ],
        ];
    }

    /**
     * A payload activity entry for a kept page module.
     *
     * @param string $name
     * @param string|null $intro
     * @return array
     */
    private function page_activity(string $name, ?string $intro): array {
        $root = [];
        if ($intro !== null) {
            $root['intro'] = $intro;
        }
        return [
            'resource_type' => 'page',
            'parameters' => [
                'name' => $name,
                'structure' => [
                    'page' => [$root],
                ],
            ],
        ];
    }
}
