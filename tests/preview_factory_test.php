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

use local_coursegen\local\preview\book_preview;
use local_coursegen\local\preview\intro_preview;
use local_coursegen\local\preview\preview_factory;
use local_coursegen\local\preview\settings_preview;

/**
 * Unit tests for the choice that preview_factory makes between a template activity and one written from scratch.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\preview_factory
 */
final class preview_factory_test extends \basic_testcase {
    /**
     * The nineteen types the plugin supports.
     *
     * @return array
     */
    public static function every_type_provider(): array {
        $names = [
            'assign', 'book', 'choice', 'data', 'feedback', 'folder', 'forum', 'glossary', 'h5pactivity', 'imscp',
            'label', 'lesson', 'page', 'quiz', 'resource', 'scorm', 'url', 'wiki', 'workshop',
        ];
        $cases = [];
        foreach ($names as $name) {
            $cases[$name] = [$name];
        }
        return $cases;
    }

    /**
     * An activity written from scratch has no tree and is drawn from its settings, whatever its type.
     *
     * @dataProvider every_type_provider
     * @param string $modname
     */
    public function test_an_activity_without_a_tree_is_drawn_from_its_settings(string $modname): void {
        $preview = preview_factory::for_activity($modname, ['name' => 'Written', 'mod_settings' => []]);

        $this->assertInstanceOf(settings_preview::class, $preview);
    }

    /**
     * An activity that carries the tree of its template is drawn by the preview written against its own view.
     *
     * @dataProvider every_type_provider
     * @param string $modname
     */
    public function test_an_activity_with_a_tree_is_drawn_by_the_preview_of_its_type(string $modname): void {
        $parameters = ['name' => 'From template', 'structure' => [$modname => [['id' => '1']]]];

        $preview = preview_factory::for_activity($modname, $parameters);

        $this->assertNotInstanceOf(settings_preview::class, $preview);
        $this->assertTrue(preview_factory::has_own_preview($modname));
    }

    /**
     * The tree decides: a book with its tree keeps the preview of the book view.
     */
    public function test_a_book_with_a_tree_keeps_the_book_preview(): void {
        $preview = preview_factory::for_activity('book', ['structure' => ['book' => [['id' => '1']]]]);

        $this->assertInstanceOf(book_preview::class, $preview);
    }

    /**
     * An empty tree is no tree.
     */
    public function test_an_empty_tree_is_no_tree(): void {
        $preview = preview_factory::for_activity('book', ['structure' => []]);

        $this->assertInstanceOf(settings_preview::class, $preview);
    }

    /**
     * A type with no preview of its own and a tree falls back to the description.
     */
    public function test_an_unknown_type_with_a_tree_shows_its_description(): void {
        $preview = preview_factory::for_activity('bigbluebuttonbn', ['structure' => ['bigbluebuttonbn' => [['id' => '1']]]]);

        $this->assertInstanceOf(intro_preview::class, $preview);
    }

    /**
     * The name of the activity reaches the preview.
     */
    public function test_the_name_reaches_the_preview(): void {
        $preview = preview_factory::for_activity('quiz', ['name' => 'Final quiz'], 12);

        $this->assertSame('Final quiz', $preview->name());
    }
}
