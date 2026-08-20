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

use local_coursegen\local\image_generation\activities;
use local_coursegen\local\image_generation\image_policy_builder;

/**
 * Tests for the global image generation policy composition.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\image_generation\image_policy_builder
 */
final class image_policy_builder_test extends \advanced_testcase {
    /**
     * MDL-UNIT-003: The policy is composed with the global mode, both override
     * switches and the activity table with its parts, exactly as saved in the
     * image generation administration page.
     */
    public function test_policy_composed_from_saved_configuration(): void {
        $this->resetAfterTest();

        set_config('generationmode', activities::MODE_MANUAL, 'local_coursegen');
        set_config('overridecourse', 1, 'local_coursegen');
        set_config('overrideactivity', 0, 'local_coursegen');
        set_config('enableimgassign', 1, 'local_coursegen');
        set_config('enableimgassign_intro', 1, 'local_coursegen');
        set_config('maximgassign_intro', 3, 'local_coursegen');

        $policy = image_policy_builder::build();

        $this->assertSame(activities::MODE_MANUAL, $policy['mode']);
        $this->assertTrue($policy['overridecourse']);
        $this->assertFalse($policy['overrideactivity']);

        // The activity table carries every defined activity type with its parts.
        $definitions = activities::get_definitions();
        $this->assertCount(count($definitions), $policy['activities']);

        $assign = $this->find_activity_policy($policy, 'assign');
        $this->assertTrue($assign['enabled']);

        $intro = $this->find_part_policy($assign, 'intro');
        $this->assertTrue($intro['enabled']);
        $this->assertSame(3, $intro['maximages']);
    }

    /**
     * MDL-UNIT-003: The maximum images of a part is only carried when the saved
     * value is greater than zero; otherwise it stays at zero.
     */
    public function test_part_max_images_only_carried_when_positive(): void {
        $this->resetAfterTest();

        set_config('generationmode', activities::MODE_MANUAL, 'local_coursegen');
        set_config('enableimgassign', 1, 'local_coursegen');
        set_config('enableimgassign_intro', 1, 'local_coursegen');
        set_config('maximgassign_intro', 0, 'local_coursegen');
        set_config('enableimgassign_instructions', 1, 'local_coursegen');
        set_config('maximgassign_instructions', 4, 'local_coursegen');

        $policy = image_policy_builder::build();
        $assign = $this->find_activity_policy($policy, 'assign');

        $this->assertSame(0, $this->find_part_policy($assign, 'intro')['maximages']);
        $this->assertSame(4, $this->find_part_policy($assign, 'instructions')['maximages']);
    }

    /**
     * MDL-UNIT-003: The activity table is fully serialized regardless of the
     * global mode, disabled mode included.
     */
    public function test_activity_table_serialized_regardless_of_mode(): void {
        $this->resetAfterTest();

        $definitions = activities::get_definitions();

        foreach ([activities::MODE_DISABLED, activities::MODE_AUTO, activities::MODE_MANUAL] as $mode) {
            set_config('generationmode', $mode, 'local_coursegen');

            $policy = image_policy_builder::build();

            $this->assertSame($mode, $policy['mode']);
            $this->assertCount(count($definitions), $policy['activities']);
            foreach ($policy['activities'] as $activity) {
                $this->assertArrayHasKey('id', $activity);
                $this->assertArrayHasKey('enabled', $activity);
                $this->assertArrayHasKey('parts', $activity);
            }
        }
    }

    /**
     * Find one activity entry of the built policy by id.
     *
     * @param array $policy Full policy from image_policy_builder::build().
     * @param string $id Activity identifier.
     * @return array
     */
    private function find_activity_policy(array $policy, string $id): array {
        foreach ($policy['activities'] as $activity) {
            if ($activity['id'] === $id) {
                return $activity;
            }
        }
        $this->fail('Activity policy not found: ' . $id);
    }

    /**
     * Find one part entry of an activity policy by id.
     *
     * @param array $activity Activity entry from the built policy.
     * @param string $id Part identifier.
     * @return array
     */
    private function find_part_policy(array $activity, string $id): array {
        foreach ($activity['parts'] as $part) {
            if ($part['id'] === $id) {
                return $part;
            }
        }
        $this->fail('Part policy not found: ' . $id);
    }
}
