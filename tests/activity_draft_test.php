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
}
