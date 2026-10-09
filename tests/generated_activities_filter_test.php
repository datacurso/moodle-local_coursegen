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
 * It is the check finish_template_generation.php runs on every finished generation: only the activities the
 * run was asked to modify are written by the AI. The kept ones are copied from the template, and a resource
 * whose file the run replaced is a copy of the template resource with another file.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\generated_activities_filter
 */
final class generated_activities_filter_test extends \basic_testcase {
    /**
     * An activity modified by the AI is written by it.
     */
    public function test_a_modified_activity_is_kept(): void {
        $result = generated_activities_filter::only_ai_written([$this->activity('modify', 'page', 11342)]);

        $this->assertCount(1, $result);
    }

    /**
     * A kept activity travels back unchanged and is not written.
     */
    public function test_a_kept_activity_is_dropped(): void {
        $result = generated_activities_filter::only_ai_written([$this->activity('keep', 'page', 11335)]);

        $this->assertSame([], $result);
    }

    /**
     * Any action the service does not know is dropped.
     */
    public function test_an_unknown_action_is_dropped(): void {
        foreach (['reference', 'instance', 'template', 'space', 'exclude', 'MODIFY', ''] as $action) {
            $result = generated_activities_filter::only_ai_written([$this->activity($action, 'page', 1)]);
            $this->assertSame([], $result, 'Action: ' . $action);
        }
    }

    /**
     * An activity with no template_behavior is dropped instead of crashing.
     */
    public function test_an_activity_without_behavior_is_dropped(): void {
        $result = generated_activities_filter::only_ai_written([['resource_type' => 'page', 'parameters' => []]]);

        $this->assertSame([], $result);
    }

    /**
     * A resource whose file the run replaced is not written by the AI, even when it is marked as modified.
     */
    public function test_a_file_resource_with_a_replacement_file_is_dropped(): void {
        $resource = $this->activity('modify', 'resource', 11340);
        $resource['generated_files'] = [['file_id' => 'f1', 'filename' => 'guide.pdf', 'role' => 'main']];

        $result = generated_activities_filter::only_ai_written([$resource]);

        $this->assertSame([], $result);
    }

    /**
     * A resource marked as modified that got no file is still written by the AI.
     */
    public function test_a_modified_resource_without_a_file_is_kept(): void {
        $result = generated_activities_filter::only_ai_written([$this->activity('modify', 'resource', 11340)]);

        $this->assertCount(1, $result);
    }

    /**
     * A mixed batch keeps only the modified activities, in their original order.
     */
    public function test_a_mixed_batch_keeps_only_the_modified_ones_in_order(): void {
        $resource = $this->activity('modify', 'resource', 11340);
        $resource['generated_files'] = [['file_id' => 'f1', 'filename' => 'guide.pdf']];
        $activities = [
            $this->activity('keep', 'forum', 5),
            $this->activity('modify', 'page', 11342),
            $resource,
            $this->activity('modify', 'quiz', 900007),
        ];

        $result = generated_activities_filter::only_ai_written($activities);

        $this->assertCount(2, $result);
        $this->assertSame(11342, $result[0]['cmid']);
        $this->assertSame(900007, $result[1]['cmid']);
    }

    /**
     * An empty batch returns an empty list.
     */
    public function test_an_empty_batch_returns_an_empty_list(): void {
        $this->assertSame([], generated_activities_filter::only_ai_written([]));
    }

    /**
     * One entry of the result with the given action, type and cmid.
     *
     * @param string $action Action of the template_behavior.
     * @param string $type Module name.
     * @param int $cmid Course module id.
     * @return array The entry.
     */
    private function activity(string $action, string $type, int $cmid): array {
        return [
            'cmid' => $cmid,
            'resource_type' => $type,
            'parameters' => ['name' => 'Activity'],
            'template_behavior' => ['action' => $action],
        ];
    }
}
