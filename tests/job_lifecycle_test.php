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
use local_coursegen\external\create_mod;
use local_coursegen\local\service\module_job_service;
use local_coursegen\tests\api_testcase;

/**
 * Job lifecycle tests: a generation job is single-use.
 *
 * The AI service and the download HTTP client are both mocked, so no network
 * request is ever performed.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\create_mod
 * @covers     \local_coursegen\local\service\module_job_service
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\external\create_mod::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\local\service\module_job_service::class)]
final class job_lifecycle_test extends api_testcase {
    /**
     * Give the front page real section rows.
     */
    protected function setUp(): void {
        parent::setUp();

        // See create_mod_permissions_test: give the front page real section rows.
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        course_create_sections_if_missing(get_site(), [0, 1]);
    }

    /**
     * A successful creation marks the job consumed and a replay is rejected
     * with the localized error before touching the AI service again.
     */
    public function test_consumed_job_cannot_be_replayed(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $job = module_job_service::create_job($course->id, $USER->id, 'job-once', 0, null, null, 1, null, 'completed');
        $this->inject_api_service(['get_activity_result' => $this->h5p_activity_result()]);
        $this->inject_download_client();

        $result = create_mod::execute($course->id, 1, 'job-once');
        $this->resetDebugging();
        $this->assertTrue($result['ok'], 'First creation must succeed: ' . ($result['message'] ?? ''));

        // The job is marked consumed after the module is created.
        $this->assertSame(
            module_job_service::STATUS_CONSUMED,
            $DB->get_field('local_coursegen_module_jobs', 'status', ['id' => $job->get('id')])
        );

        // Replaying the same job must be rejected with the localized error.
        try {
            create_mod::execute($course->id, 1, 'job-once');
            $this->fail('A moodle_exception was expected for the replayed job.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_job_already_used', $e->errorcode);
            $this->assertStringContainsString(
                get_string('error_job_already_used', 'local_coursegen'),
                $e->getMessage()
            );
        }
        $this->resetDebugging();

        // The replay created nothing: still exactly one module in the course.
        $this->assertSame(1, $DB->count_records('course_modules', ['course' => $course->id]));
    }

    /**
     * The consumed status constant and update_status() transition work as a unit.
     */
    public function test_update_status_marks_job_consumed(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $job = module_job_service::create_job($course->id, $USER->id, 'job-status', 0, null, null, 1, null, 'completed');

        $this->assertSame('consumed', module_job_service::STATUS_CONSUMED);

        module_job_service::update_status((int)$job->get('id'), module_job_service::STATUS_CONSUMED);

        $reloaded = module_job_service::get_user_job('job-status', $course->id, (int)$USER->id);
        $this->assertSame(module_job_service::STATUS_CONSUMED, $reloaded->get('status'));
    }
}
