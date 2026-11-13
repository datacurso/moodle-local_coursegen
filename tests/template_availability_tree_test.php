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

use local_coursegen\local\service\template_availability_tree;

/**
 * The access restrictions of a template item, carried onto its counterpart in the new course.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_availability_tree
 */
final class template_availability_tree_test extends \advanced_testcase {
    /**
     * Carry a restriction with the counterparts of the test.
     *
     * @param string $tree The restrictions of the template item.
     * @param array $maps What each table maps to.
     * @param array $existing What the new course owns.
     * @return array|null The restrictions of the new item.
     */
    private function carry(string $tree, array $maps = [], array $existing = []): ?array {
        $this->resetAfterTest();
        return json_decode((string) template_availability_tree::carry($tree, $maps, $existing), true);
    }

    /**
     * A completion restriction points at the counterpart of the activity it names.
     */
    public function test_a_completion_restriction_points_at_the_new_activity(): void {
        $tree = '{"op":"&","showc":[true],"c":[{"type":"completion","cm":13006,"e":1}]}';

        $carried = $this->carry($tree, ['course_modules' => [13006 => 555]], ['course_modules' => [555 => true]]);

        $this->assertSame(555, $carried['c'][0]['cm']);
        $this->assertSame([true], $carried['showc']);
    }

    /**
     * The activity before this one stays "the previous one", whatever the ids are.
     */
    public function test_the_previous_activity_condition_is_kept(): void {
        $tree = '{"op":"&","showc":[true],"c":[{"type":"completion","cm":-1,"e":1}]}';

        $carried = $this->carry($tree, ['course_modules' => [1 => 555]], ['course_modules' => [555 => true]]);

        $this->assertSame(-1, $carried['c'][0]['cm']);
    }

    /**
     * What still names something of the template's course is marked as Moodle's restore marks it.
     */
    public function test_references_without_counterpart_are_marked_broken(): void {
        $tree = '{"op":"&","showc":[true,true,true,true],"c":[{"type":"completion","cm":10,"e":1},' .
            '{"type":"grade","id":20},{"type":"group","id":30},{"type":"grouping","id":40}]}';

        $carried = $this->carry($tree);

        $this->assertSame(0, $carried['c'][0]['cm']);
        $this->assertSame(0, $carried['c'][1]['id']);
        $this->assertSame(-1, $carried['c'][2]['id']);
        $this->assertSame(-1, $carried['c'][3]['id']);
    }

    /**
     * Grades, groups and groupings point at their counterparts, and "any group" stays as it is.
     */
    public function test_grades_groups_and_groupings_point_at_their_counterparts(): void {
        $tree = '{"op":"&","showc":[true,true,true,true],"c":[{"type":"grade","id":20,"min":50},' .
            '{"type":"group","id":30},{"type":"grouping","id":40},{"type":"group"}]}';
        $maps = ['grade_items' => [20 => 21], 'groups' => [30 => 31], 'groupings' => [40 => 41]];
        $existing = ['grade_items' => [21 => true], 'groups' => [31 => true], 'groupings' => [41 => true]];

        $carried = $this->carry($tree, $maps, $existing);

        $this->assertSame(21, $carried['c'][0]['id']);
        $this->assertSame(31, $carried['c'][1]['id']);
        $this->assertSame(41, $carried['c'][2]['id']);
        $this->assertArrayNotHasKey('id', $carried['c'][3]);
    }

    /**
     * Carry a restriction while shifting its dates.
     *
     * @param string $tree The restrictions of the template item.
     * @param int|null $delta Seconds to shift the dates by; null drops them.
     * @return array|null The restrictions of the new item.
     */
    private function carry_shifted(string $tree, ?int $delta): ?array {
        $this->resetAfterTest();
        return json_decode((string) template_availability_tree::carry($tree, [], [], $delta), true);
    }

    /**
     * Without a usable shift the conditions on a date are left out, the rest stays with what it shows, and a set
     * left empty goes too.
     */
    public function test_dates_are_left_out_when_there_is_no_shift_and_the_rest_is_kept(): void {
        $tree = '{"op":"&","showc":[false,true,true],"c":[{"type":"date","d":">=","t":1790000000},' .
            '{"type":"profile","op":"isnotempty","sf":"email"},' .
            '{"op":"|","c":[{"type":"date","d":"<","t":1791000000}]}]}';

        $carried = $this->carry_shifted($tree, null);

        $this->assertCount(1, $carried['c']);
        $this->assertSame('profile', $carried['c'][0]['type']);
        $this->assertSame([true], $carried['showc']);
    }

    /**
     * A restriction made only of dates leaves no restriction when there is no shift.
     */
    public function test_a_restriction_of_only_dates_leaves_none_when_there_is_no_shift(): void {
        $tree = '{"op":"&","showc":[true],"c":[{"type":"date","d":">=","t":1790000000}]}';

        $this->assertNull($this->carry_shifted($tree, null));
    }

    /**
     * A zero shift keeps the dates as they are.
     */
    public function test_a_zero_shift_keeps_the_dates(): void {
        $tree = '{"op":"&","showc":[true],"c":[{"type":"date","d":">=","t":1790000000}]}';

        $carried = $this->carry_shifted($tree, 0);

        $this->assertSame(1790000000, $carried['c'][0]['t']);
        $this->assertSame('>=', $carried['c'][0]['d']);
    }

