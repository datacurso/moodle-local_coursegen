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
use local_coursegen\local\models\template_instance;
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
     * Save a virtual row of a template.
     *
     * @param int $templateid
     * @param int $sectionid
     * @param string $uid
     * @param int $aftercmid
     * @param int $sortorder
     */
    private function create_instance(int $templateid, int $sectionid, string $uid, int $aftercmid, int $sortorder = 0): void {
        $instance = new template_instance(0, (object) [
            'uid' => $uid,
            'templateid' => $templateid,
            'sectionid' => $sectionid,
            'sourcecmid' => 0,
            'sourcename' => 'Source',
            'name' => $uid,
            'typelabel' => 'Page',
            'modname' => 'page',
            'aftercmid' => $aftercmid,
            'sortorder' => $sortorder,
        ]);
        $instance->create();
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
     * A payload entry of an instance, with the created cmid it maps to.
     *
     * @param string $uid
     * @param int $payloadcmid
     * @return array
     */
    private function entry(string $uid, int $payloadcmid): array {
        return ['uid' => $uid, 'cmid' => $payloadcmid];
    }

    /**
     * Instances go right after their anchor and the kept activities keep their place.
     */
    public function test_instances_follow_their_anchor_and_kept_activities_keep_their_place(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $base = $generator->create_course(['numsections' => 1]);
        $hub = $generator->create_module('label', ['course' => $base->id, 'section' => 1]);
        $excluded = $generator->create_module('lesson', ['course' => $base->id, 'section' => 1]);
        $tail = $generator->create_module('feedback', ['course' => $base->id, 'section' => 1]);
        $templateid = $this->create_template($base->id);
        $sectionid = (int) get_fast_modinfo($base)->get_section_info(1)->id;
        $this->create_instance($templateid, $sectionid, 'lesson-1', (int) $excluded->cmid, 0);
        $this->create_instance($templateid, $sectionid, 'forum-1', (int) $excluded->cmid, 1);

        $target = $generator->create_course(['numsections' => 1]);
        $forum = $generator->create_module('forum', ['course' => $target->id, 'section' => 1]);
        $lesson = $generator->create_module('lesson', ['course' => $target->id, 'section' => 1]);
        $keptfeedback = $generator->create_module('feedback', ['course' => $target->id, 'section' => 1]);
        $kepthub = $generator->create_module('label', ['course' => $target->id, 'section' => 1]);

        template_course_order::apply(
            $templateid,
            $target->id,
            [$this->entry('lesson-1', -1), $this->entry('forum-1', -2)],
            [-1 => (int) $lesson->cmid, -2 => (int) $forum->cmid],
            [(int) $hub->cmid => (int) $kepthub->cmid, (int) $tail->cmid => (int) $keptfeedback->cmid]
        );

        $expected = [(int) $kepthub->cmid, (int) $lesson->cmid, (int) $forum->cmid, (int) $keptfeedback->cmid];
        $this->assertSame($expected, $this->sequence($target->id, 1));
    }

    /**
     * Instances sharing an anchor keep the order of their sort order.
     */
    public function test_instances_sharing_an_anchor_follow_their_sort_order(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $base = $generator->create_course(['numsections' => 1]);
        $anchor = $generator->create_module('lesson', ['course' => $base->id, 'section' => 1]);
        $templateid = $this->create_template($base->id);
        $sectionid = (int) get_fast_modinfo($base)->get_section_info(1)->id;
        $this->create_instance($templateid, $sectionid, 'second', (int) $anchor->cmid, 2);
        $this->create_instance($templateid, $sectionid, 'first', (int) $anchor->cmid, 1);

        $target = $generator->create_course(['numsections' => 1]);
        $second = $generator->create_module('page', ['course' => $target->id, 'section' => 1]);
        $first = $generator->create_module('page', ['course' => $target->id, 'section' => 1]);

        template_course_order::apply(
            $templateid,
            $target->id,
            [$this->entry('second', -1), $this->entry('first', -2)],
            [-1 => (int) $second->cmid, -2 => (int) $first->cmid],
            []
        );

        $this->assertSame([(int) $first->cmid, (int) $second->cmid], $this->sequence($target->id, 1));
    }

    /**
     * An activity that no row accounts for stays after the ones that do.
     */
    public function test_an_activity_without_a_row_stays_after_the_ordered_ones(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $base = $generator->create_course(['numsections' => 1]);
        $anchor = $generator->create_module('lesson', ['course' => $base->id, 'section' => 1]);
        $templateid = $this->create_template($base->id);
        $sectionid = (int) get_fast_modinfo($base)->get_section_info(1)->id;
        $this->create_instance($templateid, $sectionid, 'inst', (int) $anchor->cmid);

        $target = $generator->create_course(['numsections' => 1]);
        $stray = $generator->create_module('page', ['course' => $target->id, 'section' => 1]);
        $instance = $generator->create_module('page', ['course' => $target->id, 'section' => 1]);

        template_course_order::apply(
            $templateid,
            $target->id,
            [$this->entry('inst', -1)],
            [-1 => (int) $instance->cmid],
            []
        );

        $this->assertSame([(int) $instance->cmid, (int) $stray->cmid], $this->sequence($target->id, 1));
    }

    /**
     * Each section is ordered on its own.
     */
    public function test_each_section_is_ordered_on_its_own(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $base = $generator->create_course(['numsections' => 2]);
        $firstanchor = $generator->create_module('lesson', ['course' => $base->id, 'section' => 1]);
        $secondanchor = $generator->create_module('lesson', ['course' => $base->id, 'section' => 2]);
        $templateid = $this->create_template($base->id);
        $modinfo = get_fast_modinfo($base);
        $this->create_instance($templateid, (int) $modinfo->get_section_info(2)->id, 'two-b', (int) $secondanchor->cmid, 1);
        $this->create_instance($templateid, (int) $modinfo->get_section_info(2)->id, 'two-a', (int) $secondanchor->cmid, 0);
        $this->create_instance($templateid, (int) $modinfo->get_section_info(1)->id, 'one', (int) $firstanchor->cmid);

        $target = $generator->create_course(['numsections' => 2]);
        $twob = $generator->create_module('page', ['course' => $target->id, 'section' => 2]);
        $one = $generator->create_module('page', ['course' => $target->id, 'section' => 1]);
        $twoa = $generator->create_module('page', ['course' => $target->id, 'section' => 2]);

        template_course_order::apply(
            $templateid,
            $target->id,
            [$this->entry('two-b', -1), $this->entry('one', -2), $this->entry('two-a', -3)],
            [-1 => (int) $twob->cmid, -2 => (int) $one->cmid, -3 => (int) $twoa->cmid],
            []
        );

        $this->assertSame([(int) $one->cmid], $this->sequence($target->id, 1));
        $this->assertSame([(int) $twoa->cmid, (int) $twob->cmid], $this->sequence($target->id, 2));
    }

    /**
     * A template that does not exist, or whose course is gone, changes nothing.
     */
    public function test_missing_template_or_base_course_changes_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $base = $generator->create_course();
        $target = $generator->create_course(['numsections' => 1]);
        $first = $generator->create_module('page', ['course' => $target->id, 'section' => 1]);
        $second = $generator->create_module('page', ['course' => $target->id, 'section' => 1]);
        $templateid = $this->create_template($base->id);
        $expected = [(int) $first->cmid, (int) $second->cmid];

        template_course_order::apply(999999, $target->id, [], [], []);
        $DB->delete_records('course', ['id' => $base->id]);
        template_course_order::apply($templateid, $target->id, [], [], []);

        $this->assertSame($expected, $this->sequence($target->id, 1));
    }
}
