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

use local_coursegen\external\answer_template_questions;
use local_coursegen\external\stop_template_generation;
use local_coursegen\local\models\course_session;
use local_coursegen\local\service\draft_file;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../capability_user_trait.php');

/**
 * Who may answer the questions of a template generation, or stop it, and what they may send.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\answer_template_questions
 * @covers     \local_coursegen\external\stop_template_generation
 * @covers     \local_coursegen\local\service\draft_file
 *
 * @runTestsInSeparateProcesses
 */
final class template_questions_and_stop_test extends \advanced_testcase {
    use capability_user_trait;

    /**
     * A session of a template generation that belongs to the given user.
     *
     * @param int $userid
     * @return course_session
     */
    private function create_session(int $userid): course_session {
        $session = new course_session(0, (object) [
            'userid' => $userid,
            'session_id' => 'thread-' . $userid,
            'status' => course_session::STATUS_PENDING,
            'templateid' => 0,
            'coursedata' => json_encode(['templateid' => 0, 'payload' => [], 'syllabus' => '']),
        ]);
        $session->create();
        return $session;
    }

    /**
     * A user who may start a generation can answer its questions.
     */
    public function test_answering_needs_the_capability_to_create_with_a_template(): void {
        $this->resetAfterTest();
        $this->login_without('local/coursegen:createtemplatecoursewithai');
        $session = $this->create_session($GLOBALS['USER']->id);

        $this->expectException(\required_capability_exception::class);
        answer_template_questions::execute($session->get('id'), true, []);
    }

    /**
     * Another user's generation cannot be answered.
     */
    public function test_answering_a_generation_of_someone_else_is_refused(): void {
        $this->resetAfterTest();
        $other = $this->getDataGenerator()->create_user();
        $session = $this->create_session((int) $other->id);
        $this->login_user_with(['local/coursegen:createtemplatecoursewithai']);

        $this->expectException(\moodle_exception::class);
        answer_template_questions::execute($session->get('id'), true, []);
    }

    /**
     * An answer that is not JSON is refused before anything is sent to the service.
     */
    public function test_an_answer_that_is_not_json_is_refused(): void {
        $this->resetAfterTest();
        $this->login_user_with(['local/coursegen:createtemplatecoursewithai']);
        $session = $this->create_session($GLOBALS['USER']->id);

        $this->expectException(\invalid_parameter_exception::class);
        answer_template_questions::execute($session->get('id'), false, [
            ['questionid' => 'images', 'value' => '{not json'],
        ]);
    }

    /**
     * A file answer needs the capability to upload a syllabus.
     */
    public function test_a_file_answer_needs_the_capability_to_upload_a_syllabus(): void {
        $this->resetAfterTest();
        $this->login_user_with(['local/coursegen:createtemplatecoursewithai']);
        $session = $this->create_session($GLOBALS['USER']->id);

        $this->expectException(\required_capability_exception::class);
        answer_template_questions::execute($session->get('id'), false, [
            ['questionid' => 'syllabus', 'value' => json_encode(['draftitemid' => 5, 'filename' => 'g.pdf'])],
        ]);
    }

    /**
     * A file answer that points at a draft item with no file is refused.
     */
    public function test_a_file_answer_with_no_file_in_its_draft_item_is_refused(): void {
        $this->resetAfterTest();
        $this->login_user_with([
            'local/coursegen:createtemplatecoursewithai',
            'local/coursegen:uploadcoursesyllabus',
        ]);
        $session = $this->create_session($GLOBALS['USER']->id);

        $this->expectException(\invalid_parameter_exception::class);
        answer_template_questions::execute($session->get('id'), false, [
            ['questionid' => 'syllabus', 'value' => json_encode(['draftitemid' => 987654, 'filename' => 'g.pdf'])],
        ]);
    }

    /**
     * Stopping needs the same capability as starting.
     */
    public function test_stopping_needs_the_capability_to_create_with_a_template(): void {
        $this->resetAfterTest();
        $this->login_without('local/coursegen:createtemplatecoursewithai');
        $session = $this->create_session($GLOBALS['USER']->id);

        $this->expectException(\required_capability_exception::class);
        stop_template_generation::execute($session->get('id'));
    }

    /**
     * Another user's generation cannot be stopped.
     */
    public function test_stopping_a_generation_of_someone_else_is_refused(): void {
        $this->resetAfterTest();
        $other = $this->getDataGenerator()->create_user();
        $session = $this->create_session((int) $other->id);
        $this->login_user_with(['local/coursegen:createtemplatecoursewithai']);

        $this->expectException(\moodle_exception::class);
        stop_template_generation::execute($session->get('id'));
    }

    /**
     * A draft item that does not exist, or has no file, gives no file.
     */
    public function test_the_draft_file_of_nothing_is_null(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertNull(draft_file::first(0));
        $this->assertNull(draft_file::first(-3));
        $this->assertNull(draft_file::first(123456));
    }

    /**
     * The first file of a draft item of the user is the one that is read.
     */
    public function test_the_draft_file_of_an_item_with_a_file_is_that_file(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $itemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => 'guide.pdf',
        ], 'content');

        $file = draft_file::first($itemid);

        $this->assertNotNull($file);
        $this->assertSame('guide.pdf', $file->get_filename());
    }

    /**
     * What the service answers to both calls reaches the page in the shape it reads.
     */
    public function test_the_returns_are_the_shape_the_page_reads(): void {
        $answer = answer_template_questions::execute_returns();
        $stop = stop_template_generation::execute_returns();

        $this->assertArrayHasKey('settled', $answer->keys);
        $this->assertArrayHasKey('answered', $answer->keys);
        $this->assertArrayHasKey('wasrunning', $stop->keys);
        $this->assertArrayHasKey('stopped', $stop->keys);
    }
}
