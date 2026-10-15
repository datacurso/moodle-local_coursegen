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

use local_coursegen\local\service\template_access_copier;

/**
 * The access restrictions of a template item, carried onto its counterpart in the new course.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_access_copier
 */
final class template_access_copier_test extends \advanced_testcase {
    /**
     * Carry a restriction with the counterparts of the test.
     *
     * @param string $tree The restrictions of the template item.
     * @param array $maps What each table maps to.
     * @param array $existing What the new course owns.
     * @return array The restrictions of the new item.
     */
    private function carry(string $tree, array $maps = [], array $existing = []): array {
        $this->resetAfterTest();
        $carried = template_access_copier::carry_availability($tree, $maps, $existing);
        return json_decode((string) $carried, true);
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
     * Dates and nested sets travel as they are, with their operators.
     */
    public function test_dates_and_nested_sets_are_kept(): void {
        $tree = '{"op":"|","show":false,"c":[{"type":"date","d":">=","t":1790000000},' .
            '{"op":"&","c":[{"type":"completion","cm":10,"e":1}]}]}';

        $carried = $this->carry($tree, ['course_modules' => [10 => 77]], ['course_modules' => [77 => true]]);

        $this->assertSame('|', $carried['op']);
        $this->assertFalse($carried['show']);
        $this->assertSame(1790000000, $carried['c'][0]['t']);
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
     * No restriction stays none.
     */
    public function test_an_item_without_restriction_stays_without_one(): void {
        $this->resetAfterTest();

        $this->assertNull(template_access_copier::carry_availability(null, [], []));
        $this->assertNull(template_access_copier::carry_availability('', [], []));
    }
}
