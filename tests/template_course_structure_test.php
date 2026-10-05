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

use local_coursegen\local\template\course_structure;

/**
 * Tests for the list of sections and activities of a course.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\template\course_structure
 */
final class template_course_structure_test extends \advanced_testcase {
    /**
     * Set up a clean site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Only a course that exists and is not the front page can be a template base.
     */
    public function test_usable_courses(): void {
        $course = $this->getDataGenerator()->create_course();

        $usable = course_structure::is_usable_course((int) $course->id);
        $this->assertTrue($usable);
        $usable2 = course_structure::is_usable_course(SITEID);
        $this->assertFalse($usable2);
        $usable3 = course_structure::is_usable_course(0);
        $this->assertFalse($usable3);
        $usable4 = course_structure::is_usable_course(-1);
        $this->assertFalse($usable4);
        $usable5 = course_structure::is_usable_course(424242);
        $this->assertFalse($usable5);
    }

    /**
     * A course with no activities lists its sections without activities.
     */
    public function test_empty_course_lists_sections_without_activities(): void {
        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);

        $sections = course_structure::for_course((int) $course->id);

        $this->assertCount(4, $sections);
        foreach ($sections as $section) {
            $this->assertSame([], $section['activities']);
        }
        $column = array_column($sections, 'number');
        $this->assertSame([0, 1, 2, 3], $column);
    }

    /**
     * Activities appear in their section in order, and hidden activities and labels are listed.
     */
    public function test_activities_are_listed_in_order_with_hidden_and_labels(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 2]);
        $first = $generator->create_module('page', ['course' => $course->id, 'name' => 'First', 'section' => 1]);
        $hidden = $generator->create_module('page', ['course' => $course->id, 'name' => 'Hidden', 'section' => 1, 'visible' => 0]);
        $label = $generator->create_module('label', ['course' => $course->id, 'intro' => 'Just text', 'section' => 1]);
        $generator->create_module('forum', ['course' => $course->id, 'name' => 'Forum', 'section' => 2]);

        $sections = course_structure::for_course((int) $course->id);

        $this->assertSame([], $sections[0]['activities']);
        $column = array_column($sections[1]['activities'], 'cmid');
        $this->assertSame([(int) $first->cmid, (int) $hidden->cmid, (int) $label->cmid], $column);
        $this->assertTrue($sections[1]['activities'][0]['visible']);
        $this->assertFalse($sections[1]['activities'][1]['visible']);
        $this->assertSame('label', $sections[1]['activities'][2]['modname']);
        $this->assertSame('forum', $sections[2]['activities'][0]['modname']);
        $this->assertNotSame('', $sections[2]['activities'][0]['typename']);
        $this->assertNotSame('', $sections[2]['activities'][0]['iconurl']);
    }

    /**
     * An activity available but not shown on the course page is flagged as stealth.
     */
    public function test_stealth_activity_is_flagged(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 1]);
        $page = $generator->create_module('page', ['course' => $course->id, 'section' => 1]);
        $DB->set_field('course_modules', 'visibleoncoursepage', 0, ['id' => $page->cmid]);
        rebuild_course_cache((int) $course->id, true);

        $sections = course_structure::for_course((int) $course->id);

        $this->assertTrue($sections[1]['activities'][0]['stealth']);
        $this->assertTrue($sections[1]['activities'][0]['visible']);
    }

    /**
     * An activity that is being deleted is left out.
     */
    public function test_activity_being_deleted_is_left_out(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 1]);
        $gone = $generator->create_module('page', ['course' => $course->id, 'section' => 1]);
        $stays = $generator->create_module('page', ['course' => $course->id, 'section' => 1]);
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $gone->cmid]);
        rebuild_course_cache((int) $course->id, true);

        $sections = course_structure::for_course((int) $course->id);

        $column = array_column($sections[1]['activities'], 'cmid');
        $this->assertSame([(int) $stays->cmid], $column);
    }

    /**
     * The ids of every activity are collected across sections.
     */
    public function test_cmids_of_collects_every_section(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 2]);
        $one = $generator->create_module('page', ['course' => $course->id, 'section' => 1]);
        $two = $generator->create_module('page', ['course' => $course->id, 'section' => 2]);

        $sections = course_structure::for_course((int) $course->id);
        $cmids = course_structure::cmids_of($sections);

        $this->assertEqualsCanonicalizing([(int) $one->cmid, (int) $two->cmid], $cmids);
        $cmids2 = course_structure::cmids_of([]);
        $this->assertSame([], $cmids2);
    }

    /**
     * A course with more than five hundred activities is listed completely and in order.
     */
    public function test_course_with_five_hundred_activities(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 2]);
        $expected = [];
        for ($index = 0; $index < 505; $index++) {
            $label = $generator->create_module('label', ['course' => $course->id, 'intro' => 'Label ' . $index, 'section' => 1]);
            $expected[] = (int) $label->cmid;
        }

        $sections = course_structure::for_course((int) $course->id);

        $column = array_column($sections[1]['activities'], 'cmid');
        $this->assertSame($expected, $column);
        $cmids = course_structure::cmids_of($sections);
        $this->assertCount(505, $cmids);
    }
}
