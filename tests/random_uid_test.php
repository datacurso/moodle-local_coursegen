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

use local_coursegen\local\service\template_export_uids;

/**
 * Unit tests for template_export_uids::random_uid().
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_export_uids
 */
final class random_uid_test extends \basic_testcase {
    /**
     * Two calls never produce the same uid: nothing of ours could tell two
     * real activities/sections named this way apart otherwise.
     */
    public function test_two_calls_produce_different_uids(): void {
        $first = template_export_uids::random_uid();
        $second = template_export_uids::random_uid();

        $this->assertNotSame($first, $second);
    }

    /**
     * The result only ever contains what PARAM_ALPHANUMEXT accepts - letters,
     * digits, underscore and hyphen - since every caller reads it through
     * that param type.
     */
    public function test_uid_is_param_alphanumext_safe(): void {
        $uid = template_export_uids::random_uid();

        $matched = preg_match('/^[A-Za-z0-9_-]+$/', $uid);
        $this->assertSame(1, $matched);
    }

    /**
     * The result is a V4 UUID, the same shape \core\uuid::generate() itself
     * documents (xxxxxxxx-xxxx-4xxx-Yxxx-xxxxxxxxxxxx), which is what this
     * method wraps.
     */
    public function test_uid_matches_the_v4_uuid_shape(): void {
        $uid = template_export_uids::random_uid();

        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        $matched = preg_match($pattern, $uid);
        $this->assertSame(1, $matched);
    }

    /**
     * Many consecutive calls never collide, since a real export can name
     * dozens of activities and sections within the same payload build.
     */
    public function test_many_calls_never_collide(): void {
        $uids = [];
        for ($i = 0; $i < 100; $i++) {
            $uids[] = template_export_uids::random_uid();
        }

        $unique = array_unique($uids);
        $this->assertCount(100, $unique);
    }

    /**
     * The uid saved with an activity is a V4 UUID and never repeats.
     */
    public function test_the_uid_of_an_activity_is_a_unique_uuid(): void {
        $first = template_export_uids::new_item_uid();
        $second = template_export_uids::new_item_uid();

        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        $this->assertSame(1, preg_match($pattern, $first));
        $this->assertNotSame($first, $second);
    }

    /**
     * The stand-in uid of an activity with no saved row is stable, opaque and different for each activity.
     */
    public function test_the_stand_in_uid_is_stable_and_differs_per_activity_and_template(): void {
        $uid = template_export_uids::stand_in_uid(3, 11342);

        $this->assertSame($uid, template_export_uids::stand_in_uid(3, 11342));
        $this->assertNotSame($uid, template_export_uids::stand_in_uid(3, 11343));
        $this->assertNotSame($uid, template_export_uids::stand_in_uid(4, 11342));
        $this->assertSame(1, preg_match('/^[0-9a-f]{32}$/', $uid));
    }
}
