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

use local_coursegen\local\models\template;
use local_coursegen\local\service\template_course_order;

/**
 * Putting the activities of a course built from a template in the order the template shows them.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_course_order
 *
 * @runTestsInSeparateProcesses
 */
final class template_course_order_test extends \advanced_testcase {
    /**
     * Save a template of a course.
     *
     * @param int $courseid
     * @return int The template id.
     */
    private function create_template(int $courseid): int {
        $template = new template(0, (object) ['name' => 'Test template', 'courseid' => $courseid]);
        $template->create();
        return (int) $template->get('id');
    }

    /**
     * The cmids of a section, in order.
     *
     * @param int $courseid
     * @param int $number
     * @return int[]
     */
    private function sequence(int $courseid, int $number): array {
        \course_modinfo::clear_instance_cache($courseid);
        $modinfo = get_fast_modinfo($courseid);
        return array_map('intval', $modinfo->sections[$number] ?? []);
    }

    /**
     * A page in a section of a course.
     *
     * @param \stdClass $course
     * @param int $section
     * @return int The cmid.
     */
    private function page(\stdClass $course, int $section): int {
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => $section]);
        return (int) $page->cmid;
    }

    public function test_kept_and_written_activities_follow_the_order_of_the_template(): void {
        $this->resetAfterTest();
        $source = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $target = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $first = $this->page($source, 1);
        $second = $this->page($source, 1);
        $third = $this->page($source, 1);
        $templateid = $this->create_template((int) $source->id);
        // The course is built with the written activity first and the copied ones after it.
        $written = $this->page($target, 1);
        $keptfirst = $this->page($target, 1);
        $keptthird = $this->page($target, 1);

        template_course_order::apply(
            $templateid,
            (int) $target->id,
            [$second => $written],
            [$first => $keptfirst, $third => $keptthird]
        );

        $actual = $this->sequence((int) $target->id, 1);
        $this->assertSame([$keptfirst, $written, $keptthird], $actual);
    }

    public function test_an_activity_the_template_does_not_account_for_stays_after_the_ordered_ones(): void {
        $this->resetAfterTest();
        $source = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $target = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $anchor = $this->page($source, 1);
        $templateid = $this->create_template((int) $source->id);
        $stray = $this->page($target, 1);
        $kept = $this->page($target, 1);

        template_course_order::apply($templateid, (int) $target->id, [], [$anchor => $kept]);

        $actual = $this->sequence((int) $target->id, 1);
        $this->assertSame([$kept, $stray], $actual);
    }

    public function test_each_section_is_ordered_on_its_own(): void {
        $this->resetAfterTest();
        $source = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $target = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $sourceone = $this->page($source, 1);
        $sourcetwoa = $this->page($source, 2);
        $sourcetwob = $this->page($source, 2);
        $templateid = $this->create_template((int) $source->id);
        $targettwob = $this->page($target, 2);
        $targettwoa = $this->page($target, 2);
        $targetone = $this->page($target, 1);

        template_course_order::apply(
            $templateid,
            (int) $target->id,
            [],
            [$sourceone => $targetone, $sourcetwoa => $targettwoa, $sourcetwob => $targettwob]
        );

        $sectionone = $this->sequence((int) $target->id, 1);
        $sectiontwo = $this->sequence((int) $target->id, 2);
        $this->assertSame([$targetone], $sectionone);
        $this->assertSame([$targettwoa, $targettwob], $sectiontwo);
    }

    public function test_a_template_whose_course_is_gone_leaves_the_order_alone(): void {
        $this->resetAfterTest();
        $source = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $target = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $templateid = $this->create_template((int) $source->id);
        $first = $this->page($target, 1);
        $second = $this->page($target, 1);
        delete_course($source, false);

        template_course_order::apply($templateid, (int) $target->id, [], []);

        $actual = $this->sequence((int) $target->id, 1);
        $this->assertSame([$first, $second], $actual);
    }

    public function test_an_unknown_template_leaves_the_order_alone(): void {
        $this->resetAfterTest();
        $target = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $first = $this->page($target, 1);
        $second = $this->page($target, 1);

        template_course_order::apply(987654, (int) $target->id, [], [$first => $second]);

        $actual = $this->sequence((int) $target->id, 1);
        $this->assertSame([$first, $second], $actual);
    }
}
