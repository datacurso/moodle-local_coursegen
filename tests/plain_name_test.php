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

use local_coursegen\local\text\plain_name;

/**
 * A name typed in the editor of a template is kept as plain text within its column, so it never makes the save fail.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\text\plain_name
 */
final class plain_name_test extends \basic_testcase {
    /**
     * Names and what is kept of each.
     *
     * @return array
     */
    public static function names_provider(): array {
        return [
            'plain' => ['Libro de la unidad', '', 'Libro de la unidad'],
            'tags are dropped' => ['<b>Libro</b> de la unidad', '', 'Libro de la unidad'],
            'only a tag keeps the fallback' => ['<img src=x onerror="alert(1)">', 'Libro', 'Libro'],
            'spaces are joined and trimmed' => ["  Libro \n de   la unidad ", '', 'Libro de la unidad'],
            'empty keeps the fallback' => ['   ', 'Foro', 'Foro'],
            'quotes and accents stay' => ['Guía "práctica" de l\'unité', '', 'Guía "práctica" de l\'unité'],
        ];
    }

    /**
     * Each name is kept as plain text.
     *
     * @dataProvider names_provider
     * @param string $raw What the editor sent.
     * @param string $fallback What is kept when nothing is left.
     * @param string $expected What is stored.
     */
    public function test_a_name_is_kept_as_plain_text(string $raw, string $fallback, string $expected): void {
        $this->assertSame($expected, plain_name::of($raw, $fallback));
    }

    /**
     * A name longer than its column is cut at the column, counting characters and not bytes.
     */
    public function test_a_long_name_is_cut_at_the_column(): void {
        $name = plain_name::of(str_repeat('ñ', 300));

        $this->assertSame(plain_name::MAX_LENGTH, \core_text::strlen($name));
        $this->assertSame(str_repeat('ñ', plain_name::MAX_LENGTH), $name);
    }
}
