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

use local_coursegen\local\service\kept_link_rewriter;

/**
 * Pointing the links of the copied kept activities at the new course.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\kept_link_rewriter
 *
 * @runTestsInSeparateProcesses
 */
final class kept_link_rewriter_test extends \advanced_testcase {
    /**
     * The address of a module of the template's course.
     *
     * @param string $modname
     * @param int $cmid
     * @return string
     */
    private function url(string $modname, int $cmid): string {
        global $CFG;
        return $CFG->wwwroot . '/mod/' . $modname . '/view.php?id=' . $cmid;
    }

    /**
     * An instance entry of the result.
     *
     * @param int $payloadcmid
     * @param int $sourcecmid
     * @return array
     */
    private function instance(int $payloadcmid, int $sourcecmid): array {
        return [
            'uid' => 'uid' . $payloadcmid,
            'cmid' => $payloadcmid,
            'template_behavior' => ['action' => 'instance', 'template_source_cmid' => $sourcecmid],
        ];
    }

    /**
     * A link of a kept label to another kept activity points at its copy.
     */
    public function test_a_link_to_a_kept_activity_points_at_its_copy(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $copy = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $label = $this->getDataGenerator()->create_module('label', [
            'course' => $course->id,
            'intro' => '<a href="' . $this->url('resource', 9326) . '">Support</a>',
        ]);

        kept_link_rewriter::rewrite_for_course($course->id, [], [], [9326 => (int) $copy->cmid, 9321 => (int) $label->cmid]);

        $intro = $DB->get_field('label', 'intro', ['id' => $label->id]);
        $this->assertSame('<a href="' . $this->url('resource', (int) $copy->cmid) . '">Support</a>', $intro);
    }

    /**
     * A link to a template source that produced one activity points at that activity.
     */
    public function test_a_link_to_a_single_instance_source_points_at_the_instance(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $generated = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $label = $this->getDataGenerator()->create_module('label', [
            'course' => $course->id,
            'intro' => '<a href="' . $this->url('page', 9322) . '">About</a>',
        ]);

        kept_link_rewriter::rewrite_for_course(
            $course->id,
            [$this->instance(-5, 9322)],
            [-5 => (int) $generated->cmid],
            [9321 => (int) $label->cmid]
        );

        $intro = $DB->get_field('label', 'intro', ['id' => $label->id]);
        $this->assertSame('<a href="' . $this->url('page', (int) $generated->cmid) . '">About</a>', $intro);
    }

    /**
     * A source with several instances is ambiguous, so the link is left as it is.
     */
    public function test_a_link_to_a_source_with_several_instances_is_left_untouched(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $first = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $second = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $intro = '<a href="' . $this->url('forum', 9331) . '">Forum</a>';
        $label = $this->getDataGenerator()->create_module('label', ['course' => $course->id, 'intro' => $intro]);

        kept_link_rewriter::rewrite_for_course(
            $course->id,
            [$this->instance(-1, 9331), $this->instance(-2, 9331)],
            [-1 => (int) $first->cmid, -2 => (int) $second->cmid],
            [9321 => (int) $label->cmid]
        );

        $this->assertSame($intro, $DB->get_field('label', 'intro', ['id' => $label->id]));
    }

    /**
     * A source whose only instance was not created is not mapped.
     */
    public function test_a_link_to_a_source_whose_instance_was_not_created_is_left_untouched(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $intro = '<a href="' . $this->url('page', 9322) . '">About</a>';
        $label = $this->getDataGenerator()->create_module('label', ['course' => $course->id, 'intro' => $intro]);

        kept_link_rewriter::rewrite_for_course(
            $course->id,
            [$this->instance(-5, 9322)],
            [],
            [9321 => (int) $label->cmid]
        );

        $this->assertSame($intro, $DB->get_field('label', 'intro', ['id' => $label->id]));
    }

    /**
     * A generated activity is not a kept one: its texts are not read here.
     */
    public function test_texts_of_generated_activities_are_not_rewritten(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $copy = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $intro = '<a href="' . $this->url('resource', 9326) . '">Support</a>';
        $generated = $this->getDataGenerator()->create_module('label', ['course' => $course->id, 'intro' => $intro]);

        kept_link_rewriter::rewrite_for_course($course->id, [], [-1 => (int) $generated->cmid], [9326 => (int) $copy->cmid]);

        $this->assertSame($intro, $DB->get_field('label', 'intro', ['id' => $generated->id]));
    }

    /**
     * A kept page is rewritten in its content as well as in its intro.
     */
    public function test_kept_pages_are_rewritten_in_content_and_intro(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $copy = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $text = '<a href="' . $this->url('resource', 9326) . '">Support</a>';
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => $text, 'intro' => $text]);

        kept_link_rewriter::rewrite_for_course($course->id, [], [], [9326 => (int) $copy->cmid, 9330 => (int) $page->cmid]);

        $expected = '<a href="' . $this->url('resource', (int) $copy->cmid) . '">Support</a>';
        $record = $DB->get_record('page', ['id' => $page->id], '*', MUST_EXIST);
        $this->assertSame($expected, $record->content);
        $this->assertSame($expected, $record->intro);
    }

    /**
     * With nothing kept there is nothing to rewrite, and nothing fails.
     */
    public function test_nothing_kept_is_a_no_op(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        kept_link_rewriter::rewrite_for_course($course->id, [], [], []);

        $this->assertTrue(true);
    }
}
