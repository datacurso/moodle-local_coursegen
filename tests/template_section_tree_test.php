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

use local_coursegen\local\service\template_section_tree;

/**
 * The tree a flexsections course is made of, built from the parent each section carries.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_section_tree
 */
final class template_section_tree_test extends \advanced_testcase {
    /**
     * The sections of the example course: two top sections, two units with two children each, a closing section.
     *
     * @return array Entries as sections_info describes them.
     */
    private function entries(): array {
        $parents = [0 => 0, 1 => 0, 2 => 0, 3 => 2, 4 => 2, 5 => 0, 6 => 5, 7 => 5, 8 => 0];
        $entries = [];
        foreach ($parents as $number => $parent) {
            $entries[] = ['section' => $number, 'name' => 'S' . $number, 'format_options' => ['parent' => $parent]];
        }
        return $entries;
    }

    public function test_parent_and_children_follow_the_parent_option(): void {
        $tree = template_section_tree::from_entries($this->entries());

        $this->assertSame(2, $tree->parent_of(3));
        $this->assertSame([3, 4], $tree->children_of(2));
        $this->assertSame([], $tree->children_of(8));
    }

    public function test_the_explicit_parent_section_wins_over_the_format_option(): void {
        $entries = $this->entries();
        $entries[6]['parent_section'] = 2;

        $tree = template_section_tree::from_entries($entries);

        $this->assertSame(2, $tree->parent_of(6));
    }

    public function test_depth_counts_the_levels_under_the_top(): void {
        $entries = $this->entries();
        $entries[] = ['section' => 9, 'name' => 'S9', 'format_options' => ['parent' => 7]];

        $tree = template_section_tree::from_entries($entries);

        $this->assertSame(0, $tree->depth_of(2));
        $this->assertSame(1, $tree->depth_of(3));
        $this->assertSame(2, $tree->depth_of(9));
    }

    public function test_subtree_is_the_section_and_every_descendant_in_order(): void {
        $entries = $this->entries();
        $entries[] = ['section' => 9, 'name' => 'S9', 'format_options' => ['parent' => 7]];

        $tree = template_section_tree::from_entries($entries);

        $this->assertSame([5, 6, 7, 9], $tree->subtree_of(5));
        $this->assertSame([8], $tree->subtree_of(8));
    }

    public function test_units_are_the_top_sections_that_have_children(): void {
        $tree = template_section_tree::from_entries($this->entries());

        $this->assertSame([2, 5], $tree->units());
    }

    public function test_a_parent_that_is_missing_or_not_before_its_child_counts_as_top_level(): void {
        $entries = [
            ['section' => 0, 'format_options' => []],
            ['section' => 1, 'format_options' => ['parent' => 7]],
            ['section' => 2, 'format_options' => ['parent' => 2]],
            ['section' => 3, 'format_options' => ['parent' => 4]],
        ];

        $tree = template_section_tree::from_entries($entries);

        $this->assertSame(0, $tree->parent_of(1));
        $this->assertSame(0, $tree->parent_of(2));
        $this->assertSame(0, $tree->parent_of(3));
        $this->assertDebuggingCalledCount(3);
    }

    public function test_a_valid_parent_is_not_reported(): void {
        template_section_tree::from_entries([
            ['section' => 0],
            ['section' => 1],
            ['section' => 2, 'parent_section' => 1],
        ]);

        $this->assertDebuggingNotCalled();
    }

    public function test_entries_without_a_parent_make_a_flat_tree(): void {
        $tree = template_section_tree::from_entries([['section' => 0], ['section' => 1], ['section' => 2]]);

        $this->assertSame([], $tree->units());
        $this->assertSame(0, $tree->depth_of(2));
    }
}
