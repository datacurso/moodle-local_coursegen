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

use local_coursegen\local\service\guideline_unit_locks;

/**
 * Units the guideline locks get a completion availability pointing at the previous unit's activities.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\guideline_unit_locks
 */
final class guideline_unit_locks_test extends \advanced_testcase {
    /**
     * A course with three unit sections, each holding a tracked quiz.
     *
     * @return array The course, the section entries, the activity entries and the placeholder map.
     */
    private function three_units(): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'numsections' => 3]);
        $sections = [];
        $activities = [];
        $map = [];
        for ($unit = 1; $unit <= 3; $unit++) {
            $quiz = $this->getDataGenerator()->create_module('quiz', [
                'course' => $course->id,
                'section' => $unit,
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'completionusegrade' => 1,
            ]);
            $cm = get_coursemodule_from_instance('quiz', $quiz->id);
            $activities[] = ['uid' => "quiz$unit", 'cmid' => -$unit, 'resource_type' => 'quiz'];
            $map[-$unit] = (int) $cm->id;
            $sections[] = ['uid' => "unit$unit", 'section' => $unit, 'name' => "Unit $unit"];
        }
        return [$course, $sections, $activities, $map];
    }

    /**
     * The availability JSON stored on a section, decoded.
     *
     * @param int $courseid
     * @param int $number
     * @return \stdClass|null
     */
    private function availability(int $courseid, int $number): ?\stdClass {
        global $DB;
        $json = $DB->get_field('course_sections', 'availability', ['course' => $courseid, 'section' => $number]);
        if (empty($json)) {
            return null;
        }
        return json_decode($json);
    }

    /**
     * Units 2 and 3 are restricted to the completion of the previous unit's quiz; unit 1 stays open.
     */
    public function test_units_two_and_three_point_to_the_previous_cmids(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $sections, $activities, $map] = $this->three_units();
        $sections[1]['unlock_requires_uids'] = ['quiz1'];
        $sections[2]['unlock_requires_uids'] = ['quiz2'];
        guideline_unit_locks::apply((int) $course->id, $sections, $activities, $map);

        $this->assertNull($this->availability($course->id, 1));
        $second = $this->availability($course->id, 2);
        $this->assertEquals('&', $second->op);
        $this->assertEquals([true], $second->showc);
        $this->assertEquals('completion', $second->c[0]->type);
        $this->assertEquals($map[-1], $second->c[0]->cm);
        $this->assertEquals(COMPLETION_COMPLETE, $second->c[0]->e);
        $third = $this->availability($course->id, 3);
        $this->assertEquals($map[-2], $third->c[0]->cm);
        $modinfo = get_fast_modinfo($course->id);
        $this->assertNotEmpty($modinfo->get_section_info(2)->availability);
        $this->assertEquals(1, $modinfo->get_section_info(2)->visible);
    }

    /**
     * Several required activities become several conditions, each shown.
     */
    public function test_several_activities_make_several_conditions(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $sections, $activities, $map] = $this->three_units();
        $sections[2]['unlock_requires_uids'] = ['quiz1', 'quiz2'];
        guideline_unit_locks::apply((int) $course->id, $sections, $activities, $map);

        $third = $this->availability($course->id, 3);
        $this->assertCount(2, $third->c);
        $this->assertEquals([true, true], $third->showc);
    }

    /**
     * Entries without the key are untouched.
     */
    public function test_entries_without_the_key_are_untouched(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $sections, $activities, $map] = $this->three_units();
        guideline_unit_locks::apply((int) $course->id, $sections, $activities, $map);

        foreach ([1, 2, 3] as $number) {
            $this->assertNull($this->availability($course->id, $number));
        }
    }

    /**
     * An uid that was not created, or whose module does not track completion, is left out of the condition.
     */
    public function test_unmapped_and_untracked_activities_are_skipped(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $sections, $activities, $map] = $this->three_units();
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_NONE, ['id' => $map[-2]]);
        $sections[1]['unlock_requires_uids'] = ['ghost', 'quiz1'];
        $sections[2]['unlock_requires_uids'] = ['quiz2'];
        guideline_unit_locks::apply((int) $course->id, $sections, $activities, $map);
        $this->assertDebuggingCalledCount(3);

        $second = $this->availability($course->id, 2);
        $this->assertCount(1, $second->c);
        $this->assertEquals($map[-1], $second->c[0]->cm);
        $this->assertNull($this->availability($course->id, 3));
    }
}
