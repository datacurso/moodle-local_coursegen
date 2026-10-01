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

use local_coursegen\local\service\generated_activities_filter;

/**
 * Unit tests for generated_activities_filter::only_ai_written().
 *
 * This is the exact check finish_template_generation.php runs on every
 * finished generation. An earlier version of it compared an activity's cmid
 * against a base number that stood for virtual instances, which crashed every
 * real generation once that number was gone. These tests exercise only the
 * action field, never a cmid's numeric shape.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\generated_activities_filter
 */
final class generated_activities_filter_test extends \basic_testcase {
    /**
     * An activity submitted with the instance wire action - the literal
     * "instance" of the service contract, the one a virtual instance is
     * always submitted with - is kept.
     */
    public function test_instance_activity_is_kept(): void {
        $activities = [$this->activity('instance')];

        $result = generated_activities_filter::only_ai_written($activities);

        $this->assertCount(1, $result);
    }

    /**
     * A "keep" activity - a real activity travelling back only as context -
     * is dropped.
     */
    public function test_keep_activity_is_dropped(): void {
        $activities = [$this->activity('keep')];

        $result = generated_activities_filter::only_ai_written($activities);

        $this->assertSame([], $result);
    }

    /**
     * A "reference" activity is dropped, the same as "keep".
     */
    public function test_reference_activity_is_dropped(): void {
        $activities = [$this->activity('reference')];

        $result = generated_activities_filter::only_ai_written($activities);

        $this->assertSame([], $result);
    }

    /**
     * A "template" activity - a mold, never written itself - is dropped,
     * as is every other action that is not the instance one.
     */
    public function test_template_activity_is_dropped(): void {
        $activities = [$this->activity('template'), $this->activity('space'), $this->activity('exclude')];

        $result = generated_activities_filter::only_ai_written($activities);

        $this->assertSame([], $result);
    }

    /**
     * An activity with no template_behavior at all - malformed input, not
     * something the real payload ever sends - is dropped rather than
     * crashing, since an absent action is never the instance one.
     */
    public function test_activity_with_no_template_behavior_is_dropped(): void {
        $activities = [['resource_type' => 'page', 'parameters' => []]];

        $result = generated_activities_filter::only_ai_written($activities);

        $this->assertSame([], $result);
    }

    /**
     * A mixed batch keeps only the instance entries, in their original order,
     * regardless of the cmid each one carries - proving the filter never
     * reads a cmid's value or shape, only the action.
     */
    public function test_mixed_batch_keeps_only_instance_entries_regardless_of_cmid(): void {
        $activities = [
            $this->activity('keep', 5),
            $this->activity('instance', -1),
            $this->activity('reference', 12),
            $this->activity('instance', 900007),
        ];

        $result = generated_activities_filter::only_ai_written($activities);

        $this->assertCount(2, $result);
        $this->assertSame(-1, $result[0]['cmid']);
        $this->assertSame(900007, $result[1]['cmid']);
    }

    /**
     * An empty batch returns an empty list.
     */
    public function test_empty_batch_returns_empty_list(): void {
        $this->assertSame([], generated_activities_filter::only_ai_written([]));
    }

    /**
     * One payload activity entry with the given action and cmid.
     *
     * @param string $action
     * @param int $cmid
     * @return array
     */
    private function activity(string $action, int $cmid = 0): array {
        return [
            'resource_type' => 'page',
            'cmid' => $cmid,
            'parameters' => ['name' => 'Test'],
            'template_behavior' => ['action' => $action],
        ];
    }
}
