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

/**
 * The words that explain the reference marker, in both languages the plugin ships.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\form\template_config_form
 */
final class template_reference_marker_strings_test extends \advanced_testcase {
    /**
     * The keys the form and the template screen read.
     *
     * @return array
     */
    public static function key_provider(): array {
        return [
            ['template_markers_title'],
            ['template_reference_marker'],
            ['template_reference_marker_example'],
            ['template_reference_marker_help'],
            ['courseai_reference_title'],
            ['courseai_reference_intro'],
            ['courseai_reference_hint'],
            ['courseai_reference_current'],
            ['courseai_reference_remove'],
            ['courseai_reference_in_activity'],
        ];
    }

    /**
     * Every string exists in English and in Spanish.
     *
     * @dataProvider key_provider
     * @param string $key
     */
    public function test_the_string_exists_in_both_languages(string $key): void {
        $manager = get_string_manager();

        $this->assertTrue($manager->string_exists($key, 'local_coursegen'));
        $english = $manager->get_string($key, 'local_coursegen', null, 'en');
        $spanish = $manager->get_string($key, 'local_coursegen', null, 'es');

        $this->assertNotSame('', $english);
        $this->assertNotSame('', $spanish);
    }

    /**
     * The help names the marker the way an author types it, in both languages.
     */
    public function test_the_help_names_the_marker_syntax(): void {
        $manager = get_string_manager();

        $english = $manager->get_string('template_reference_marker_help', 'local_coursegen', null, 'en');
        $spanish = $manager->get_string('template_reference_marker_help', 'local_coursegen', null, 'es');

        $this->assertStringContainsString('[[coursegen:reference:', $english);
        $this->assertStringContainsString('[[coursegen:reference:', $spanish);
    }

    /**
     * The Spanish hint says what happens with no file, as the teacher is told.
     */
    public function test_the_spanish_hint_says_what_happens_with_no_file(): void {
        $manager = get_string_manager();

        $hint = $manager->get_string('courseai_reference_hint', 'local_coursegen', null, 'es');

        $this->assertStringStartsWith('Sin archivo:', $hint);
    }
}
