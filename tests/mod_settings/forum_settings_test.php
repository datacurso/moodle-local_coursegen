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

namespace local_coursegen\mod_settings;

use local_coursegen\local\warning_collector;

/**
 * Unit tests for forum_settings: the initial discussions the AI result carries.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\mod_settings\forum_settings
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\mod_settings\forum_settings::class)]
final class forum_settings_test extends \advanced_testcase {
    /**
     * Leave no injected failure behind.
     */
    protected function tearDown(): void {
        warning_collector::clear_test_failures();
        parent::tearDown();
    }

    /**
     * Create a forum and return a cm-like object shaped as create_mod_service passes it.
     *
     * @param int $courseid Course id.
     * @param string $type Forum type.
     * @return object Object with ->coursemodule (cmid), ->instance (forum id) and ->course.
     */
    private function make_forum_cm(int $courseid, string $type = 'general'): object {
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $courseid, 'type' => $type]);

        return (object) ['coursemodule' => $forum->cmid, 'instance' => $forum->id, 'course' => $courseid];
    }

    /**
     * Two initial discussions, as the AI result declares them.
     *
     * @return array
     */
    private function discussions(): array {
        return ['discussions' => [
            ['subject' => 'Welcome', 'message' => '<p>Introduce yourself here.</p>'],
            ['subject' => 'Week 1 questions', 'message' => '<p>Ask anything about week 1.</p>'],
        ]];
    }

    /**
     * Discussion rows of a forum keyed by subject, with their first post.
     *
     * @param int $forumid Forum id.
     * @return array<string, array{discussion: \stdClass, post: \stdClass}>
     */
    private function discussions_by_subject(int $forumid): array {
        global $DB;

        $result = [];
        foreach ($DB->get_records('forum_discussions', ['forum' => $forumid], 'id ASC') as $discussion) {
            $post = $DB->get_record('forum_posts', ['id' => $discussion->firstpost], '*', MUST_EXIST);
            $result[$discussion->name] = ['discussion' => $discussion, 'post' => $post];
        }

        return $result;
    }

    /**
     * Assert that the two declared discussions exist with their subject and message, posted by $userid.
     *
     * @param int $forumid Forum id.
     * @param int $courseid Course id.
     * @param int $userid Expected author.
     * @return void
     */
    private function assert_discussions_created(int $forumid, int $courseid, int $userid): void {
        $discussions = $this->discussions_by_subject($forumid);
        $this->assertSame(['Welcome', 'Week 1 questions'], array_keys($discussions));

        foreach ($discussions as $subject => $row) {
            $this->assertEquals($courseid, $row['discussion']->course);
            $this->assertEquals($userid, $row['discussion']->userid);
            $this->assertEquals(-1, $row['discussion']->groupid, 'Initial discussions are visible to all participants.');
            $this->assertEquals($row['discussion']->id, $row['post']->discussion);
            $this->assertEquals($userid, $row['post']->userid);
            $this->assertSame($subject, $row['post']->subject);
            $this->assertEquals(FORMAT_HTML, $row['post']->messageformat);
        }
        $this->assertSame('<p>Introduce yourself here.</p>', $discussions['Welcome']['post']->message);
        $this->assertSame('<p>Ask anything about week 1.</p>', $discussions['Week 1 questions']['post']->message);
    }

    /**
     * An editing teacher of the course gets the discussions created in their name.
     */
    public function test_discussions_created_for_editing_teacher(): void {
        $this->resetAfterTest();

        [$course, $teacher] = $this->course_with_editing_teacher();
        $cm = $this->make_forum_cm((int)$course->id);

        $settings = new forum_settings($cm, $this->discussions());
        $settings->add_settings();

        $this->assertDebuggingNotCalled();
        $this->assertSame([], $settings->get_warnings());
        $this->assert_discussions_created((int)$cm->instance, (int)$course->id, (int)$teacher->id);
    }

    /**
     * The site administrator gets the discussions created as well.
     */
    public function test_discussions_created_for_admin(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_forum_cm((int)$course->id);

        (new forum_settings($cm, $this->discussions()))->add_settings();

        $this->assert_discussions_created((int)$cm->instance, (int)$course->id, (int)$USER->id);
    }

    /**
     * Create a course with an enrolled editing teacher and log them in.
     *
     * @return array{0: \stdClass, 1: \stdClass} The course and the teacher.
     */
    private function course_with_editing_teacher(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        return [$course, $teacher];
    }

    /**
     * Assert that the handler recorded exactly the given forum discussion warnings, in order.
     *
     * @param forum_settings $settings The handler.
     * @param string[] $subjects Expected warning subjects.
     * @return void
     */
    private function assert_discussion_warnings(forum_settings $settings, array $subjects): void {
        $warnings = $settings->get_warnings();
        $this->assertSame($subjects, array_column($warnings, 'subject'));
        foreach ($warnings as $warning) {
            $this->assertSame(warning_collector::STEP_FORUM_DISCUSSION, $warning['step']);
            $this->assertNotSame('', $warning['reason']);
        }
    }

    /**
     * A single-discussion forum accepts no further discussion: the entry is recorded as a warning
     * and the forum keeps its one discussion.
     */
    public function test_single_forum_records_warning_instead_of_discussion(): void {
        global $DB;

        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $cm = $this->make_forum_cm((int)$course->id, 'single');
        $this->assertSame(1, $DB->count_records('forum_discussions', ['forum' => $cm->instance]));

        $settings = new forum_settings($cm, ['discussions' => [
            ['subject' => 'Welcome', 'message' => '<p>Introduce yourself here.</p>'],
        ]]);
        $settings->add_settings();

        $this->assertDebuggingCalledCount(1);
        $this->assert_discussion_warnings($settings, ['Welcome']);
        $this->assertSame(get_string('cannotcreatediscussion', 'forum'), $settings->get_warnings()[0]['reason']);
        $this->assertSame(1, $DB->count_records('forum_discussions', ['forum' => $cm->instance]));
        $this->assertSame(0, $DB->count_records('forum_discussions', ['forum' => $cm->instance, 'name' => 'Welcome']));
    }

    /**
     * A user who manages activities but may not start discussions in the forum gets no discussion
     * created; each entry is recorded as a warning.
     */
    public function test_user_without_startdiscussion_records_warnings(): void {
        global $DB;

        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $coursecontext = \core\context\course::instance($course->id);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('mod/forum:startdiscussion', CAP_PROHIBIT, $roleid, $coursecontext->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(has_capability('moodle/course:manageactivities', $coursecontext));
        $cm = $this->make_forum_cm((int)$course->id);

        $settings = new forum_settings($cm, $this->discussions());
        $settings->add_settings();

        $this->assertDebuggingCalledCount(2);
        $this->assert_discussion_warnings($settings, ['Welcome', 'Week 1 questions']);
        $this->assertSame(0, $DB->count_records('forum_discussions', ['forum' => $cm->instance]));
    }

    /**
     * Entries without a subject or without a message are skipped with a warning; the valid ones
     * are still created.
     */
    public function test_malformed_entries_are_skipped_with_warning(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_forum_cm((int)$course->id);

        $settings = new forum_settings($cm, ['discussions' => [
            ['subject' => '   ', 'message' => '<p>No subject</p>'],
            ['subject' => 'Only subject'],
            'not an entry',
            ['subject' => 'Welcome', 'message' => '<p>Introduce yourself here.</p>'],
        ]]);
        $settings->add_settings();

        $this->assertDebuggingCalledCount(3);
        $this->assert_discussion_warnings($settings, ['', 'Only subject', '']);
        $discussions = $this->discussions_by_subject((int)$cm->instance);
        $this->assertSame(['Welcome'], array_keys($discussions));
        $this->assertSame('<p>Introduce yourself here.</p>', $discussions['Welcome']['post']->message);
    }

    /**
     * Without a discussions key nothing happens: no discussion, no warning, no debugging.
     */
    public function test_missing_discussions_key_is_a_noop(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_forum_cm((int)$course->id);

        foreach ([['other' => 1], ['discussions' => []], ['discussions' => 'none']] as $modsettings) {
            $settings = new forum_settings($cm, $modsettings);
            $settings->add_settings();
            $this->assertSame([], $settings->get_warnings());
        }

        $this->assertDebuggingNotCalled();
        $this->assertSame(0, $DB->count_records('forum_discussions', ['forum' => $cm->instance]));
    }

    /**
     * A forum library failure while adding a discussion becomes a warning: the forum module stays
     * and the next discussion is still attempted.
     */
    public function test_forum_failure_becomes_warning(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_forum_cm((int)$course->id);
        warning_collector::set_test_failure(
            warning_collector::STEP_FORUM_DISCUSSION,
            new \RuntimeException('forum_discussions insert failed')
        );

        $settings = new forum_settings($cm, $this->discussions());
        $settings->add_settings();

        $this->assertDebuggingCalledCount(2);
        $this->assert_discussion_warnings($settings, ['Welcome', 'Week 1 questions']);
        $this->assertSame('forum_discussions insert failed', $settings->get_warnings()[0]['reason']);
        $this->assertSame([], $this->discussions_by_subject((int)$cm->instance));
    }

    /**
     * Each created discussion is audited like one posted through the forum.
     */
    public function test_discussion_created_events_are_fired(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_forum_cm((int)$course->id);

        $sink = $this->redirectEvents();
        (new forum_settings($cm, $this->discussions()))->add_settings();
        $events = array_values(array_filter($sink->get_events(), static function (\core\event\base $event): bool {
            return $event instanceof \mod_forum\event\discussion_created;
        }));
        $sink->close();

        $this->assertCount(2, $events);
        $discussions = $this->discussions_by_subject((int)$cm->instance);
        $this->assertEquals($discussions['Welcome']['discussion']->id, $events[0]->objectid);
        $this->assertEquals($cm->instance, $events[0]->other['forumid']);
        $this->assertEquals(\core\context\module::instance($cm->coursemodule)->id, $events[0]->get_context()->id);
    }
}
