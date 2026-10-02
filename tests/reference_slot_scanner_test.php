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
use local_coursegen\local\models\template_activity;
use local_coursegen\local\reference\reference_file_policy;
use local_coursegen\local\reference\reference_slot_scanner;

/**
 * The slots a template offers the teacher, found over the same texts the export sends.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\reference\reference_slot_scanner
 * @covers     \local_coursegen\local\reference\reference_slot
 */
final class reference_slot_scanner_test extends \advanced_testcase {
    /**
     * A page of the template with a marker over a document is one slot named after its activity.
     */
    public function test_a_marked_mold_gives_one_slot(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $content = '<h3>Guide</h3>[[coursegen:reference: Didactic guide]]<iframe src="@@PLUGINFILE@@/guide.pdf"></iframe>';
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Unit one',
            'content' => $content,
        ]);
        $template = $this->create_template($course->id);
        $this->mark($template->get('id'), (int) $page->cmid, template_activity::ACTION_TEMPLATE);

        $slots = reference_slot_scanner::for_template($template->get('id'));

        $this->assertCount(1, $slots);
        $this->assertSame($page->cmid . '.1', $slots[0]->key());
        $this->assertSame((int) $page->cmid, $slots[0]->cmid);
        $this->assertSame('Unit one', $slots[0]->activityname);
        $this->assertSame('Didactic guide', $slots[0]->instruction);
        $this->assertSame(reference_file_policy::KIND_DOCUMENT, $slots[0]->kind);
    }

    /**
     * The markers of an activity are numbered over its texts in export order, intro before content.
     */
    public function test_markers_are_numbered_over_the_texts_in_export_order(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'intro' => '[[coursegen:reference: cover]]<img src="@@PLUGINFILE@@/cover.png">',
            'content' => '[[coursegen:reference: first]]<img src="@@PLUGINFILE@@/a.png">'
                . '[[coursegen:reference: second]]<video src="@@PLUGINFILE@@/b.mp4"></video>',
        ]);
        $template = $this->create_template($course->id);
        $this->mark($template->get('id'), (int) $page->cmid, template_activity::ACTION_TEMPLATE);

        $slots = reference_slot_scanner::for_template($template->get('id'));

        $this->assertCount(3, $slots);
        $this->assertSame($page->cmid . '.1', $slots[0]->key());
        $this->assertSame($page->cmid . '.2', $slots[1]->key());
        $this->assertSame($page->cmid . '.3', $slots[2]->key());
        $this->assertSame('cover', $slots[0]->instruction);
        $this->assertSame('first', $slots[1]->instruction);
        $this->assertSame('second', $slots[2]->instruction);
        $this->assertSame(reference_file_policy::KIND_VIDEO, $slots[2]->kind);
    }

    /**
     * An activity kept as it is, left out, used only as a reference or not saved at all is never searched.
     */
    public function test_kept_excluded_and_unsaved_activities_are_not_searched(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $content = '[[coursegen:reference: x]]<img src="@@PLUGINFILE@@/a.png">';
        $kept = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => $content]);
        $excluded = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => $content]);
        $referenced = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => $content]);
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => $content]);
        $template = $this->create_template($course->id);
        $this->mark($template->get('id'), (int) $kept->cmid, template_activity::ACTION_KEEP);
        $this->mark($template->get('id'), (int) $excluded->cmid, template_activity::ACTION_EXCLUDE);
        $this->mark($template->get('id'), (int) $referenced->cmid, template_activity::ACTION_REFERENCE);

        $slots = reference_slot_scanner::for_template($template->get('id'));

        $this->assertSame([], $slots);
    }

    /**
     * Each activity numbers its own markers from one.
     */
    public function test_each_activity_starts_counting_at_one(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $content = '[[coursegen:reference: x]]<img src="@@PLUGINFILE@@/a.png">';
        $first = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => $content]);
        $second = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => $content]);
        $template = $this->create_template($course->id);
        $this->mark($template->get('id'), (int) $first->cmid, template_activity::ACTION_TEMPLATE);
        $this->mark($template->get('id'), (int) $second->cmid, template_activity::ACTION_TEMPLATE);

        $slots = reference_slot_scanner::for_template($template->get('id'));

        $this->assertCount(2, $slots);
        $this->assertSame($first->cmid . '.1', $slots[0]->key());
        $this->assertSame($second->cmid . '.1', $slots[1]->key());
    }

    /**
     * A source without any marker offers nothing.
     */
    public function test_a_source_without_markers_offers_nothing(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => '<p>Plain</p>']);
        $template = $this->create_template($course->id);
        $this->mark($template->get('id'), (int) $page->cmid, template_activity::ACTION_TEMPLATE);

        $slots = reference_slot_scanner::for_template($template->get('id'));

        $this->assertSame([], $slots);
    }

    /**
     * A marker with no element below it is reported, not skipped.
     */
    public function test_a_marker_without_a_target_is_reported(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => '<p>[[coursegen:reference: nothing below]]</p>',
        ]);
        $template = $this->create_template($course->id);
        $this->mark($template->get('id'), (int) $page->cmid, template_activity::ACTION_TEMPLATE);

        $this->expectException(\moodle_exception::class);

        reference_slot_scanner::for_template($template->get('id'));
    }

    /**
     * A template that does not exist is reported, not read as empty.
     */
    public function test_an_unknown_template_is_reported(): void {
        $this->resetAfterTest(true);

        $this->expectException(\moodle_exception::class);

        reference_slot_scanner::for_template(987654);
    }

    /**
     * Save a template over a course.
     *
     * @param int $courseid
     * @return template
     */
    private function create_template(int $courseid): template {
        $template = new template(0, (object) ['name' => 'Test template', 'courseid' => $courseid]);
        $template->create();
        return $template;
    }

    /**
     * Save the action of one activity of the template.
     *
     * @param int $templateid
     * @param int $cmid
     * @param string $action
     */
    private function mark(int $templateid, int $cmid, string $action): void {
        $activity = new template_activity(0, (object) [
            'templateid' => $templateid,
            'sectionid' => 0,
            'cmid' => $cmid,
            'action' => $action,
        ]);
        $activity->create();
    }
}
