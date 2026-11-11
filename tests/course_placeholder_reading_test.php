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

use local_coursegen\local\placeholder\course_template_creator;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/placeholder_course_helper.php');

/**
 * Finding the placeholders in the activities of a course and in the rows of their child tables.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\placeholder\course_structure_reader
 * @covers     \local_coursegen\local\placeholder\activity_scanner
 */
final class course_placeholder_reading_test extends \advanced_testcase {
    use placeholder_course_helper;

    /**
     * Every test starts from a clean database and acts as the administrator, as the command does.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * A hidden activity counts.
     */
    public function test_a_hidden_activity_counts(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $marker = self::marker_text();
        $hidden = $generator->create_module('page', ['course' => $course->id, 'content' => $marker, 'visible' => 0]);

        $plan = course_template_creator::plan($course->id);

        $this->assertSame([(int) $hidden->cmid], $plan['molds']);
    }

    /**
     * An activity being deleted is skipped.
     */
    public function test_an_activity_being_deleted_is_skipped(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $marker = self::marker_text();
        $kept = $generator->create_module('page', ['course' => $course->id, 'content' => $marker]);
        $going = $generator->create_module('page', ['course' => $course->id, 'content' => $marker]);
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $going->cmid]);
        rebuild_course_cache($course->id, true);

        $plan = course_template_creator::plan($course->id);

        $this->assertSame([(int) $kept->cmid], $plan['molds']);
    }

    /**
     * A placeholder only in the title of a lesson page, among thirty pages, makes the lesson a mold.
     */
    public function test_a_placeholder_only_in_a_lesson_page_title_makes_the_lesson_a_mold(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $lesson = $generator->create_module('lesson', ['course' => $course->id, 'name' => 'Long lesson']);
        $lessons = $generator->get_plugin_generator('mod_lesson');
        for ($number = 1; $number <= 30; $number++) {
            $lessons->create_content($lesson, ['title' => 'Page ' . $number, 'contents' => '<p>Plain</p>']);
        }
        $lessons->create_content($lesson, ['title' => '[[coursegen:aiprompt: page title]]', 'contents' => '<p>Plain</p>']);

        $plan = course_template_creator::plan($course->id);

        $this->assertSame([(int) $lesson->cmid], $plan['molds']);
    }

    /**
     * A type that cannot be a mold keeps its place and is reported.
     */
    public function test_a_type_that_cannot_be_a_mold_is_kept_and_reported(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $marker = self::marker_text();
        $generator->create_module('page', ['course' => $course->id, 'content' => $marker]);
        $chat = $generator->create_module('chat', ['course' => $course->id, 'intro' => $marker]);

        $plan = course_template_creator::plan($course->id);

        $this->assertSame((int) $chat->cmid, $plan['unsupported'][0]['cmid']);
        $this->assertNotEmpty($plan['warnings']);
    }

    /**
     * A malformed marker is reported and does not make the activity a mold.
     */
    public function test_a_malformed_marker_is_reported_and_does_not_count(): void {
        $marker = self::marker_text();
        $pages = ['Broken' => '<p>[[coursegen:aiprompt: never closed</p>', 'Good' => $marker];
        $course = $this->course_with_pages($pages);
        $ids = $this->cmids_by_name($course);

        $plan = course_template_creator::plan($course->id);

        $this->assertSame([$ids['Good']], $plan['molds']);
        $this->assertNotEmpty($plan['problems']);
    }

    /**
     * A course with two hundred activities is read and planned.
     */
    public function test_a_course_with_two_hundred_activities(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $marker = self::marker_text();
        for ($index = 1; $index <= 200; $index++) {
            $content = '<p>Fixed</p>';
            if ($index % 4 === 0) {
                $content = $marker;
            }
            $generator->create_module('page', ['course' => $course->id, 'name' => 'Page ' . $index, 'content' => $content]);
        }

        $plan = course_template_creator::plan($course->id);

        $this->assertSame(50, $plan['instances']);
        $this->assertSame(150, $plan['kept']);
    }
}
