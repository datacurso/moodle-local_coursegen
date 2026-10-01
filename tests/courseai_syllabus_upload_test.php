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

use core\exception\moodle_exception;
use core\exception\require_login_exception;
use local_coursegen\local\models\course_session;

/**
 * Access contract of the syllabus upload web service.
 *
 * The endpoint must validate the system context like its sibling planning
 * endpoints (so a logged-out caller is rejected before any capability check)
 * and must resolve the session through course_session_service, so a session
 * owned by someone else or a missing one raise the same exception as the
 * other session-bound endpoints instead of a soft "success: false" reply.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\courseai_syllabus_upload
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\external\courseai_syllabus_upload::class)]
final class courseai_syllabus_upload_test extends \advanced_testcase {
    /**
     * Load the testable subclass fixture.
     */
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/testable_courseai_syllabus_upload.php');
    }

    /**
     * Reset the injected doubles between tests.
     */
    protected function tearDown(): void {
        testable_courseai_syllabus_upload::$mockservice = null;
        parent::tearDown();
    }

    /**
     * Create a planning session owned by the given user.
     *
     * @param int $userid Owner user id.
     * @return course_session
     */
    private function create_session(int $userid): course_session {
        $session = new course_session(0, (object) [
            'userid' => $userid,
            'session_id' => 'thread-1',
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode(['local_coursegen_context_type' => 'customprompt']),
        ]);
        $session->create();

        return $session;
    }

    /**
     * Run the endpoint and return the raised moodle_exception.
     *
     * @param int $sessionid Session record id.
     * @return moodle_exception
     */
    private function execute_expecting_exception(int $sessionid): moodle_exception {
        try {
            testable_courseai_syllabus_upload::execute($sessionid, file_get_unused_draft_itemid());
        } catch (moodle_exception $e) {
            return $e;
        }

        $this->fail('The upload must be rejected with a moodle_exception.');
    }

    /**
     * A logged-out caller is rejected by the context validation.
     */
    public function test_not_logged_in_user_is_rejected(): void {
        $this->resetAfterTest();

        $session = $this->create_session(get_admin()->id);
        $this->setUser(null);

        $this->expectException(require_login_exception::class);
        testable_courseai_syllabus_upload::execute((int)$session->get('id'), file_get_unused_draft_itemid());
    }

    /**
     * A session owned by another user cannot receive a syllabus.
     */
    public function test_session_of_another_user_is_rejected(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $session = $this->create_session((int)$owner->id);
        $this->setAdminUser();

        $exception = $this->execute_expecting_exception((int)$session->get('id'));
        $this->assertSame('error_no_session_found', $exception->errorcode);
        $this->assertSame('local_coursegen', $exception->module);
    }

    /**
     * A session id that does not exist is rejected the same way.
     */
    public function test_missing_session_is_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $exception = $this->execute_expecting_exception(999999);
        $this->assertSame('error_no_session_found', $exception->errorcode);
        $this->assertSame('local_coursegen', $exception->module);
    }
}
