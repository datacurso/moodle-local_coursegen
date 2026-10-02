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

use local_coursegen\local\preview\preview_factory;

/**
 * Tests for the title the preview puts in the activity header.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\activity_preview
 */
final class activity_preview_header_title_test extends \advanced_testcase {
    /**
     * The header prints the name of the generated activity, never the template activity's.
     *
     * @dataProvider instance_names_provider
     * @param string $modname
     * @param string $name
     */
    public function test_header_title_is_the_name_of_the_generated_activity(string $modname, string $name): void {
        $this->resetAfterTest(true);
        $source = ['cmid' => 0, 'parameters' => ['name' => 'Molde - Lección estándar']];

        $preview = preview_factory::for_activity($modname, ['name' => $name], $source);

        $this->assertSame($name, $preview->header_title());
        $this->assertSame($name, $preview->name());
    }

    /**
     * Instance names with accents, numbers and trailing text, for several modules.
     *
     * @return array
     */
    public static function instance_names_provider(): array {
        return [
            'lesson' => ['lesson', 'Lección 1'],
            'forum' => ['forum', 'Foro Formativo - Lección 3'],
            'page' => ['page', 'Evaluación: módulo ñandú 12 (final)'],
            'assign' => ['assign', 'Tarea 2'],
            'unknown type' => ['unknowntype', 'Lección 7'],
        ];
    }

    /**
     * An activity with no name leaves the header to the theme.
     */
    public function test_header_title_is_empty_without_a_name(): void {
        $this->resetAfterTest(true);

        $preview = preview_factory::for_activity('page', []);

        $this->assertSame('', $preview->header_title());
    }
}
