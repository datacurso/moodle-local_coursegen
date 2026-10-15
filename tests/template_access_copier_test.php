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
 * The access of a template activity, carried onto its counterpart in the new course.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_access_copier
 */
final class template_access_copier_test extends \advanced_testcase {
    /**
     * A completion restriction points at the counterpart of the activity it names.
     */
    public function test_a_completion_restriction_points_at_the_new_activity(): void {
        $tree = '{"op":"&","showc":[true],"c":[{"type":"completion","cm":13006,"e":1}]}';

        $remapped = json_decode(template_access_copier::remap_availability($tree, [13006 => 555]), true);

        $this->assertSame(555, $remapped['c'][0]['cm']);
        $this->assertSame([true], $remapped['showc']);
    }

    /**
     * A completion with no counterpart is left out, and with nothing else left there is no restriction.
     */
    public function test_a_completion_without_counterpart_leaves_no_restriction(): void {
        $tree = '{"op":"&","showc":[true],"c":[{"type":"completion","cm":13006,"e":1}]}';

        $this->assertNull(template_access_copier::remap_availability($tree, [999 => 1]));
    }

    /**
     * What names the template's course is dropped and the rest stays, with what it shows kept in step.
     */
    public function test_conditions_of_the_template_course_are_dropped_and_the_rest_kept(): void {
        $tree = '{"op":"&","showc":[true,false,true],"c":[{"type":"group","id":4},' .
            '{"type":"date","d":">=","t":1790000000},{"type":"completion","cm":10,"e":1}]}';

        $remapped = json_decode(template_access_copier::remap_availability($tree, [10 => 77]), true);

        $this->assertCount(2, $remapped['c']);
        $this->assertSame('date', $remapped['c'][0]['type']);
        $this->assertSame(77, $remapped['c'][1]['cm']);
        $this->assertSame([false, true], $remapped['showc']);
    }

    /**
     * No restriction stays none.
     */
    public function test_an_activity_without_restriction_stays_without_one(): void {
        $this->assertNull(template_access_copier::remap_availability(null, []));
        $this->assertNull(template_access_copier::remap_availability('', []));
    }
}
