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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use local_coursegen\privacy\provider;

/**
 * The text a person wrote does not outlive their deletion (Mindfree, LCG-PRIV-004).
 *
 * The custom prompt of a course is free text a teacher writes: it goes when its author is deleted,
 * through the Privacy API or by deleting the user, and when the course is erased through the
 * Privacy API. The system instructions are institutional configuration written by administrators
 * for every course: they are kept, without the reference to the person.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\privacy\provider
 * @covers     \local_coursegen\observer
 * @covers     \local_coursegen\local\service\user_data_eraser
 */
final class privacy_user_text_test extends \advanced_testcase {
    /**
     * A course whose custom prompt was written by the given user.
     *
     * @param int $userid Author of the prompt.
     * @param string $prompt Prompt text.
     * @return \stdClass The course.
     */
    private function course_with_prompt(int $userid, string $prompt): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('local_coursegen_course_context', (object)[
            'courseid' => $course->id,
            'context_type' => ai_context::CONTEXT_TYPE_CUSTOM_PROMPT,
            'prompt_text' => $prompt,
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => $userid,
        ]);
        return $course;
    }

    /**
     * A system instruction last edited by the given user.
     *
     * @param int $userid Editor.
     * @return int Instruction id.
     */
    private function system_instruction(int $userid): int {
        global $DB;
        return (int)$DB->insert_record('local_coursegen_system_instruction', (object)[
            'name' => 'Institutional instruction',
            'content' => 'Write for first-year students.',
            'deleted' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => $userid,
        ]);
    }

    /**
     * A planning session of the user, whose data carries the prompt they typed.
     *
     * @param int $userid Owner.
     * @param int $courseid Course.
     * @return void
     */
    private function session(int $userid, int $courseid): void {
        global $DB;
        $DB->insert_record('local_coursegen_course_sessions', (object)[
            'courseid' => $courseid,
            'userid' => $userid,
            'session_id' => 'sess_' . random_string(8),
            'status' => 1,
            'coursedata' => json_encode(['local_coursegen_custom_prompt' => 'A course about my students']),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * The prompt of a course.
     *
     * @param int $courseid
     * @return string|null
     */
    private function prompt_of(int $courseid): ?string {
        global $DB;
        $value = $DB->get_field('local_coursegen_course_context', 'prompt_text', ['courseid' => $courseid], MUST_EXIST);
        return $value === null ? null : (string)$value;
    }

    /**
     * Assert that the institutional instruction is kept, without the reference to the person.
     *
     * @param int $instructionid
     */
    private function assert_instruction_kept_anonymised(int $instructionid): void {
        global $DB;
        $instruction = $DB->get_record('local_coursegen_system_instruction', ['id' => $instructionid], '*', MUST_EXIST);
        $this->assertSame('Write for first-year students.', $instruction->content);
        $this->assertEquals(0, $instruction->usermodified);
    }

    /**
     * A privacy deletion of a user erases the prompts they wrote and keeps the prompts of others.
     */
    public function test_privacy_deletion_of_a_user_erases_their_prompts(): void {
        global $DB;
        $this->resetAfterTest();
        $author = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $theirs = $this->course_with_prompt($author->id, 'Course for the class of Ms. Smith');
        $kept = $this->course_with_prompt($other->id, 'Course written by someone else');
        $instructionid = $this->system_instruction($author->id);
        $this->session($author->id, $theirs->id);

        provider::delete_data_for_user(new approved_contextlist(
            $author,
            'local_coursegen',
            [\context_user::instance($author->id)->id]
        ));

        $this->assertNull($this->prompt_of($theirs->id));
        $this->assertEquals(0, $DB->get_field('local_coursegen_course_context', 'usermodified', ['courseid' => $theirs->id]));
        $this->assertSame('Course written by someone else', $this->prompt_of($kept->id));
        $this->assert_instruction_kept_anonymised($instructionid);
        $this->assertFalse($DB->record_exists('local_coursegen_course_sessions', ['userid' => $author->id]));
    }

    /**
     * Deleting a user in Moodle erases their prompts, sessions and jobs too, without a privacy request.
     */
    public function test_deleting_a_user_erases_their_prompts_and_sessions(): void {
        global $DB;
        $this->resetAfterTest();
        $author = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $theirs = $this->course_with_prompt($author->id, 'Course for the class of Ms. Smith');
        $kept = $this->course_with_prompt($other->id, 'Course written by someone else');
        $instructionid = $this->system_instruction($author->id);
        $this->session($author->id, $theirs->id);

        delete_user($author);

        $this->assertNull($this->prompt_of($theirs->id));
        $this->assertSame('Course written by someone else', $this->prompt_of($kept->id));
        $this->assert_instruction_kept_anonymised($instructionid);
        $this->assertFalse($DB->record_exists('local_coursegen_course_sessions', ['userid' => $author->id]));
    }

    /**
     * A course without its prompt is no longer a usable AI context: it has to be configured again.
     */
    public function test_a_course_without_its_prompt_has_no_ai_context(): void {
        $this->resetAfterTest();
        $author = $this->getDataGenerator()->create_user();
        $course = $this->course_with_prompt($author->id, 'Course for the class of Ms. Smith');
        $this->assertNotNull(ai_context::get_valid_course_context((int)$course->id));

        delete_user($author);

        $this->assertNull(ai_context::get_valid_course_context((int)$course->id));
    }

    /**
     * A privacy deletion of a whole course erases its prompt, whoever wrote it.
     */
    public function test_privacy_deletion_of_a_course_erases_its_prompt(): void {
        $this->resetAfterTest();
        $author = $this->getDataGenerator()->create_user();
        $course = $this->course_with_prompt($author->id, 'Course for the class of Ms. Smith');
        $other = $this->course_with_prompt($author->id, 'Another course');

        provider::delete_data_for_all_users_in_context(\context_course::instance($course->id));

        $this->assertNull($this->prompt_of($course->id));
        $this->assertSame('Another course', $this->prompt_of($other->id));
    }

    /**
     * A privacy deletion of some users of a course erases only the prompt they wrote there.
     */
    public function test_privacy_deletion_of_users_in_a_course_erases_only_their_prompt(): void {
        $this->resetAfterTest();
        $author = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $theirs = $this->course_with_prompt($author->id, 'Course for the class of Ms. Smith');
        $kept = $this->course_with_prompt($other->id, 'Course written by someone else');

        provider::delete_data_for_users(new approved_userlist(
            \context_course::instance($theirs->id),
            'local_coursegen',
            [(int)$author->id]
        ));
        provider::delete_data_for_users(new approved_userlist(
            \context_course::instance($kept->id),
            'local_coursegen',
            [(int)$author->id]
        ));

        $this->assertNull($this->prompt_of($theirs->id));
        $this->assertSame('Course written by someone else', $this->prompt_of($kept->id));
    }
}
