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

use local_coursegen\local\preview\json_store_builder;

/**
 * Unit tests for json_store_builder::build().
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\json_store_builder
 */
final class json_store_builder_test extends \basic_testcase {
    /**
     * One top-level element with a known table becomes one row of it.
     */
    public function test_single_element_becomes_one_row(): void {
        $tree = [
            'lesson' => [
                ['id' => 5, 'name' => 'Lesson One'],
            ],
        ];
        $tables = ['lesson' => 'lesson'];

        $rows = json_store_builder::build($tree, $tables, []);

        $this->assertCount(1, $rows['lesson']);
        $this->assertSame(5, $rows['lesson'][0]->id);
        $this->assertSame('Lesson One', $rows['lesson'][0]->name);
    }

    /**
     * An element with no known table never becomes a row of anything, but
     * its own children - real rows further down - still get walked.
     */
    public function test_element_with_no_table_produces_no_row_but_children_still_walk(): void {
        $tree = [
            'activity' => [
                [
                    'wrapper' => [
                        [
                            'leaf' => [
                                ['id' => 1, 'value' => 'A'],
                                ['id' => 2, 'value' => 'B'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $tables = ['leaf' => 'leaf_table'];

        $rows = json_store_builder::build($tree, $tables, []);

        $this->assertArrayNotHasKey('activity', $rows);
        $this->assertArrayNotHasKey('wrapper', $rows);
        $this->assertCount(2, $rows['leaf_table']);
        $this->assertSame(['A', 'B'], array_column($rows['leaf_table'], 'value'));
    }

    /**
     * A child row carries its parent's id, under both the parent's own name
     * and that name suffixed "id" - the two names Moodle tables use for the
     * same link.
     */
    public function test_child_row_gets_parent_ancestor_columns(): void {
        $tree = [
            'lesson' => [
                [
                    'id' => 5,
                    'pages' => [
                        ['id' => 10, 'title' => 'Page One'],
                    ],
                ],
            ],
        ];
        $tables = ['lesson' => 'lesson', 'pages' => 'lesson_pages'];

        $rows = json_store_builder::build($tree, $tables, []);
        $page = $rows['lesson_pages'][0];

        $this->assertSame(10, $page->id);
        $this->assertSame(5, $page->lessonid);
        $this->assertSame(5, $page->lesson);
    }

    /**
     * A grandchild row carries every ancestor's id, not only its immediate
     * parent's - an answer belongs to its page and to its lesson alike.
     */
    public function test_grandchild_row_gets_both_ancestor_levels_folded_in(): void {
        $tree = [
            'lesson' => [
                [
                    'id' => 5,
                    'pages' => [
                        [
                            'id' => 10,
                            'answers' => [
                                ['id' => 100, 'text' => 'An answer'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $tables = ['lesson' => 'lesson', 'pages' => 'lesson_pages', 'answers' => 'lesson_answers'];

        $rows = json_store_builder::build($tree, $tables, []);
        $answer = $rows['lesson_answers'][0];

        $this->assertSame(100, $answer->id);
        $this->assertSame(10, $answer->pagesid);
        $this->assertSame(10, $answer->pages);
        $this->assertSame(5, $answer->lessonid);
        $this->assertSame(5, $answer->lesson);
    }

    /**
     * Every occurrence of a repeated child element becomes its own row.
     */
    public function test_repeated_child_element_produces_one_row_each(): void {
        $tree = [
            'lesson' => [
                [
                    'id' => 5,
                    'pages' => [
                        ['id' => 10, 'title' => 'Page One'],
                        ['id' => 11, 'title' => 'Page Two'],
                        ['id' => 12, 'title' => 'Page Three'],
                    ],
                ],
            ],
        ];
        $tables = ['lesson' => 'lesson', 'pages' => 'lesson_pages'];

        $rows = json_store_builder::build($tree, $tables, []);

        $this->assertCount(3, $rows['lesson_pages']);
        $this->assertSame([10, 11, 12], array_column($rows['lesson_pages'], 'id'));
    }

    /**
     * A column declared under an alias is renamed to the table's own column
     * on the way into the row.
     */
    public function test_aliased_column_is_renamed_via_aliases_map(): void {
        $tree = [
            'lesson' => [
                ['id' => 5, 'contents' => 'Body text'],
            ],
        ];
        $tables = ['lesson' => 'lesson'];
        $aliases = ['lesson' => ['contents' => 'page']];

        $rows = json_store_builder::build($tree, $tables, $aliases);
        $row = $rows['lesson'][0];

        $this->assertSame('Body text', $row->page);
        $this->assertFalse(property_exists($row, 'contents'));
    }
}
