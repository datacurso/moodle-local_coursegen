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

use local_coursegen\local\service\template_section_map;
use local_coursegen\local\service\template_section_tree;
use local_coursegen\local\service\template_unit_resizer;

/**
 * Making a flexsections template hold the number of units the professor asked for.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_unit_resizer
 * @covers     \local_coursegen\local\service\template_section_map
 */
final class template_unit_resizer_test extends \advanced_testcase {
    /**
     * The example course: 0 and 1 on top, units 2, 5 and 8 with two children each, 11 closing.
     *
     * @return array Entries as sections_info describes them.
     */
    private function entries(): array {
        $parents = [
            0 => 0, 1 => 0,
            2 => 0, 3 => 2, 4 => 2,
            5 => 0, 6 => 5, 7 => 5,
            8 => 0, 9 => 8, 10 => 8,
            11 => 0,
        ];
        $entries = [];
        foreach ($parents as $number => $parent) {
            $entries[] = [
                'uid' => 'uid-' . $number,
                'section' => $number,
                'name' => 'S' . $number,
                'format_options' => ['parent' => $parent, 'collapsed' => 0],
                'template_behavior' => ['behavior' => 'aimodify'],
            ];
        }
        return $entries;
    }

    /**
     * The shape of a result: section number => [parent, name].
     *
     * @param array $entries
     * @return array
     */
    private function shape(array $entries): array {
        $shape = [];
        foreach ($entries as $entry) {
            $shape[$entry['section']] = [$entry['parent_section'], $entry['name']];
        }
        return $shape;
    }

    public function test_the_same_number_of_units_changes_nothing(): void {
        $entries = $this->entries();

        $this->assertSame($entries, template_unit_resizer::resize($entries, 3));
    }

    public function test_a_template_without_units_changes_nothing(): void {
        $entries = [['section' => 0], ['section' => 1], ['section' => 2]];

        $this->assertSame($entries, template_unit_resizer::resize($entries, 5));
    }

    public function test_more_units_clone_the_last_unit_before_the_closing_section(): void {
        $result = template_unit_resizer::resize($this->entries(), 4);

        $this->assertSame([
            0 => [0, 'S0'], 1 => [0, 'S1'],
            2 => [0, 'S2'], 3 => [2, 'S3'], 4 => [2, 'S4'],
            5 => [0, 'S5'], 6 => [5, 'S6'], 7 => [5, 'S7'],
            8 => [0, 'S8'], 9 => [8, 'S9'], 10 => [8, 'S10'],
            11 => [0, 'S8'], 12 => [11, 'S9'], 13 => [11, 'S10'],
            14 => [0, 'S11'],
        ], $this->shape($result));
    }

    public function test_a_clone_points_at_the_section_it_was_made_from(): void {
        $result = template_unit_resizer::resize($this->entries(), 4);

        $this->assertSame(11, $result[14]['source_section']);
        $this->assertFalse($result[14]['cloned']);
        $this->assertSame(8, $result[11]['source_section']);
        $this->assertSame(10, $result[13]['source_section']);
        $this->assertTrue($result[11]['cloned']);
        $this->assertTrue($result[13]['cloned']);
    }

    public function test_a_clone_keeps_the_format_options_but_takes_its_own_parent_and_uid(): void {
        $result = template_unit_resizer::resize($this->entries(), 4);

        $this->assertSame(11, $result[12]['format_options']['parent']);
        $this->assertSame(0, $result[12]['format_options']['collapsed']);
        $this->assertNotSame('uid-9', $result[12]['uid']);
        $this->assertNotSame($result[12]['uid'], $result[13]['uid']);
    }

    public function test_many_more_units_clone_the_model_each_time(): void {
        $result = template_unit_resizer::resize($this->entries(), 5);

        $shape = $this->shape($result);
        $this->assertCount(18, $result);
        $this->assertSame([0, 'S8'], $shape[11]);
        $this->assertSame([0, 'S8'], $shape[14]);
        $this->assertSame(14, $result[15]['parent_section']);
        $this->assertSame([0, 'S11'], $shape[17]);
    }

    public function test_fewer_units_drop_whole_subtrees_from_the_end(): void {
        $result = template_unit_resizer::resize($this->entries(), 2);

        $this->assertSame([
            0 => [0, 'S0'], 1 => [0, 'S1'],
            2 => [0, 'S2'], 3 => [2, 'S3'], 4 => [2, 'S4'],
            5 => [0, 'S5'], 6 => [5, 'S6'], 7 => [5, 'S7'],
            8 => [0, 'S11'],
        ], $this->shape($result));
        $this->assertSame(11, $result[8]['source_section']);
    }

