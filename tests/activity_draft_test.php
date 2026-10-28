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

use local_coursegen\external\activity_draft;
use local_coursegen\local\draft_files;
use local_coursegen\local\models\activity_draft as draft_model;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/capability_user_trait.php');

/**
 * The drafts of the AI activity editor belong to one user, are written only when they change, and are deleted with their owner.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\activity_draft
 * @covers     \local_coursegen\local\models\activity_draft
 * @covers     \local_coursegen\local\draft_observer
 * @covers     \local_coursegen\task\clean_activity_drafts
 */
final class activity_draft_test extends \advanced_testcase {
    use capability_user_trait;

    /** The kind of activity of the drafts of these tests. */
    private const KIND = 'course_presentation';

    /**
     * A user that can create activities with AI.
     *
     * @return \stdClass
     */
    private function teacher(): \stdClass {
        return $this->login_user_with(['moodle/course:manageactivities', 'local/coursegen:createactivitywithai']);
    }

    /**
     * A draft the editor could have saved.
     *
     * @param string $topic
     * @return string
     */
    private function data(string $topic = 'Water'): string {
        return json_encode(['v' => 1, 'topic' => $topic, 'model' => ['slides' => []]]);
    }

    /**
     * The user reads back what was saved.
     */
    public function test_saves_and_resumes(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->teacher();

        $this->assertFalse(activity_draft::resume($course->id, self::KIND)['exists']);
        $this->assertTrue(activity_draft::update($course->id, self::KIND, $this->data())['saved']);

        $resumed = activity_draft::resume($course->id, self::KIND);
        $this->assertTrue($resumed['exists']);
        $this->assertSame($this->data(), $resumed['data']);
    }

    /**
     * Another user does not read, change or delete the draft of a user, in the same course.
     */
    public function test_a_draft_belongs_to_its_user(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $owner = $this->teacher();
        activity_draft::update($course->id, self::KIND, $this->data('Owner'));

        $other = $this->teacher();
        $this->assertNotEquals($owner->id, $other->id);
        $this->assertFalse(activity_draft::resume($course->id, self::KIND)['exists']);

        activity_draft::update($course->id, self::KIND, $this->data('Other'));
        activity_draft::reset($course->id, self::KIND);

        $this->setUser($owner);
        $resumed = activity_draft::resume($course->id, self::KIND);
        $this->assertTrue($resumed['exists']);
        $this->assertSame($this->data('Owner'), $resumed['data']);
        $this->assertSame(1, $DB->count_records('local_coursegen_activity_draft', ['userid' => $owner->id]));
        $this->assertSame(0, $DB->count_records('local_coursegen_activity_draft', ['userid' => $other->id]));
    }

    /**
     * Whoever cannot create activities with AI in the course cannot touch drafts.
     */
    public function test_needs_the_capability(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->login_user_with(['moodle/course:manageactivities']);

        $this->expectException(\required_capability_exception::class);
        activity_draft::update($course->id, self::KIND, $this->data());
    }

    /**
     * The same data is not written again.
     */
    public function test_does_not_write_what_has_not_changed(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->teacher();
        activity_draft::update($course->id, self::KIND, $this->data());
        $DB->set_field('local_coursegen_activity_draft', 'timemodified', 1000);

        $this->assertFalse(activity_draft::update($course->id, self::KIND, $this->data())['saved']);
        $this->assertSame(1000, (int) $DB->get_field('local_coursegen_activity_draft', 'timemodified', []));
        $this->assertTrue(activity_draft::update($course->id, self::KIND, $this->data('Changed'))['saved']);
        $this->assertSame(1, $DB->count_records('local_coursegen_activity_draft'));
    }

    /**
     * What is too big or is not JSON is not saved, and nothing breaks.
     */
    public function test_does_not_save_what_is_not_valid(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->teacher();

        $toobig = json_encode(['topic' => str_repeat('a', draft_model::MAX_BYTES)]);
        $this->assertFalse(activity_draft::update($course->id, self::KIND, $toobig)['saved']);
        $this->assertFalse(activity_draft::update($course->id, self::KIND, 'not json')['saved']);
        $this->assertSame(0, $DB->count_records('local_coursegen_activity_draft'));
    }

    /**
     * A draft that is too old, or of another version of the editor, is not offered.
     */
    public function test_an_old_draft_is_not_offered(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->teacher();

        activity_draft::update($course->id, self::KIND, $this->data());
        $DB->set_field('local_coursegen_activity_draft', 'schemaver', 99);
        $this->assertFalse(activity_draft::resume($course->id, self::KIND)['exists']);

        $DB->set_field('local_coursegen_activity_draft', 'schemaver', draft_model::SCHEMA_VERSION);
        $DB->set_field('local_coursegen_activity_draft', 'timemodified', time() - draft_model::LIFETIME - 10);
        $this->assertFalse(activity_draft::resume($course->id, self::KIND)['exists']);
        $this->assertSame(0, $DB->count_records('local_coursegen_activity_draft'));
    }

    /**
     * The draft goes with its user and with its course.
     */
    public function test_deleted_with_the_user_and_the_course(): void {
        global $DB;
        $this->resetAfterTest();
        $first = $this->getDataGenerator()->create_course();
        $second = $this->getDataGenerator()->create_course();
        $user = $this->teacher();
        activity_draft::update($first->id, self::KIND, $this->data());
        activity_draft::update($second->id, self::KIND, $this->data());

        delete_course($first, false);
        $this->assertSame(1, $DB->count_records('local_coursegen_activity_draft'));

        $this->setAdminUser();
        delete_user($user);
        $this->assertSame(0, $DB->count_records('local_coursegen_activity_draft'));
    }

