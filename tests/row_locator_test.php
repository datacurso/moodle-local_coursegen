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

use local_coursegen\local\structure\row_locator;
use local_coursegen\local\structure\tree_changes;

/**
 * Unit tests for row_locator, including the real export of every type of activity.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\structure\row_locator
 * @covers     \local_coursegen\local\structure\row_place
 */
final class row_locator_test extends \basic_testcase {
    /** @var string[] The keys whose text the agent rewrites in the export of an activity. */
    private const TEXT_KEYS = ['name', 'intro', 'content', 'title', 'description', 'message', 'subject', 'text', 'answer'];

    /**
     * A small tree of a lesson with the tables and aliases its module declares.
     *
     * @return array
     */
    private function lesson_tree(): array {
        return [
            'id' => '5',
            'lesson' => [[
                'id' => '5',
                'intro' => '<p>Intro</p>',
                'pages' => [['page' => [
                    ['id' => '11', 'title' => 'Page', 'contents' => '<p>Body</p>', 'answers' => [['answer' => [
                        ['id' => '21', 'answer_text' => 'Yes'],
                        ['id' => '22', 'answer_text' => 'No'],
                    ]]]],
                ]]],
            ]],
        ];
    }

    /**
     * The tables of that lesson tree.
     *
     * @return array
     */
    private function lesson_tables(): array {
        return ['lesson' => 'lesson', 'page' => 'lesson_pages', 'answer' => 'lesson_answers'];
    }

    /**
     * A text of the row the module itself describes is located in the table of its element.
     */
    public function test_a_text_of_the_root_row_is_located(): void {
        $lessontree = $this->lesson_tree();
        $tables = $this->lesson_tables();

        $place = row_locator::locate($lessontree, ['lesson', 0, 'intro'], $tables, []);

        $this->assertNotNull($place);
        $this->assertSame('lesson', $place->table);
        $this->assertSame('intro', $place->column);
        $this->assertSame(5, $place->id);
    }

    /**
     * A text of a row of a list is located by the id of that row.
     */
    public function test_a_text_of_a_list_row_is_located(): void {
        $path = ['lesson', 0, 'pages', 0, 'page', 0, 'contents'];

        $lessontree = $this->lesson_tree();
        $tables = $this->lesson_tables();

        $place = row_locator::locate($lessontree, $path, $tables, []);

        $this->assertSame('lesson_pages', $place->table);
        $this->assertSame('contents', $place->column);
        $this->assertSame(11, $place->id);
    }

    /**
     * A column that travels under another name in the tree is written to its real column.
     */
    public function test_a_renamed_column_is_located_by_its_real_name(): void {
        $path = ['lesson', 0, 'pages', 0, 'page', 0, 'answers', 0, 'answer', 1, 'answer_text'];
        $aliases = ['answer' => ['answer_text' => 'answer']];

        $lessontree = $this->lesson_tree();
        $tables = $this->lesson_tables();

        $place = row_locator::locate($lessontree, $path, $tables, $aliases);

        $this->assertSame('lesson_answers', $place->table);
        $this->assertSame('answer', $place->column);
        $this->assertSame(22, $place->id);
    }

    /**
     * An element without a table cannot be written.
     */
    public function test_an_element_without_a_table_is_not_located(): void {
        $tables = ['lesson' => 'lesson'];
        $path = ['lesson', 0, 'pages', 0, 'page', 0, 'contents'];
        $lessontree = $this->lesson_tree();

        $actual = row_locator::locate($lessontree, $path, $tables, []);
        $this->assertNull($actual);
    }

    /**
     * A path that leads to a row the tree does not have is not located.
     */
    public function test_a_missing_row_is_not_located(): void {
        $path = ['lesson', 0, 'pages', 0, 'page', 5, 'contents'];
        $lessontree = $this->lesson_tree();
        $tables = $this->lesson_tables();

        $actual = row_locator::locate($lessontree, $path, $tables, []);
        $this->assertNull($actual);
    }

    /**
     * A row without an id cannot be written.
     */
    public function test_a_row_without_an_id_is_not_located(): void {
        $tree = ['lesson' => [['intro' => '<p>Intro</p>']]];

        $actual = row_locator::locate($tree, ['lesson', 0, 'intro'], ['lesson' => 'lesson'], []);
        $this->assertNull($actual);
    }

    /**
     * A path with no list position, or with nothing before the list position, has no row.
     */
    public function test_a_path_without_a_row_is_not_located(): void {
        $tables = $this->lesson_tables();
        $lessontree = $this->lesson_tree();

        $actual = row_locator::locate($lessontree, ['id'], $tables, []);
        $this->assertNull($actual);
        $actual = row_locator::locate($lessontree, ['lesson', 'intro'], $tables, []);
        $this->assertNull($actual);
        $actual = row_locator::locate($lessontree, [0, 'intro'], $tables, []);
        $this->assertNull($actual);
        $actual = row_locator::locate([], ['lesson', 0, 'intro'], $tables, []);
        $this->assertNull($actual);
    }

    /**
     * The export of one type of activity, as the template service sends it.
     *
     * @return array[] Type => [type].
     */
    public static function exported_types(): array {
        $json = file_get_contents(__DIR__ . '/fixtures/template_export_by_type.json');
        $export = json_decode($json, true);
        $cases = [];
        foreach (array_keys($export) as $modname) {
            $cases[$modname] = [$modname];
        }
        return $cases;
    }

    /**
     * Every text the agent rewrites in the real export of a type has a row and a column in the tables of its module.
     *
     * @dataProvider exported_types
     * @param string $modname The type of activity.
     */
    public function test_every_rewritten_text_of_a_real_export_is_located(string $modname): void {
        $json = file_get_contents(__DIR__ . '/fixtures/template_export_by_type.json');
        $export = json_decode($json, true);
        $parameters = $export[$modname]['parameters'];
        $tree = $parameters['structure'];
        $rewritten = $this->rewritten($tree);

        $changes = tree_changes::between($tree, $rewritten);
        $unlocated = [];
        $tables = $parameters['structure_tables'];
        $aliases = $this->aliases($parameters);
        foreach ($changes as $change) {
            $place = row_locator::locate($tree, $change->path, $tables, $aliases);
            if ($place === null || $place->id <= 0 || $place->table === '' || $place->column === '') {
                $unlocated[] = $change->describe();
            }
        }

        $this->assertNotEmpty($changes, $modname . ' has texts the agent rewrites');
        $this->assertSame([], $unlocated, $modname . ' texts without a row');
    }

    /**
     * The aliases of an export: an empty export holds an empty list instead of a map.
     *
     * @param array $parameters The parameters of the exported activity.
     * @return array
     */
    private function aliases(array $parameters): array {
        $aliases = $parameters['structure_aliases'] ?? [];
        return (array) $aliases;
    }

    /**
     * The tree with a suffix on every text of the keys the agent rewrites.
     *
     * @param array $tree A tree or one level of it.
     * @return array
     */
    private function rewritten(array $tree): array {
        foreach ($tree as $key => $value) {
            if (is_array($value)) {
                $tree[$key] = $this->rewritten($value);
                continue;
            }
            if (is_string($value) && in_array((string) $key, self::TEXT_KEYS, true) && !is_numeric($value)) {
                $tree[$key] = $value . ' (rewritten)';
            }
        }
        return $tree;
    }
}
