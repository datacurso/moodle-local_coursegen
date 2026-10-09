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

use local_coursegen\local\structure\tree_changes;

/**
 * Unit tests for tree_changes.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\structure\tree_changes
 * @covers     \local_coursegen\local\structure\tree_change
 */
final class tree_changes_test extends \basic_testcase {
    /**
     * A small tree of a book: the module identity, its row and two chapters.
     *
     * @return array
     */
    private function book_tree(): array {
        return [
            'id' => '9',
            'moduleid' => 103,
            'modulename' => 'book',
            'contextid' => 139,
            'book' => [[
                'id' => '9',
                'name' => 'Old book',
                'intro' => '<p>Old intro</p>',
                'chapters' => [['chapter' => [
                    ['id' => '37', 'title' => 'One', 'content' => '<p>First</p>'],
                    ['id' => '38', 'title' => 'Two', 'content' => '<p>Second</p>'],
                ]]],
            ]],
        ];
    }

    /**
     * Two equal trees have no change.
     */
    public function test_equal_trees_report_nothing(): void {
        $tree = $this->book_tree();

        $actual = tree_changes::between($tree, $tree);
        $this->assertSame([], $actual);
    }

    /**
     * A rewritten text is reported with the path that leads to it and its new value.
     */
    public function test_a_rewritten_text_is_reported_with_its_path(): void {
        $source = $this->book_tree();
        $rewritten = $this->book_tree();
        $rewritten['book'][0]['chapters'][0]['chapter'][1]['content'] = '<p>Second, rewritten</p>';

        $changes = tree_changes::between($source, $rewritten);

        $this->assertCount(1, $changes);
        $this->assertSame(['book', 0, 'chapters', 0, 'chapter', 1, 'content'], $changes[0]->path);
        $this->assertSame('<p>Second, rewritten</p>', $changes[0]->value);
        $actual = $changes[0]->describe();
        $this->assertSame('book/0/chapters/0/chapter/1/content', $actual);
    }

    /**
     * Every rewritten text is reported, in the order of the tree.
     */
    public function test_every_rewritten_text_is_reported_in_tree_order(): void {
        $source = $this->book_tree();
        $rewritten = $this->book_tree();
        $rewritten['book'][0]['name'] = 'New book';
        $rewritten['book'][0]['chapters'][0]['chapter'][0]['title'] = 'Uno';
        $rewritten['book'][0]['chapters'][0]['chapter'][1]['title'] = 'Dos';

        $changes = tree_changes::between($source, $rewritten);

        $this->assertCount(3, $changes);
        $actual = $changes[0]->describe();
        $this->assertSame('book/0/name', $actual);
        $actual = $changes[1]->describe();
        $this->assertSame('book/0/chapters/0/chapter/0/title', $actual);
        $actual = $changes[2]->describe();
        $this->assertSame('book/0/chapters/0/chapter/1/title', $actual);
    }

    /**
     * The keys that identify a row or its module are never reported, even if the agent changed them.
     */
    public function test_identity_keys_are_never_reported(): void {
        $source = $this->book_tree();
        $rewritten = $this->book_tree();
        $rewritten['id'] = '10';
        $rewritten['moduleid'] = 999;
        $rewritten['modulename'] = 'forum';
        $rewritten['contextid'] = 1;
        $rewritten['book'][0]['id'] = '77';
        $rewritten['book'][0]['chapters'][0]['chapter'][0]['id'] = '78';

        $actual = tree_changes::between($source, $rewritten);
        $this->assertSame([], $actual);
    }

    /**
     * A key that only the rewritten tree has is left alone: there is no row of the template to compare it with.
     */
    public function test_a_key_only_the_rewritten_tree_has_is_ignored(): void {
        $source = $this->book_tree();
        $rewritten = $this->book_tree();
        $rewritten['book'][0]['extra'] = 'new text';
        $rewritten['book'][0]['chapters'][0]['chapter'][2] = ['id' => '99', 'title' => 'Three'];

        $actual = tree_changes::between($source, $rewritten);
        $this->assertSame([], $actual);
    }

    /**
     * A key that only the template tree has is not a change: the agent left that text out.
     */
    public function test_a_key_only_the_template_tree_has_is_not_a_change(): void {
        $source = $this->book_tree();
        $rewritten = $this->book_tree();
        unset($rewritten['book'][0]['intro']);

        $actual = tree_changes::between($source, $rewritten);
        $this->assertSame([], $actual);
    }

    /**
     * Only text is compared: a number, a null or a list against a text is left alone.
     */
    public function test_only_texts_are_compared(): void {
        $source = ['book' => [['id' => '1', 'numbering' => '1', 'navstyle' => 1, 'note' => null, 'tags' => 'a']]];
        $rewritten = ['book' => [['id' => '1', 'numbering' => 2, 'navstyle' => '2', 'note' => 'x', 'tags' => ['a']]]];

        $actual = tree_changes::between($source, $rewritten);
        $this->assertSame([], $actual);
    }

    /**
     * An empty rewritten tree reports nothing, and so does an empty template tree.
     */
    public function test_empty_trees_report_nothing(): void {
        $tree = $this->book_tree();

        $actual = tree_changes::between($tree, []);
        $this->assertSame([], $actual);
        $actual = tree_changes::between([], $tree);
        $this->assertSame([], $actual);
    }

    /**
     * A text emptied by the agent is a change: the row is written with the empty text.
     */
    public function test_a_text_emptied_by_the_agent_is_reported(): void {
        $source = $this->book_tree();
        $rewritten = $this->book_tree();
        $rewritten['book'][0]['intro'] = '';

        $changes = tree_changes::between($source, $rewritten);

        $this->assertCount(1, $changes);
        $this->assertSame('', $changes[0]->value);
    }
}
