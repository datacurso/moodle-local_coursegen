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

namespace local_coursegen\local\service;

use local_coursegen\local\models\course_session;

/**
 * What the page needs to pick up a template generation after a reload.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_resume_context
 */
final class template_resume_context_test extends \advanced_testcase {
    /**
     * A session of a user.
     *
     * @param int $userid Owner.
     * @param int $status Status of the session.
     * @param mixed $coursedata Data stored with the session.
     * @return course_session The session.
     */
    private function session(int $userid, int $status, $coursedata): course_session {
        $session = new course_session(0, (object) [
            'userid' => $userid,
            'session_id' => 'thread-' . uniqid(),
            'status' => $status,
            'coursedata' => $this->encode($coursedata),
        ]);
        $session->create();
        return $session;
    }

    /**
     * The text stored as the data of a session.
     *
     * @param mixed $coursedata A text kept as it is, or data to encode.
     * @return string The text.
     */
    private function encode($coursedata): string {
        if (is_string($coursedata)) {
            return $coursedata;
        }
        return json_encode($coursedata);
    }

    /**
     * An unfinished template session gives its template, its prompt and its name.
     */
    public function test_an_unfinished_template_session_is_resumed(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $templateid = $DB->insert_record('local_coursegen_template', (object) [
            'courseid' => $course->id, 'name' => 'Marketing', 'description' => '',
            'timecreated' => time(), 'timemodified' => time(), 'usermodified' => $user->id,
        ]);
        $session = $this->session((int) $user->id, course_session::STATUS_PENDING, [
            'templateid' => $templateid, 'payload' => ['general_instruction' => 'Crea el curso 🙂'],
        ]);

        $context = template_resume_context::for_session((int) $session->get('id'), (int) $user->id);

        $this->assertSame([
            'sessionid' => (int) $session->get('id'),
            'templateid' => (int) $templateid,
            'prompt' => 'Crea el curso 🙂',
            'templatename' => 'Marketing',
        ], $context);
    }

    /**
     * A session of another user is not resumed.
     */
    public function test_a_session_of_another_user_is_not_resumed(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $session = $this->session((int) $owner->id, course_session::STATUS_PENDING, ['templateid' => 3]);

        $this->assertNull(template_resume_context::for_session((int) $session->get('id'), (int) $other->id));
    }

    /**
     * A finished session, a free session and a missing session are not resumed.
     */
    public function test_finished_free_and_missing_sessions_are_not_resumed(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $created = $this->session((int) $user->id, course_session::STATUS_CREATED, ['templateid' => 3]);
        $free = $this->session((int) $user->id, course_session::STATUS_PENDING, ['local_coursegen_lang' => 'es']);

        $this->assertNull(template_resume_context::for_session((int) $created->get('id'), (int) $user->id));
        $this->assertNull(template_resume_context::for_session((int) $free->get('id'), (int) $user->id));
        $this->assertNull(template_resume_context::for_session(987654, (int) $user->id));
        $this->assertNull(template_resume_context::for_session(0, (int) $user->id));
        $this->assertNull(template_resume_context::for_session(-4, (int) $user->id));
    }

    /**
     * Data that is not valid JSON, and a template that no longer exists, do not break the context.
     */
    public function test_broken_data_and_a_missing_template_do_not_break_it(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $broken = $this->session((int) $user->id, course_session::STATUS_PENDING, '{not json');
        $gone = $this->session((int) $user->id, course_session::STATUS_PENDING, ['templateid' => 555, 'payload' => 'x']);

        $this->assertNull(template_resume_context::for_session((int) $broken->get('id'), (int) $user->id));
        $context = template_resume_context::for_session((int) $gone->get('id'), (int) $user->id);
        $this->assertSame('', $context['templatename']);
        $this->assertSame('', $context['prompt']);
    }
}
