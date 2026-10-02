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
use local_coursegen\local\service\template_keep_copier;

/**
 * Unit tests for template_keep_copier::copy_into().
 *
 * The base course is entirely under the professor's control while a run is
 * pending or under review: they can edit, delete an activity from, or
 * delete outright the course a template points at. None of that may crash
 * copy_into() - the target course, already built with every AI-generated
 * activity by the time this runs, has to survive regardless.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_keep_copier
 *
 * @runTestsInSeparateProcesses
 */
final class template_keep_copier_test extends \advanced_testcase {
    /**
     * A kept activity is duplicated into the target course's matching
     * section, and no failure is reported for it.
     */
    public function test_copies_a_kept_activity_into_the_target_course(): void {
        $this->resetAfterTest(true);

        $sourcecourse = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $targetcourse = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->getDataGenerator()->create_module('page', ['course' => $sourcecourse->id, 'section' => 1]);
        $template = $this->create_template($sourcecourse->id);

        $failures = template_keep_copier::copy_into((int) $template->get('id'), $targetcourse->id);

        $targetmodinfo = get_fast_modinfo($targetcourse);
        $targetcms = $targetmodinfo->get_instances_of('page');
        $this->assertSame([], $failures);
        $this->assertCount(1, $targetcms);
    }

    /**
     * A templateid with no saved template record is a no-op, not a crash -
     * copy_into() cannot assume the caller already checked it exists.
     */
    public function test_missing_template_record_returns_empty(): void {
        $this->resetAfterTest(true);

        $targetcourse = $this->getDataGenerator()->create_course();

        $failures = template_keep_copier::copy_into(999999, $targetcourse->id);

        $this->assertSame([], $failures);
    }

    /**
     * The template's own base course can be deleted by the professor while
     * a run is pending. get_course() would throw for a missing course id;
     * copy_into() has to return instead, so the exception never reaches
     * finish_template_generation and loses the already-built target course.
     */
    public function test_deleted_source_course_returns_empty_without_crashing(): void {
        global $DB;
        $this->resetAfterTest(true);

        $sourcecourse = $this->getDataGenerator()->create_course();
        $targetcourse = $this->getDataGenerator()->create_course();
        $template = $this->create_template($sourcecourse->id);
        $DB->delete_records('course', ['id' => $sourcecourse->id]);

        $failures = template_keep_copier::copy_into((int) $template->get('id'), $targetcourse->id);

        $this->assertSame([], $failures);
    }

    /**
     * An activity deleted from the base course after the template's
     * structure was saved is simply not there to copy anymore: kept_cmids()
     * reads the base course as it is right now, so a gone activity is
     * skipped rather than attempted and failed - and every other kept
     * activity still copies.
     */
    public function test_deleted_kept_activity_is_skipped_without_failing_the_rest(): void {
        $this->resetAfterTest(true);

        $sourcecourse = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $targetcourse = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $tokeep = $this->getDataGenerator()->create_module('page', ['course' => $sourcecourse->id, 'section' => 1]);
        $todelete = $this->getDataGenerator()->create_module('page', ['course' => $sourcecourse->id, 'section' => 1]);
        $template = $this->create_template($sourcecourse->id);
        course_delete_module($todelete->cmid);

        $failures = template_keep_copier::copy_into((int) $template->get('id'), $targetcourse->id);

        $targetmodinfo = get_fast_modinfo($targetcourse);
        $targetcms = $targetmodinfo->get_instances_of('page');
        $this->assertSame([], $failures);
        $this->assertCount(1, $targetcms);
    }

    /**
     * An activity moved to a section number the target course does not
     * have is reported as a failure for that one activity, not a crash.
     */
    public function test_unresolvable_target_section_is_reported_as_a_failure(): void {
        $this->resetAfterTest(true);

        $sourcecourse = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $targetcourse = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $sourcecourse->id, 'section' => 3]);
        $template = $this->create_template($sourcecourse->id);

        $failures = template_keep_copier::copy_into((int) $template->get('id'), $targetcourse->id);

        $this->assertSame([$page->name], $failures);
    }

    /**
     * Every copied activity is reported under the cmid it has in the base
     * course, mapped to the cmid of its copy in the target course, so a
     * caller can tell where each kept activity ended up.
     */
    public function test_reports_the_created_cmid_of_every_kept_activity(): void {
        $this->resetAfterTest(true);

        $sourcecourse = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $targetcourse = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $first = $this->getDataGenerator()->create_module('page', ['course' => $sourcecourse->id, 'section' => 1]);
        $second = $this->getDataGenerator()->create_module('page', ['course' => $sourcecourse->id, 'section' => 2]);
        $template = $this->create_template($sourcecourse->id);

        $createdcmids = [];
        template_keep_copier::copy_into((int) $template->get('id'), $targetcourse->id, $createdcmids);

        $targetmodinfo = get_fast_modinfo($targetcourse);
        $targetcmids = array_keys($targetmodinfo->get_instances_of('page'));
        $sourcecmids = [(int) $first->cmid, (int) $second->cmid];
        $this->assertEqualsCanonicalizing($sourcecmids, array_keys($createdcmids));
        $this->assertEqualsCanonicalizing($targetcmids, array_values($createdcmids));
        $this->assertSame(
            $targetmodinfo->get_cm($createdcmids[(int) $first->cmid])->sectionnum,
            1
        );
    }

    /**
     * An activity that could not be copied has no entry in the map.
     */
    public function test_failed_copy_has_no_created_cmid(): void {
        $this->resetAfterTest(true);

        $sourcecourse = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $targetcourse = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $this->getDataGenerator()->create_module('page', ['course' => $sourcecourse->id, 'section' => 3]);
        $template = $this->create_template($sourcecourse->id);

        $createdcmids = [];
        template_keep_copier::copy_into((int) $template->get('id'), $targetcourse->id, $createdcmids);

        $this->assertSame([], $createdcmids);
    }

    /**
     * A template row pointing at the given course, with no saved
     * template_activity rows - every activity there defaults to "keep".
     *
     * @param int $courseid
     * @return template
     */
    private function create_template(int $courseid): template {
        $template = new template(0, (object) [
            'name' => 'Test template',
            'courseid' => $courseid,
        ]);
        $template->create();
        return $template;
    }
}