    /**
     * The default is a zero shift, not dropping the dates.
     */
    public function test_the_default_shift_is_zero(): void {
        $this->resetAfterTest();
        $tree = '{"op":"&","showc":[true],"c":[{"type":"date","d":">=","t":1790000000}]}';

        $carried = json_decode((string) template_availability_tree::carry($tree, [], []), true);

        $this->assertSame(1790000000, $carried['c'][0]['t']);
    }

    /**
     * A positive shift moves every date later, both operators stay and so does what each one shows.
     */
    public function test_a_positive_shift_moves_dates_and_keeps_operators_and_showc(): void {
        $tree = '{"op":"&","showc":[false,true],"c":[{"type":"date","d":">=","t":1794895740},' .
            '{"type":"date","d":"<","t":1795464540}]}';

        $carried = $this->carry_shifted($tree, 86400 * 10);

        $this->assertSame(1794895740 + 864000, $carried['c'][0]['t']);
        $this->assertSame('>=', $carried['c'][0]['d']);
        $this->assertSame(1795464540 + 864000, $carried['c'][1]['t']);
        $this->assertSame('<', $carried['c'][1]['d']);
        $this->assertSame([false, true], $carried['showc']);
    }

    /**
     * A negative shift moves dates earlier.
     */
    public function test_a_negative_shift_moves_dates_earlier(): void {
        $tree = '{"op":"&","showc":[true],"c":[{"type":"date","d":">=","t":1794895740}]}';

        $carried = $this->carry_shifted($tree, -86400 * 30);

        $this->assertSame(1794895740 - 2592000, $carried['c'][0]['t']);
    }

    /**
     * The distance between two dates of the template is the distance between the shifted ones.
     */
    public function test_the_spacing_between_dates_is_preserved(): void {
        $tree = '{"op":"&","showc":[true,true],"c":[{"type":"date","d":">=","t":1794895740},' .
            '{"type":"date","d":"<","t":1795464540}]}';

        $carried = $this->carry_shifted($tree, 86400 * 123);

        $this->assertSame(568800, $carried['c'][1]['t'] - $carried['c'][0]['t']);
    }

    /**
     * Dates inside nested AND, OR, NOT AND and NOT OR sets are shifted, and the other conditions are not touched.
     */
    public function test_dates_in_nested_sets_of_every_operator_are_shifted(): void {
        $tree = '{"op":"&","showc":[true,true,true,true],"c":[' .
            '{"op":"|","c":[{"type":"date","d":">=","t":1000},{"type":"profile","op":"isnotempty","sf":"city"}]},' .
            '{"op":"!&","c":[{"type":"date","d":"<","t":2000}]},' .
            '{"op":"!|","c":[{"op":"&","c":[{"type":"date","d":">=","t":3000}]}]},' .
            '{"type":"profile","op":"isnotempty","sf":"email"}]}';

        $carried = $this->carry_shifted($tree, 500);

        $this->assertSame(1500, $carried['c'][0]['c'][0]['t']);
        $this->assertSame('profile', $carried['c'][0]['c'][1]['type']);
        $this->assertSame('!&', $carried['c'][1]['op']);
        $this->assertSame(2500, $carried['c'][1]['c'][0]['t']);
        $this->assertSame('!|', $carried['c'][2]['op']);
        $this->assertSame(3500, $carried['c'][2]['c'][0]['c'][0]['t']);
        $this->assertSame([true, true, true, true], $carried['showc']);
    }

    /**
     * Nested sets travel with their operators.
     */
    public function test_nested_sets_are_kept(): void {
        $tree = '{"op":"|","show":false,"c":[{"type":"profile","op":"isnotempty","sf":"city"},' .
            '{"op":"&","c":[{"type":"completion","cm":10,"e":1}]}]}';

        $carried = $this->carry($tree, ['course_modules' => [10 => 77]], ['course_modules' => [77 => true]]);

        $this->assertSame('|', $carried['op']);
        $this->assertFalse($carried['show']);
        $this->assertSame(77, $carried['c'][1]['c'][0]['cm']);
    }

    /**
     * An id that is a new id of one mapping and an old id of another one is not changed twice.
     */
    public function test_a_new_id_that_is_an_old_id_of_another_is_not_changed_twice(): void {
        $tree = '{"op":"&","showc":[true,true],"c":[{"type":"completion","cm":10,"e":1},' .
            '{"type":"completion","cm":20,"e":1}]}';
        $maps = ['course_modules' => [10 => 20, 20 => 30]];

        $carried = $this->carry($tree, $maps, ['course_modules' => [20 => true, 30 => true]]);

        $this->assertSame(20, $carried['c'][0]['cm']);
        $this->assertSame(30, $carried['c'][1]['cm']);
    }

    /**
     * The groups and groupings a restriction names are found at any depth.
     */
    public function test_the_groups_a_restriction_names_are_found(): void {
        $tree = '{"op":"&","showc":[true,true],"c":[{"type":"group","id":4},' .
            '{"op":"|","c":[{"type":"grouping","id":9},{"type":"group"}]}]}';

        $this->assertSame(['group' => [4], 'grouping' => [9]], template_availability_tree::group_ids($tree));
    }

    /**
     * No restriction stays none.
     */
    public function test_an_item_without_restriction_stays_without_one(): void {
        $this->resetAfterTest();

        $this->assertNull(template_availability_tree::carry(null, [], []));
        $this->assertNull(template_availability_tree::carry('', [], []));
    }
}
