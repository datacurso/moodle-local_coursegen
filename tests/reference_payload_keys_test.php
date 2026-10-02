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

use local_coursegen\local\reference\reference_payload_keys;

/**
 * The two names of a place: after its template activity, and after the uid the payload gives that activity.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\reference\reference_payload_keys
 */
final class reference_payload_keys_test extends \basic_testcase {
    /**
     * A payload with two real activities and one virtual instance.
     *
     * @return array
     */
    private function payload(): array {
        return [
            'activities' => [
                ['uid' => 'uid-aaa', 'cmid' => 12],
                ['uid' => 'uid-bbb', 'cmid' => 13],
                ['uid' => 'uid-instance', 'cmid' => -4],
            ],
        ];
    }

    /**
     * Staged places are named after the uid of their activity.
     */
    public function test_staged_places_are_named_after_the_uid(): void {
        $keys = reference_payload_keys::for_payload($this->payload(), ['12.1', '13.2', '12.3']);

        $this->assertSame(['uid-aaa.1', 'uid-bbb.2', 'uid-aaa.3'], $keys);
    }

    /**
     * A place whose activity is not in the payload, or that is not a place name, is left out.
     */
    public function test_places_that_nothing_reads_are_left_out(): void {
        $keys = reference_payload_keys::for_payload($this->payload(), ['99.1', 'nonsense', '12.1', '12.x']);

        $this->assertSame(['uid-aaa.1'], $keys);
    }

    /**
     * Nothing staged lists nothing.
     */
    public function test_nothing_staged_lists_nothing(): void {
        $keys = reference_payload_keys::for_payload($this->payload(), []);

        $this->assertSame([], $keys);
    }

    /**
     * An instance is never the owner of a place.
     */
    public function test_a_virtual_instance_owns_no_place(): void {
        $keys = reference_payload_keys::for_payload($this->payload(), ['-4.1']);

        $this->assertSame([], $keys);
    }

    /**
     * The places the payload lists map back to the template activity that holds them.
     */
    public function test_listed_places_map_back_to_their_template_activity(): void {
        $payload = $this->payload();
        $payload['reference_files'] = ['uid-aaa.1', 'uid-bbb.2'];

        $stored = reference_payload_keys::stored_keys($payload);

        $this->assertSame(['uid-aaa.1' => '12.1', 'uid-bbb.2' => '13.2'], $stored);
    }

    /**
     * A payload that lists nothing, or lists an unknown uid, maps nothing.
     */
    public function test_unknown_or_missing_lists_map_nothing(): void {
        $payload = $this->payload();
        $none = reference_payload_keys::stored_keys($payload);
        $payload['reference_files'] = ['uid-zzz.1', 'broken'];
        $unknown = reference_payload_keys::stored_keys($payload);

        $this->assertSame([], $none);
        $this->assertSame([], $unknown);
    }
}
