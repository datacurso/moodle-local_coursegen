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

use context_system;
use local_coursegen\local\service\course_session_service;

/**
 * Tests for the syllabus file name that a reloaded planning page shows again.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\course_session_service::get_syllabus_filename
 */
final class course_session_syllabus_name_test extends \advanced_testcase {
    /**
     * Every test starts from a clean database.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A session with an uploaded syllabus answers the name of that file.
     */
    public function test_the_name_of_the_uploaded_syllabus_is_returned(): void {
        $session = $this->create_session();
        $this->store_syllabus((int)$session->get('id'), 'Marketing syllabus.pdf');

        $this->assertSame('Marketing syllabus.pdf', course_session_service::get_syllabus_filename((int)$session->get('id')));
    }

    /**
     * A session that never received a syllabus answers an empty name.
     */
    public function test_a_session_without_syllabus_answers_an_empty_name(): void {
        $session = $this->create_session();

        $this->assertSame('', course_session_service::get_syllabus_filename((int)$session->get('id')));
    }

    /**
     * A syllabus stored for another session is not returned.
     */
    public function test_the_syllabus_of_another_session_is_not_returned(): void {
        $first = $this->create_session();
        $second = $this->create_session();
        $this->store_syllabus((int)$first->get('id'), 'First syllabus.pdf');

        $this->assertSame('', course_session_service::get_syllabus_filename((int)$second->get('id')));
    }

    /**
     * A record id that does not exist answers an empty name instead of failing.
     */
    public function test_an_unknown_record_answers_an_empty_name(): void {
        $this->assertSame('', course_session_service::get_syllabus_filename(999999));
    }

    /**
     * Create a planning session for a new user.
     *
     * @return \local_coursegen\local\models\course_session
     */
    private function create_session(): \local_coursegen\local\models\course_session {
        $user = $this->getDataGenerator()->create_user();
        $data = (object) ['local_coursegen_lang' => 'en'];

        return course_session_service::create_from_form_data($data, (int)$user->id, 'thread-' . random_string(12));
    }

    /**
     * Store a syllabus file for a session, where the upload stores it.
     *
     * @param int $recordid Session record id, used as item id.
     * @param string $filename Name of the file.
     */
    private function store_syllabus(int $recordid, string $filename): void {
        get_file_storage()->create_file_from_string([
            'contextid' => context_system::instance()->id,
            'component' => 'local_coursegen',
            'filearea' => 'syllabus',
            'itemid' => $recordid,
            'filepath' => '/',
            'filename' => $filename,
        ], 'pdf content');
    }
}
