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

use local_coursegen\local\template\template_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/template_test_helper.php');

/**
 * Tests for what the editor loads: the course structure with what is saved for each activity.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\template\template_service::load_for_edit
 */
final class template_service_load_test extends \advanced_testcase {
    use template_test_helper;

    /** @var template_service Service under test. */
    private template_service $service;

    /**
     * Create the service.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->service = new template_service();
    }

    /**
     * Collect the activities of every section by course module id.
     *
     * @param array[] $sections Sections loaded by the service.
     * @return array[] Activities keyed by course module id.
     */
    private function activities_by_cmid(array $sections): array {
        $bycmid = [];
        foreach ($sections as $section) {
            $bycmid = $bycmid + $this->index_by_cmid($section['activities']);
        }

        return $bycmid;
    }

    /**
     * Index the activities of one section by course module id.
     *
     * @param array[] $activities Activities of a section.
     * @return array[]
     */
    private function index_by_cmid(array $activities): array {
        $bycmid = [];
        foreach ($activities as $activity) {
            $bycmid[$activity['cmid']] = $activity;
        }

        return $bycmid;
    }

    /**
     * A new template over a course shows every activity kept and without an instruction.
     */
    public function test_new_template_shows_every_activity_kept(): void {
        [$course, $page, $quiz, $label] = $this->make_course();

        $loaded = $this->service->load_for_edit(0, (int) $course->id);

        $this->assertTrue($loaded['courseusable']);
        $this->assertNull($loaded['template']);
        $activities = $this->activities_by_cmid($loaded['sections']);
        $keys = array_keys($activities);
        $this->assertEqualsCanonicalizing([$page, $quiz, $label], $keys);
        foreach ($activities as $activity) {
            $this->assertSame('keep', $activity['action']);
            $this->assertSame('', $activity['instruction']);
        }
    }

    /**
     * Without a course, nothing is shown and the course is not usable.
     */
    public function test_no_course_is_not_usable(): void {
        foreach ([0, SITEID, 99999] as $courseid) {
            $loaded = $this->service->load_for_edit(0, $courseid);

            $this->assertFalse($loaded['courseusable']);
            $this->assertSame([], $loaded['sections']);
        }
    }

    /**
     * Loading a saved template restores each action and instruction exactly.
     */
    public function test_saved_template_is_restored_exactly(): void {
        [$course, $page, $quiz, $label] = $this->make_course();
        $id = $this->save_items($course, [
            ['cmid' => $page, 'action' => 'ai', 'instruction' => "Line one\nLine two <b>x</b>"],
            ['cmid' => $quiz, 'action' => 'keep'],
            ['cmid' => $label, 'action' => 'ai'],
        ]);

        $loaded = $this->service->load_for_edit($id, 0);

        $this->assertSame((int) $course->id, $loaded['courseid']);
        $this->assertSame('Marketing', $loaded['template']->name);
        $activities = $this->activities_by_cmid($loaded['sections']);
        $this->assertSame('ai', $activities[$page]['action']);
        $this->assertSame("Line one\nLine two <b>x</b>", $activities[$page]['instruction']);
        $this->assertSame('keep', $activities[$quiz]['action']);
        $this->assertSame('ai', $activities[$label]['action']);
        $this->assertSame('', $activities[$label]['instruction']);
    }

    /**
     * An activity added to the course after the save is shown as kept.
     */
    public function test_activity_added_later_is_kept(): void {
        [$course, $page] = $this->make_course();
        $id = $this->save_items($course, [['cmid' => $page, 'action' => 'ai', 'instruction' => 'A']]);
        $added = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Added later']);

        $loaded = $this->service->load_for_edit($id, 0);

        $activities = $this->activities_by_cmid($loaded['sections']);
        $this->assertSame('keep', $activities[(int) $added->cmid]['action']);
        $this->assertSame('ai', $activities[$page]['action']);
    }

    /**
     * An activity deleted after the save does not appear among the sections, and the others keep their choice.
     */
    public function test_deleted_activity_does_not_appear(): void {
        [$course, $page, $quiz] = $this->make_course();
        $id = $this->save_items($course, [
            ['cmid' => $page, 'action' => 'ai', 'instruction' => 'gone soon'],
            ['cmid' => $quiz, 'action' => 'keep'],
        ]);
        course_delete_module($page);

        $loaded = $this->service->load_for_edit($id, 0);

        $activities = $this->activities_by_cmid($loaded['sections']);
        $this->assertArrayNotHasKey($page, $activities);
        $this->assertSame('keep', $activities[$quiz]['action']);
    }

    /**
     * Choosing another course in the editor does not apply what was saved for the first one.
     */
    public function test_another_course_does_not_apply_saved_items(): void {
        [$course, $page] = $this->make_course();
        [$other, $otherpage] = $this->make_course();
        $id = $this->save_items($course, [['cmid' => $page, 'action' => 'ai', 'instruction' => 'A']]);

        $loaded = $this->service->load_for_edit($id, (int) $other->id);

        $this->assertSame((int) $other->id, $loaded['courseid']);
        $activities = $this->activities_by_cmid($loaded['sections']);
        $this->assertSame('keep', $activities[$otherpage]['action']);
    }

    /**
     * A template whose course was deleted still loads, with an unusable course.
     */
    public function test_template_of_a_deleted_course_loads_empty(): void {
        [$course, $page] = $this->make_course();
        $id = $this->save_items($course, [['cmid' => $page, 'action' => 'ai']]);
        delete_course($course, false);

        $loaded = $this->service->load_for_edit($id, 0);

        $this->assertFalse($loaded['courseusable']);
        $this->assertSame([], $loaded['sections']);
        $this->assertSame('Marketing', $loaded['template']->name);
    }

    /**
     * Loading a template that does not exist is rejected.
     */
    public function test_unknown_template_is_rejected(): void {
        $this->expectException(\moodle_exception::class);
        $message = get_string('error_template_not_found', 'local_coursegen');
        $this->expectExceptionMessage($message);

        $this->service->load_for_edit(99999, 0);
    }
}
