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

use local_coursegen\local\service\link_targets;

/**
 * Which uid reaches which created course module.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\link_targets
 */
final class link_targets_test extends \basic_testcase {
    /**
     * Generated and kept activities both end up in the map.
     */
    public function test_map_holds_generated_and_kept_activities(): void {
        $payload = [
            ['uid' => 'uid-generated', 'cmid' => -4],
            ['uid' => 'uid-kept', 'cmid' => 31],
        ];

        $map = link_targets::build($payload, [-4 => 901], [31 => 902]);

        $this->assertSame(['uid-generated' => 901, 'uid-kept' => 902], $map);
    }

    /**
     * An activity that was never created has no target.
     */
    public function test_activity_without_a_created_module_is_left_out(): void {
        $payload = [
            ['uid' => 'uid-failed', 'cmid' => -9],
            ['uid' => 'uid-failedcopy', 'cmid' => 40],
        ];

        $this->assertSame([], link_targets::build($payload, [], []));
    }

    /**
     * An entry without a uid, or without a cmid, cannot be a target.
     */
    public function test_entries_without_uid_or_cmid_are_left_out(): void {
        $payload = [
            ['cmid' => 31],
            ['uid' => '', 'cmid' => 31],
            ['uid' => 'uid-nocmid'],
        ];

        $this->assertSame([], link_targets::build($payload, [31 => 902], [31 => 902]));
    }

    /**
     * The same uid twice would make a link ambiguous.
     */
    public function test_duplicated_uid_is_rejected(): void {
        $payload = [
            ['uid' => 'dup', 'cmid' => -1],
            ['uid' => 'dup', 'cmid' => 31],
        ];

        $this->expectException(\moodle_exception::class);

        link_targets::build($payload, [-1 => 901], [31 => 902]);
    }
}
