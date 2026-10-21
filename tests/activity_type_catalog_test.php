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

use local_coursegen\local\activity_type_catalog;
use local_coursegen\local\ai_activity_types;

/**
 * The list of activity types the modal offers.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\activity_type_catalog
 *
 * @runTestsInSeparateProcesses
 */
final class activity_type_catalog_test extends \advanced_testcase {
    /**
     * The list holds exactly the types the service supports and the site has installed.
     */
    public function test_the_list_is_what_is_supported_and_installed(): void {
        $this->resetAfterTest();

        $modnames = array_column(activity_type_catalog::for_modal(), 'modname');

        $this->assertEqualsCanonicalizing(ai_activity_types::installed(), $modnames);
    }

    /**
     * Each type has what the modal shows: a name, a description and an icon.
     */
    public function test_each_type_has_a_name_a_description_and_an_icon(): void {
        $this->resetAfterTest();

        foreach (activity_type_catalog::for_modal() as $type) {
            $this->assertNotSame('', $type['name'], $type['modname']);
            $this->assertNotSame('', $type['description'], $type['modname']);
            $this->assertNotSame('', $type['iconurl'], $type['modname']);
        }
    }

    /**
     * The list is sorted by the name the teacher reads.
     */
    public function test_the_list_is_sorted_by_name(): void {
        $this->resetAfterTest();

        $names = array_column(activity_type_catalog::for_modal(), 'name');
        $sorted = $names;
        usort($sorted, 'strcoll');

        $this->assertSame($sorted, $names);
    }

    /**
     * Only a module of the list is offered.
     */
    public function test_only_a_module_of_the_list_is_offered(): void {
        $this->resetAfterTest();

        $this->assertTrue(activity_type_catalog::is_offered('forum'));
        $this->assertFalse(activity_type_catalog::is_offered('not_a_module'));
        $this->assertFalse(activity_type_catalog::is_offered(''));
    }
}
