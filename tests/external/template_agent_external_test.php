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

namespace local_coursegen\external;

use local_coursegen\local\models\course_session;

/**
 * The web services that answer a question of a template run and read its state.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\answer_template_question
 * @covers     \local_coursegen\external\get_template_agent_state
 * @covers     \local_coursegen\external\start_template_generation
 */
final class template_agent_external_test extends \advanced_testcase {
    /**
     * A user who may create courses from a template.
     *
     * @return \stdClass The user.
     */
    private function teacher(): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/coursegen:createtemplatecoursewithai', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        return $user;
    }

    /**
     * A session of a user.
     *
     * @param int $userid Owner.
     * @return int Local session id.
     */
    private function session(int $userid): int {
        $session = new course_session(0, (object) [
            'userid' => $userid, 'session_id' => 'thread-1', 'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode(['templateid' => 1]),
        ]);
        $session->create();
        return (int) $session->get('id');
    }

    /**
     * A user without the capability cannot answer.
     */
    public function test_answering_needs_the_capability(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $sessionid = $this->session((int) $user->id);

        $this->expectException(\required_capability_exception::class);
        answer_template_question::execute($sessionid, 'c1', 'text', 0, 'x', '');
    }

    /**
     * Nobody answers the run of another user.
     */
    public function test_answering_the_run_of_another_user_is_refused(): void {
        $this->resetAfterTest();
        $owner = $this->teacher();
        $other = $this->teacher();
        $sessionid = $this->session((int) $owner->id);
        $this->setUser($other);

        $this->expectException(\moodle_exception::class);
        answer_template_question::execute($sessionid, 'c1', 'text', 0, 'x', '');
    }

    /**
     * A kind that is not file, text or choice is rejected by the parameter check or the answerer.
     */
    public function test_an_unknown_kind_is_refused(): void {
        $this->resetAfterTest();
        $user = $this->teacher();
        $this->setUser($user);
        $sessionid = $this->session((int) $user->id);

        $this->expectException(\coding_exception::class);
        answer_template_question::execute($sessionid, 'c1', 'video', 0, '', '');
    }

    /**
     * A blank text answer is refused before anything is sent.
     */
    public function test_a_blank_text_answer_is_refused(): void {
        $this->resetAfterTest();
        $user = $this->teacher();
        $this->setUser($user);
        $sessionid = $this->session((int) $user->id);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('templateanswertextmissing', 'local_coursegen'));
        answer_template_question::execute($sessionid, 'c1', 'text', 0, '   ', '');
    }

    /**
     * A call id with characters outside letters, digits, dash and underscore is rejected.
     */
    public function test_a_call_id_with_other_characters_is_rejected(): void {
        $this->resetAfterTest();
        $user = $this->teacher();
        $this->setUser($user);
        $sessionid = $this->session((int) $user->id);

        $this->expectException(\invalid_parameter_exception::class);
        answer_template_question::execute($sessionid, 'c1; DROP', 'text', 0, 'x', '');
    }

    /**
     * Reading the state needs the capability and the session of the user.
     */
    public function test_reading_the_state_needs_the_capability_and_the_owner(): void {
        $this->resetAfterTest();
        $plain = $this->getDataGenerator()->create_user();
        $owner = $this->teacher();
        $other = $this->teacher();
        $sessionid = $this->session((int) $owner->id);

        $this->setUser($plain);
        try {
            get_template_agent_state::execute($sessionid);
            $this->fail('A user without the capability must be refused.');
        } catch (\required_capability_exception $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $this->setUser($other);
        $this->expectException(\moodle_exception::class);
        get_template_agent_state::execute($sessionid);
    }

    /**
     * A syllabus attached to a template generation is refused with a clear message, not dropped in silence.
     */
    public function test_an_attached_syllabus_is_refused(): void {
        $this->resetAfterTest();
        $user = $this->teacher();
        $this->setUser($user);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('templatesyllabusunsupported', 'local_coursegen'));
        start_template_generation::execute(1, 'x', 123);
    }

    /**
     * The services are registered and the answer is a write while the state is a read.
     */
    public function test_the_services_are_registered_with_the_right_type(): void {
        $functions = [];
        $services = [];
        require(__DIR__ . '/../../db/services.php');

        $this->assertSame('write', $functions['local_coursegen_answer_template_question']['type']);
        $this->assertSame('read', $functions['local_coursegen_get_template_agent_state']['type']);
        $this->assertArrayNotHasKey('local_coursegen_template_review_feedback', $functions);
    }
}
