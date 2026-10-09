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

use local_coursegen\local\service\agent_result_activities;

/**
 * Unit tests for agent_result_activities.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\agent_result_activities
 */
final class agent_result_activities_test extends \basic_testcase {
    /**
     * A result of the agent, with one generated activity.
     *
     * @param array $activity The activity row.
     * @return array
     */
    private function agent_result(array $activity): array {
        return [
            'generated_activities' => [$activity],
            'agent_report' => ['summary' => '', 'warnings' => [], 'tool_calls' => 0, 'failed' => []],
        ];
    }

    /**
     * Only the result that carries the report of the agent is the agent's.
     */
    public function test_the_report_of_the_agent_marks_its_result(): void {
        $withreport = agent_result_activities::is_agent_result(['agent_report' => []]);
        $withactivities = agent_result_activities::is_agent_result(['generated_activities' => []]);
        $empty = agent_result_activities::is_agent_result([]);

        $this->assertTrue($withreport);
        $this->assertFalse($withactivities);
        $this->assertFalse($empty);
    }

    /**
     * The section of the row goes into the parameters of an activity the generator wrote with a section of zero.
     */
    public function test_the_section_of_the_row_replaces_the_zero_of_the_parameters(): void {
        $row = ['uid' => 'a', 'section' => 3, 'parameters' => ['name' => 'Forum', 'section' => 0]];

        $placed = agent_result_activities::placed($this->agent_result($row));

        $this->assertSame(3, $placed['generated_activities'][0]['parameters']['section']);
        $this->assertSame('Forum', $placed['generated_activities'][0]['parameters']['name']);
    }

    /**
     * An activity whose parameters name no section gets one from its row.
     */
    public function test_parameters_without_a_section_get_the_one_of_the_row(): void {
        $row = ['uid' => 'a', 'section' => 2, 'parameters' => ['name' => 'Quiz']];

        $placed = agent_result_activities::placed($this->agent_result($row));

        $this->assertSame(2, $placed['generated_activities'][0]['parameters']['section']);
    }

    /**
     * The section is a whole number whatever the row carried.
     */
    public function test_the_section_is_a_whole_number(): void {
        $row = ['uid' => 'a', 'section' => '5', 'parameters' => ['section' => 0]];

        $placed = agent_result_activities::placed($this->agent_result($row));

        $this->assertSame(5, $placed['generated_activities'][0]['parameters']['section']);
    }

    /**
     * A row that names no section leaves its parameters as they are.
     */
    public function test_a_row_without_a_section_is_left_alone(): void {
        $row = ['uid' => 'a', 'parameters' => ['name' => 'Page', 'section' => 7]];

        $placed = agent_result_activities::placed($this->agent_result($row));

        $this->assertSame($row, $placed['generated_activities'][0]);
    }

    /**
     * A row with no parameters at all still gets its section.
     */
    public function test_a_row_without_parameters_gets_them_with_its_section(): void {
        $row = ['uid' => 'a', 'section' => 1];

        $placed = agent_result_activities::placed($this->agent_result($row));

        $this->assertSame(['section' => 1], $placed['generated_activities'][0]['parameters']);
    }

    /**
     * The result of any other flow is not touched.
     */
    public function test_a_result_that_is_not_the_agents_is_returned_as_it_is(): void {
        $result = ['generated_activities' => [['uid' => 'a', 'section' => 3, 'parameters' => ['section' => 0]]]];

        $placed = agent_result_activities::placed($result);

        $this->assertSame($result, $placed);
    }

    /**
     * Every activity keeps its place in the list and gets its own section.
     */
    public function test_every_activity_gets_its_own_section_in_order(): void {
        $activities = [
            ['uid' => 'a', 'section' => 1, 'parameters' => ['name' => 'A']],
            ['uid' => 'b', 'section' => 4, 'parameters' => ['name' => 'B']],
            ['uid' => 'c', 'parameters' => ['name' => 'C']],
        ];

        $placed = agent_result_activities::with_sections($activities);

        $uids = array_column($placed, 'uid');

        $this->assertSame(['a', 'b', 'c'], $uids);
        $this->assertSame(1, $placed[0]['parameters']['section']);
        $this->assertSame(4, $placed[1]['parameters']['section']);
        $this->assertArrayNotHasKey('section', $placed[2]['parameters']);
    }

    /**
     * A result of the agent with no activities stays empty.
     */
    public function test_a_result_with_no_activities_stays_empty(): void {
        $placed = agent_result_activities::placed(['agent_report' => []]);

        $this->assertSame([], $placed['generated_activities']);
    }

    /**
     * An activity the agent made without saying how is stated as made from its settings.
     */
    public function test_an_activity_not_marked_is_stated_as_not_made_from_its_tree(): void {
        $result = $this->agent_result(['uid' => 'a', 'section' => 2, 'parameters' => ['name' => 'A']]);

        $placed = agent_result_activities::placed($result);

        $this->assertFalse($placed['generated_activities'][0]['parameters']['from_structure']);
    }

    /**
     * An activity the agent made from its template tree keeps saying so.
     */
    public function test_an_activity_marked_from_its_tree_stays_marked(): void {
        $parameters = ['name' => 'A', 'from_structure' => true];
        $result = $this->agent_result(['uid' => 'a', 'section' => 2, 'parameters' => $parameters]);

        $placed = agent_result_activities::placed($result);

        $this->assertTrue($placed['generated_activities'][0]['parameters']['from_structure']);
    }

    /**
     * A row with neither parameters nor a section is not given a mark, and a row with a mark but no section keeps it.
     */
    public function test_a_row_without_parameters_and_section_is_left_alone(): void {
        $bare = ['uid' => 'a'];
        $marked = ['uid' => 'b', 'parameters' => ['from_structure' => true]];

        $placed = agent_result_activities::with_sections([$bare, $marked]);

        $this->assertSame($bare, $placed[0]);
        $this->assertTrue($placed[1]['parameters']['from_structure']);
        $this->assertArrayNotHasKey('section', $placed[1]['parameters']);
    }

    /**
     * The result of any other flow is not given a mark.
     */
    public function test_the_result_of_another_flow_is_not_marked(): void {
        $result = ['generated_activities' => [['uid' => 'a', 'section' => 1, 'parameters' => ['name' => 'A']]]];

        $placed = agent_result_activities::placed($result);

        $this->assertArrayNotHasKey('from_structure', $placed['generated_activities'][0]['parameters']);
    }
}
