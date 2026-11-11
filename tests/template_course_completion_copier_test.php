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

use local_coursegen\local\service\template_course_completion_copier;

/**
 * The criteria that complete the template's course, carried onto the new course.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_course_completion_copier
 */
final class template_course_completion_copier_test extends \advanced_testcase {
    /**
     * An activity criterion points at the counterpart, a date criterion is left out and a grade one is kept.
     */
    public function test_criteria_follow_the_counterparts_and_leave_the_date_out(): void {
        global $DB;

        $this->resetAfterTest();
        $base = $this->getDataGenerator()->create_course();
        $new = $this->getDataGenerator()->create_course();
        $rows = [
            ['criteriatype' => 4, 'module' => 'quiz', 'moduleinstance' => 100],
            ['criteriatype' => 4, 'module' => 'quiz', 'moduleinstance' => 101],
            ['criteriatype' => 2, 'timeend' => 1790000000],
            ['criteriatype' => 6, 'gradepass' => 70],
        ];
        foreach ($rows as $row) {
            $DB->insert_record('course_completion_criteria', (object) ($row + ['course' => $base->id]));
        }

        template_course_completion_copier::copy((int) $base->id, (int) $new->id, [100 => 555]);

        $copied = $DB->get_records('course_completion_criteria', ['course' => $new->id], 'id');
        $this->assertCount(2, $copied);
        $types = array_values(array_map(static fn($row) => (int) $row->criteriatype, $copied));
        $this->assertSame([4, 6], $types);
        $this->assertSame(555, (int) reset($copied)->moduleinstance);
    }

    /**
     * How the criteria are added up is the template's.
     */
    public function test_the_aggregation_is_the_templates(): void {
        global $DB;

        $this->resetAfterTest();
        $base = $this->getDataGenerator()->create_course();
        $new = $this->getDataGenerator()->create_course();
        $DB->insert_record('course_completion_aggr_methd', (object) [
            'course' => $base->id, 'criteriatype' => null, 'method' => 2, 'value' => null,
        ]);

        template_course_completion_copier::copy((int) $base->id, (int) $new->id, []);

        $this->assertSame(2, (int) $DB->get_field('course_completion_aggr_methd', 'method', ['course' => $new->id]));
    }
}
