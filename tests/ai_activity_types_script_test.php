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

use local_coursegen\local\ai_activity_types;

/**
 * The list of AI-supported activity types of the script is the one of the server.
 *
 * Both lists are written by hand, once each, and nothing couples them at run time, so this
 * reads the script as text and compares.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\ai_activity_types
 */
final class ai_activity_types_script_test extends \basic_testcase {
    /**
     * The module names the script lists, in the order it lists them.
     *
     * @return string[]
     */
    private function script_modnames(): array {
        global $CFG;

        $path = $CFG->dirroot . '/local/coursegen/amd/src/local/ai_activity_types.js';
        $source = file_get_contents($path);
        preg_match('/export const MODNAMES = \[(.*?)\];/s', $source, $array);
        preg_match_all("/'([a-z0-9_]+)'/", $array[1], $names);
        return $names[1];
    }

    /**
     * The script lists the same module names as the server, in the same order.
     */
    public function test_the_script_lists_the_same_types_as_the_server(): void {
        $fromscript = $this->script_modnames();

        $this->assertSame(ai_activity_types::MODNAMES, $fromscript);
    }

    /**
     * The script's list is not empty, so a change of its format cannot make the comparison trivially pass.
     */
    public function test_the_script_list_is_found(): void {
        $fromscript = $this->script_modnames();

        $this->assertNotEmpty($fromscript);
    }
}