    public function test_at_least_one_unit_is_kept(): void {
        $result = template_unit_resizer::resize($this->entries(), 0);

        $this->assertSame([
            0 => [0, 'S0'], 1 => [0, 'S1'],
            2 => [0, 'S2'], 3 => [2, 'S3'], 4 => [2, 'S4'],
            5 => [0, 'S11'],
        ], $this->shape($result));
    }

    public function test_the_depth_of_every_section_is_given(): void {
        $result = template_unit_resizer::resize($this->entries(), 4);

        $this->assertSame(0, $result[11]['depth']);
        $this->assertSame(1, $result[12]['depth']);
    }

    public function test_the_map_turns_a_new_number_into_the_template_number(): void {
        $map = template_section_map::from_sections_info(template_unit_resizer::resize($this->entries(), 4));

        $this->assertSame(8, $map->source_of(11));
        $this->assertSame(11, $map->source_of(14));
        $this->assertTrue($map->is_cloned(12));
        $this->assertFalse($map->is_cloned(14));
        $this->assertSame(14, $map->target_of_source(11));
        $this->assertSame(8, $map->target_of_source(8));
        $this->assertFalse($map->is_identity());
    }

    public function test_a_map_of_entries_without_a_source_is_the_identity(): void {
        $map = template_section_map::from_sections_info($this->entries());

        $this->assertTrue($map->is_identity());
        $this->assertSame(7, $map->source_of(7));
        $this->assertSame(7, $map->target_of_source(7));
        $this->assertFalse($map->is_cloned(7));
    }

    /**
     * The activities of the example course: a kept one in section 1, instances in sections 2 and 9, a kept one in 10.
     *
     * @return array
     */
    private function activities(): array {
        return [
            ['uid' => 'a1', 'cmid' => 11, 'parameters' => ['section' => 1], 'template_behavior' => ['action' => 'keep']],
            ['uid' => 'a2', 'cmid' => -1, 'parameters' => ['section' => 2], 'template_behavior' => ['action' => 'instance']],
            ['uid' => 'a9', 'cmid' => -2, 'parameters' => ['section' => 9], 'template_behavior' => ['action' => 'instance']],
            ['uid' => 'a10', 'cmid' => 12, 'parameters' => ['section' => 10], 'template_behavior' => ['action' => 'keep']],
            ['uid' => 'a11', 'cmid' => 13, 'parameters' => ['section' => 11], 'template_behavior' => ['action' => 'keep']],
        ];
    }

    public function test_activities_follow_their_section_to_its_new_number(): void {
        $sections = template_unit_resizer::resize($this->entries(), 4);

        $result = template_unit_resizer::activities($this->activities(), $sections);

        $bycmid = array_column($result, 'parameters', 'uid');
        $this->assertSame(1, $bycmid['a1']['section']);
        $this->assertSame(14, $bycmid['a11']['section']);
        $this->assertSame(9, $bycmid['a9']['section']);
    }

    public function test_the_instances_of_the_model_unit_are_cloned_into_each_clone(): void {
        $sections = template_unit_resizer::resize($this->entries(), 4);

        $result = template_unit_resizer::activities($this->activities(), $sections);

        $clones = array_values(array_filter($result, static fn($a) => $a['parameters']['section'] === 12));
        $this->assertCount(1, $clones);
        $this->assertSame('instance', $clones[0]['template_behavior']['action']);
        $this->assertNotSame('a9', $clones[0]['uid']);
        $this->assertCount(5 + 1, $result);
        $this->assertCount(6, array_unique(array_column($result, 'cmid')));
        $this->assertCount(6, array_unique(array_column($result, 'uid')));
    }

    public function test_the_kept_activities_of_the_model_unit_are_not_cloned(): void {
        $sections = template_unit_resizer::resize($this->entries(), 4);

        $result = template_unit_resizer::activities($this->activities(), $sections);

        $this->assertSame([], array_filter($result, static fn($a) => $a['parameters']['section'] === 13));
    }

    public function test_the_activities_of_a_dropped_unit_are_dropped(): void {
        $sections = template_unit_resizer::resize($this->entries(), 2);

        $result = template_unit_resizer::activities($this->activities(), $sections);

        $this->assertSame(['a1', 'a2', 'a11'], array_column($result, 'uid'));
        $this->assertSame(8, $result[2]['parameters']['section']);
    }