    /**
     * The task deletes the drafts that nobody changed for too long, and only those.
     */
    public function test_the_task_deletes_old_drafts(): void {
        global $DB;
        $this->resetAfterTest();
        $first = $this->getDataGenerator()->create_course();
        $second = $this->getDataGenerator()->create_course();
        $this->teacher();
        activity_draft::update($first->id, self::KIND, $this->data());
        activity_draft::update($second->id, self::KIND, $this->data());
        $DB->set_field('local_coursegen_activity_draft', 'timemodified', time() - draft_model::LIFETIME - 10, [
            'courseid' => $first->id,
        ]);

        (new \local_coursegen\task\clean_activity_drafts())->execute();

        $left = $DB->get_fieldset_select('local_coursegen_activity_draft', 'courseid', '1 = 1');
        $this->assertSame([(int) $second->id], array_map('intval', $left));
    }

    /** A picture of one pixel. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAHr9ZJQAAAABJRU5ErkJggg==';

    /**
     * Put a file in the draft area of the current user, as the file picker does.
     *
     * @param string $filename
     * @param string $content
     * @return int The draft item.
     */
    private function in_draft_area(string $filename, string $content): int {
        global $USER;
        $itemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
        return $itemid;
    }

    /**
     * A picture is kept for the user, readable only by the user, and nothing else is needed to send it later.
     */
    public function test_keeps_a_file_for_its_user(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $owner = $this->teacher();
        $key = 'abcdef123456.png';

        $item = $this->in_draft_area('mine.png', base64_decode(self::PNG));
        $this->assertTrue(activity_draft::file_save($course->id, self::KIND, $key, $item, 'image')['saved']);
        $this->assertNotNull(draft_files::get($owner->id, $course->id, self::KIND, $key));

        $other = $this->teacher();
        $this->assertNull(draft_files::get($other->id, $course->id, self::KIND, $key));
    }

    /**
     * What is not a picture, or is too big, is not kept as a picture.
     */
    public function test_refuses_what_is_not_a_picture(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->teacher();

        $item = $this->in_draft_area('fake.png', 'this is not an image');
        $this->expectException(\moodle_exception::class);
        activity_draft::file_save($course->id, self::KIND, 'abcdef123456.png', $item, 'image');
    }

    /**
     * A key that is not a plain name is refused, so no path can be built from it.
     */
    public function test_refuses_a_key_that_is_not_plain(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->teacher();

        $item = $this->in_draft_area('mine.png', base64_decode(self::PNG));
        $this->expectException(\moodle_exception::class);
        activity_draft::file_save($course->id, self::KIND, '../../x.png', $item, 'image');
    }

    /**
     * Saving the draft deletes the old files it no longer refers to; resetting deletes all, unless they are asked to stay.
     */
    public function test_files_follow_the_draft(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->teacher();
        $used = 'aaaaaaaa1111.png';
        $unused = 'bbbbbbbb2222.png';
        foreach ([$used, $unused] as $key) {
            $item = $this->in_draft_area('x.png', base64_decode(self::PNG));
            activity_draft::file_save($course->id, self::KIND, $key, $item, 'image');
        }
        $DB->set_field('files', 'timecreated', time() - 3600, ['component' => 'local_coursegen']);

        $data = json_encode(['v' => 1, 'topic' => 'a', 'model' => ['slides' => [['blocks' => [['fileKey' => $used]]]]]]);
        activity_draft::update($course->id, self::KIND, $data);
        $this->assertNotNull(draft_files::get($user->id, $course->id, self::KIND, $used));
        $this->assertNull(draft_files::get($user->id, $course->id, self::KIND, $unused));

        activity_draft::reset($course->id, self::KIND, true);
        $this->assertNotNull(draft_files::get($user->id, $course->id, self::KIND, $used));
        activity_draft::reset($course->id, self::KIND);
        $this->assertNull(draft_files::get($user->id, $course->id, self::KIND, $used));
    }

    /**
     * The files go with the user and with the course, and the task deletes the ones left without a draft.
     */
    public function test_files_are_deleted_with_their_owner_and_when_orphaned(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $user = $this->teacher();
        foreach ([[$course, 'cccccccc3333.png'], [$other, 'dddddddd4444.png']] as [$where, $key]) {
            $item = $this->in_draft_area('x.png', base64_decode(self::PNG));
            activity_draft::file_save($where->id, self::KIND, $key, $item, 'image');
        }

        delete_course($course, false);
        $this->assertNull(draft_files::get($user->id, $course->id, self::KIND, 'cccccccc3333.png'));
        $this->assertNotNull(draft_files::get($user->id, $other->id, self::KIND, 'dddddddd4444.png'));

        // Without a draft and old, the file is left over.
        $DB->set_field('files', 'timecreated', time() - draft_files::ORPHAN_LIFETIME - 10, ['component' => 'local_coursegen']);
        (new \local_coursegen\task\clean_activity_drafts())->execute();
        $this->assertNull(draft_files::get($user->id, $other->id, self::KIND, 'dddddddd4444.png'));
    }
}
