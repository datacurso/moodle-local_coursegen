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

namespace local_coursegen\local\space;

use local_coursegen\local\models\template;
use local_coursegen\local\models\template_activity;

/**
 * Tests for file_spaces.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\space\file_spaces
 */
final class file_spaces_test extends \advanced_testcase {
    /**
     * A template over a course, with its section id.
     *
     * @return array{0: template, 1: \stdClass}
     */
    private function template_of_course(): array {
        $course = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $template = new template(0, (object) ['name' => 'T', 'courseid' => $course->id]);
        $template->create();
        return [$template, $course];
    }

    /**
     * Save an action for an activity of the template.
     *
     * @param template $template
     * @param \stdClass $cm
     * @param string $action
     * @param bool $required
     * @param string $instruction
     */
    private function save_action(template $template, $cm, string $action, bool $required = false, string $instruction = ''): void {
        $record = new template_activity(0, (object) [
            'templateid' => $template->get('id'),
            'sectionid' => 0,
            'cmid' => $cm->cmid,
            'action' => $action,
            'spacerequired' => (int) $required,
            'spaceinstruction' => $instruction,
        ]);
        $record->create();
    }

    /**
     * A resource with one file.
     *
     * @param \stdClass $course
     * @param string $name
     * @return \stdClass
     */
    private function resource($course, string $name) {
        $draft = file_get_unused_draft_itemid();
        $usercontext = \context_user::instance(get_admin()->id);
        get_file_storage()->create_file_from_string([
            'contextid' => $usercontext->id, 'component' => 'user', 'filearea' => 'draft', 'itemid' => $draft,
            'filepath' => '/', 'filename' => $name . '.pdf',
        ], 'PDF-' . $name);
        return $this->getDataGenerator()->create_module('resource', [
            'course' => $course->id, 'name' => $name, 'files' => $draft,
        ]);
    }

    /**
     * Store a teacher's file for a session and a space.
     *
     * @param int $userid
     * @param int $sessionid
     * @param int $cmid
     */
    private function store_for(int $userid, int $sessionid, int $cmid): void {
        $draftid = file_get_unused_draft_itemid();
        $context = \context_user::instance($userid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'user', 'filearea' => 'draft', 'itemid' => $draftid,
            'filepath' => '/', 'filename' => 'mine.pdf',
        ], 'MINE');
        space_file_storage::store_draft($userid, $sessionid, $cmid, $draftid);
    }

    /**
     * Only resources saved as space are listed, with the instruction, the requirement and their files.
     */
    public function test_lists_the_resources_saved_as_space(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$template, $course] = $this->template_of_course();
        $guide = $this->resource($course, 'Guide');
        $other = $this->resource($course, 'Other');
        $this->save_action($template, $guide, 'space', true, 'Guide of the subject');
        $this->save_action($template, $other, 'keep');

        $spaces = file_spaces::of_template((int) $template->get('id'));

        $this->assertCount(1, $spaces);
        $this->assertSame((int) $guide->cmid, $spaces[0]->cmid);
        $this->assertSame('Guide', $spaces[0]->name);
        $this->assertSame('Guide of the subject', $spaces[0]->instruction);
        $this->assertTrue($spaces[0]->required);
        $this->assertCount(1, $spaces[0]->templatefiles);
        $this->assertSame('Guide.pdf', $spaces[0]->templatefiles[0]->get_filename());
    }

    /**
     * A space saved on a type that cannot be one is not listed.
     */
    public function test_ignores_a_space_on_another_type(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$template, $course] = $this->template_of_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $this->save_action($template, $forum, 'space');

        $spaces = file_spaces::of_template((int) $template->get('id'));

        $this->assertSame([], $spaces);
    }

    /**
     * The spaces come in the order of the course.
     */
    public function test_lists_in_course_order(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$template, $course] = $this->template_of_course();
        $first = $this->resource($course, 'First');
        $second = $this->resource($course, 'Second');
        $this->save_action($template, $second, 'space');
        $this->save_action($template, $first, 'space');

        $spaces = file_spaces::of_template((int) $template->get('id'));

        $this->assertSame(['First', 'Second'], array_map(static fn($space) => $space->name, $spaces));
    }

    /**
     * A template that does not exist, or whose course is gone, has no spaces.
     */
    public function test_no_template_no_spaces(): void {
        $this->resetAfterTest();

        $this->assertSame([], file_spaces::of_template(987654));
    }

    /**
     * Spaces of the template that the teacher filled are selected by their cmid.
     */
    public function test_selection_of_session_reads_the_stored_files(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$template, $course] = $this->template_of_course();
        $guide = $this->resource($course, 'Guide');
        $this->save_action($template, $guide, 'space', true);
        $userid = (int) get_admin()->id;
        $this->store_for($userid, 55, (int) $guide->cmid);

        $selection = file_spaces::selection_of_session((int) $template->get('id'), $userid, 55);

        $this->assertSame([(int) $guide->cmid], $selection->filled_cmids());
        $this->assertSame([], $selection->missing_required());
        $this->assertSame('MINE', $selection->file_of($selection->filled()[0])->get_content());
    }

    /**
     * A file stored for a cmid that is no longer a space of the template is ignored.
     */
    public function test_selection_ignores_files_of_cmids_that_are_not_spaces(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$template, $course] = $this->template_of_course();
        $userid = (int) get_admin()->id;
        $this->store_for($userid, 56, 4242);

        $selection = file_spaces::selection_of_session((int) $template->get('id'), $userid, 56);

        $this->assertSame([], $selection->filled_cmids());
    }
}