    public function test_activities_are_untouched_when_the_sections_were_not_resized(): void {
        $sections = template_unit_resizer::resize($this->entries(), 3);

        $this->assertSame($this->activities(), template_unit_resizer::activities($this->activities(), $sections));
    }

    public function test_the_structure_the_plugin_sent_is_given_back_to_what_the_service_answered(): void {
        $sent = template_unit_resizer::resize($this->entries(), 4);
        $answered = [];
        foreach ($sent as $entry) {
            $answered[] = ['section' => $entry['section'], 'name' => 'Renamed ' . $entry['section']];
        }

        $result = template_unit_resizer::with_structure_of($answered, $sent);

        $this->assertSame('Renamed 12', $result[12]['name']);
        $this->assertSame(11, $result[12]['parent_section']);
        $this->assertSame(11, $result[12]['format_options']['parent']);
        $this->assertSame(9, $result[12]['source_section']);
        $this->assertTrue($result[11]['cloned']);
    }

    public function test_an_answer_with_nothing_sent_for_a_section_is_left_alone(): void {
        $answered = [['section' => 0, 'name' => 'General'], ['section' => 1, 'name' => 'One']];

        $this->assertSame($answered, template_unit_resizer::with_structure_of($answered, []));
    }

    /**
     * A template with no closing section: 0, then unit 1 (child 2) and unit 2 (child 3)... as 0, 1(2), 3(4).
     *
     * @return array
     */
    private function entries_without_closing(): array {
        $entries = [];
        foreach ([0 => 0, 1 => 0, 2 => 1, 3 => 0, 4 => 3] as $number => $parent) {
            $entries[] = [
                'uid' => 'uid-' . $number,
                'section' => $number,
                'name' => 'S' . $number,
                'format_options' => ['parent' => $parent],
            ];
        }
        return $entries;
    }

    public function test_the_activities_of_a_dropped_unit_are_dropped_when_nothing_closes_the_course(): void {
        $sections = template_unit_resizer::resize($this->entries_without_closing(), 1);
        $activities = [];
        foreach ([1, 2, 3, 4] as $number) {
            $activities[] = [
                'uid' => 'a' . $number, 'cmid' => $number,
                'parameters' => ['section' => $number], 'template_behavior' => ['action' => 'keep'],
            ];
        }

        $result = template_unit_resizer::activities($activities, $sections);

        $this->assertCount(3, $sections);
        $this->assertSame(['a1', 'a2'], array_column($result, 'uid'));
    }

    public function test_the_map_is_not_the_identity_when_a_template_section_was_removed(): void {
        $sections = template_unit_resizer::resize($this->entries_without_closing(), 1);
        $map = template_section_map::from_sections_info($sections);

        $this->assertFalse($map->is_identity(5));
        $this->assertTrue($map->is_identity(3));
    }

    public function test_the_number_of_units_is_capped(): void {
        $result = template_unit_resizer::resize($this->entries(), 1000);

        $units = template_section_tree::from_entries($result)->units();
        $this->assertCount(template_unit_resizer::MAX_UNITS, $units);
    }

    public function test_a_negative_number_of_units_keeps_one(): void {
        $result = template_unit_resizer::resize($this->entries(), -4);

        $this->assertSame(6, count($result));
    }

    public function test_an_answer_with_another_number_of_sections_is_reported(): void {
        $sent = template_unit_resizer::resize($this->entries(), 2);
        $answered = array_slice($sent, 0, 3);

        $result = template_unit_resizer::with_structure_of($answered, $sent);

        $this->assertDebuggingCalled();
        $this->assertCount(3, $result);
    }

    public function test_an_answered_section_the_plugin_never_sent_is_reported_and_left_alone(): void {
        $sent = template_unit_resizer::resize($this->entries(), 2);
        $answered = [];
        foreach ($sent as $entry) {
            $answered[] = ['section' => $entry['section'], 'name' => 'N'];
        }
        $answered[1]['section'] = 99;

        $result = template_unit_resizer::with_structure_of($answered, $sent);

        $this->assertDebuggingCalled();
        $this->assertSame(['section' => 99, 'name' => 'N'], $result[1]);
        $this->assertSame(0, $result[0]['parent_section']);
    }

    public function test_a_matching_answer_reports_nothing(): void {
        $sent = template_unit_resizer::resize($this->entries(), 2);
        $answered = [];
        foreach ($sent as $entry) {
            $answered[] = ['section' => $entry['section'], 'name' => 'N'];
        }

        template_unit_resizer::with_structure_of($answered, $sent);

        $this->assertDebuggingNotCalled();
    }
}
