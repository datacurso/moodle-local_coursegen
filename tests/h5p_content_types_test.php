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

use local_coursegen\local\h5p_content_types;

/**
 * The H5P kinds the modal offers.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\h5p_content_types
 */
final class h5p_content_types_test extends \advanced_testcase {
    /**
     * Every kind has a name for the list.
     */
    public function test_every_kind_has_a_name(): void {
        $strings = get_string_manager();

        foreach (array_keys(h5p_content_types::TYPES) as $type) {
            $this->assertTrue($strings->string_exists('h5ptype_' . $type, 'local_coursegen'), $type);
        }
    }

    /**
     * Every kind has a short description in the language files.
     */
    public function test_every_kind_has_a_description(): void {
        $strings = get_string_manager();

        foreach (array_keys(h5p_content_types::TYPES) as $type) {
            $this->assertTrue($strings->string_exists('h5ptypedesc_' . $type, 'local_coursegen'), $type);
        }
    }

    /**
     * Every kind has a picture: its own, or the one of the H5P activity when H5P gives it none.
     */
    public function test_every_kind_has_a_picture(): void {
        $this->resetAfterTest();

        foreach (h5p_content_types::for_modal() as $kind) {
            $this->assertNotSame('', $kind['iconurl'], $kind['type']);
            $this->assertNotSame('', $kind['description'], $kind['type']);
        }
    }

    /**
     * The list shows every kind once, with its name, after the choice to let the AI pick.
     */
    public function test_the_list_shows_every_kind_once(): void {
        $list = h5p_content_types::for_modal();

        $this->assertSame(array_merge([''], array_keys(h5p_content_types::TYPES)), array_column($list, 'type'));
        foreach ($list as $kind) {
            $this->assertNotSame('', $kind['name'], $kind['type']);
        }
    }

    /**
     * Only a kind of the list is valid.
     */
    public function test_only_a_kind_of_the_list_is_valid(): void {
        $this->assertTrue(h5p_content_types::is_valid('column_page'));
        $this->assertFalse(h5p_content_types::is_valid('not_a_kind'));
        $this->assertFalse(h5p_content_types::is_valid(''));
    }
}
