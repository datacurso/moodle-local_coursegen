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

use aiprovider_datacurso\httpclient\ai_course_api;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\writer;
use local_coursegen\local\api_client_factory;
use local_coursegen\local\service\remote_data_service;
use local_coursegen\privacy\provider;
use local_coursegen\task\erase_remote_data;

/**
 * What the course service keeps is exported and deleted from the Privacy API (Mindfree, LCG-PRIV-001).
 *
 * Every deletion of the plugin (Privacy API, deleted user, deleted course) queues the deletion of
 * the same threads at the service, naming the threads it holds and, for a person, the person. An
 * export of a person includes what the service keeps of them.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\remote_data_service
 * @covers     \local_coursegen\task\erase_remote_data
 * @covers     \local_coursegen\local\service\user_data_eraser
 * @covers     \local_coursegen\privacy\provider
 * @covers     \local_coursegen\observer
 */
final class privacy_remote_data_test extends \advanced_testcase {
    /** @var array[] Requests sent to the service: method, path and payload. */
    private array $requests = [];

    /** @var \Throwable|null Failure the service double answers with, if any. */
    private ?\Throwable $failure = null;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->requests = [];
        $this->failure = null;

        $client = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request'])
            ->getMock();
        $client->method('request')->willReturnCallback(
            function (string $method, string $path, array $payload = []): array {
                $this->requests[] = ['method' => $method, 'path' => $path, 'payload' => $payload];
                if ($this->failure !== null) {
                    throw $this->failure;
                }
                if ($path === remote_data_service::EXPORT_PATH) {
                    return ['threads' => [[
                        'thread_id' => 'session-a',
                        'conversation' => [['content' => 'A course about soil science']],
                    ]]];
                }
                return ['deleted' => count($payload['thread_ids'] ?? [])];
            }
        );
        api_client_factory::set_test_client($client);
    }

    #[\Override]
    protected function tearDown(): void {
        api_client_factory::set_test_client(null);
        parent::tearDown();
    }

    /**
     * Store a planning session of a user in a course.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $threadid
     */
    private function session(int $userid, int $courseid, string $threadid): void {
        global $DB;
        $DB->insert_record('local_coursegen_course_sessions', (object)[
            'courseid' => $courseid,
            'userid' => $userid,
            'session_id' => $threadid,
            'status' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Store a module generation job of a user in a course.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $threadid
     */
    private function job(int $userid, int $courseid, string $threadid): void {
        global $DB;
        $DB->insert_record('local_coursegen_module_jobs', (object)[
            'courseid' => $courseid,
            'userid' => $userid,
            'job_id' => $threadid,
            'generate_images' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Run the queued remote deletions and return the deletion requests the service received.
     *
     * @return array[] Payloads of the deletion requests.
     */
    private function run_remote_erasures(): array {
        ob_start();
        $this->runAdhocTasks(erase_remote_data::class);
        ob_end_clean();
        $payloads = [];
        foreach ($this->requests as $request) {
            if ($request['path'] === remote_data_service::DELETE_PATH) {
                $payloads[] = $request['payload'];
            }
        }
        return $payloads;
    }

    /**
     * Normalise a deletion payload for comparison.
     *
     * @param array $payload
     * @return array
     */
    private function scope(array $payload): array {
        $threadids = $payload['thread_ids'];
        sort($threadids);
        return ['userid' => $payload['userid'], 'thread_ids' => $threadids];
    }

    /**
     * Deleting a user deletes at the service their threads, named and by person.
     */
    public function test_deleting_a_user_erases_their_threads_at_the_service(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->session((int)$user->id, (int)$course->id, 'session-a');
        $this->job((int)$user->id, (int)$course->id, 'job-a');
        $this->session((int)$other->id, (int)$course->id, 'session-b');

        delete_user($user);
        $payloads = $this->run_remote_erasures();

        $this->assertCount(1, $payloads);
        $this->assertSame(
            ['userid' => (string)$user->id, 'thread_ids' => ['job-a', 'session-a']],
            $this->scope($payloads[0])
        );
    }

    /**
     * A Privacy API deletion of a course erases at the service the threads of that course only, of nobody in particular.
     */
    public function test_privacy_deletion_of_a_course_erases_its_threads(): void {
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->session((int)$user->id, (int)$course->id, 'session-a');
        $this->job((int)$user->id, (int)$course->id, 'job-a');
        $this->session((int)$user->id, (int)$othercourse->id, 'session-other');

        provider::delete_data_for_all_users_in_context(\context_course::instance($course->id));
        $payloads = $this->run_remote_erasures();

        $this->assertCount(1, $payloads);
        $this->assertSame(['userid' => '', 'thread_ids' => ['job-a', 'session-a']], $this->scope($payloads[0]));
    }

    /**
     * A Privacy API deletion for one user in a course erases only that user's threads of that course.
     */
    public function test_privacy_deletion_for_a_user_in_a_course_erases_only_their_threads_there(): void {
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $classmate = $this->getDataGenerator()->create_user();
        $this->session((int)$user->id, (int)$course->id, 'session-a');
        $this->session((int)$user->id, (int)$othercourse->id, 'session-other');
        $this->session((int)$classmate->id, (int)$course->id, 'session-classmate');

        provider::delete_data_for_user(
            new approved_contextlist($user, 'local_coursegen', [\context_course::instance($course->id)->id])
        );
        $payloads = $this->run_remote_erasures();

        $this->assertCount(1, $payloads);
        $this->assertSame(['userid' => '', 'thread_ids' => ['session-a']], $this->scope($payloads[0]));
    }

    /**
     * A Privacy API deletion for several users of a course erases their threads of that course.
     */
    public function test_privacy_deletion_for_users_in_a_course_erases_their_threads(): void {
        $course = $this->getDataGenerator()->create_course();
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $kept = $this->getDataGenerator()->create_user();
        $this->session((int)$usera->id, (int)$course->id, 'session-a');
        $this->job((int)$userb->id, (int)$course->id, 'job-b');
        $this->session((int)$kept->id, (int)$course->id, 'session-kept');

        provider::delete_data_for_users(new approved_userlist(
            \context_course::instance($course->id),
            'local_coursegen',
            [(int)$usera->id, (int)$userb->id]
        ));
        $erased = [];
        foreach ($this->run_remote_erasures() as $payload) {
            $erased = array_merge($erased, $payload['thread_ids']);
        }
        sort($erased);

        $this->assertSame(['job-b', 'session-a'], $erased);
    }

    /**
     * Deleting a course erases at the service the threads opened in it.
     */
    public function test_deleting_a_course_erases_its_threads_at_the_service(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->session((int)$user->id, (int)$course->id, 'session-a');
        $this->job((int)$user->id, (int)$course->id, 'job-a');

        delete_course($course, false);
        $payloads = $this->run_remote_erasures();

        $this->assertCount(1, $payloads);
        $this->assertSame(['userid' => '', 'thread_ids' => ['job-a', 'session-a']], $this->scope($payloads[0]));
    }

    /**
     * Nothing is asked of the service for a course in which the plugin opened no thread.
     */
    public function test_a_course_without_threads_asks_nothing(): void {
        $course = $this->getDataGenerator()->create_course();

        delete_course($course, false);

        $this->assertSame([], $this->run_remote_erasures());
    }

    /**
     * A failed deletion at the service makes the task fail, so Moodle runs it again; the local data is gone already.
     */
    public function test_a_failed_remote_deletion_is_retried(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->session((int)$user->id, (int)$course->id, 'session-a');
        $this->failure = new \moodle_exception('httperror', 'aiprovider_datacurso', '', 503);

        delete_user($user);
        $this->assertFalse($DB->record_exists('local_coursegen_course_sessions', ['userid' => $user->id]));

        ob_start();
        try {
            $this->runAdhocTasks(erase_remote_data::class);
            $this->fail('The failed deletion must make the task fail.');
        } catch (\moodle_exception $e) {
            $this->assertSame('httperror', $e->errorcode);
        } finally {
            ob_end_clean();
        }
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(erase_remote_data::class), 'It stays queued.');

        $this->failure = null;
        $this->requests = [];
        $payloads = $this->run_remote_erasures();

        $this->assertSame(['userid' => (string)$user->id, 'thread_ids' => ['session-a']], $this->scope($payloads[0]));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(erase_remote_data::class));
    }

    /**
     * The export of a user includes what the service keeps of their threads.
     */
    public function test_export_includes_what_the_service_keeps(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->session((int)$user->id, (int)$course->id, 'session-a');
        $context = \context_user::instance($user->id);

        provider::export_user_data(new approved_contextlist($user, 'local_coursegen', [$context->id]));

        $export = $this->requests[0];
        $this->assertSame(remote_data_service::EXPORT_PATH, $export['path']);
        $this->assertSame(['userid' => (string)$user->id, 'thread_ids' => ['session-a']], $export['payload']);
        $data = writer::with_context($context)->get_data([
            get_string('privacy:metadata:local_coursegen', 'local_coursegen'),
            get_string('privacy:remote_service', 'local_coursegen'),
        ]);
        $this->assertSame('A course about soil science', $data->threads[0]['conversation'][0]['content']);
    }

    /**
     * When the service cannot be reached, the export says so and still exports the local data.
     */
    public function test_export_says_when_the_service_is_unreachable(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->session((int)$user->id, (int)$course->id, 'session-a');
        $context = \context_user::instance($user->id);
        $this->failure = new \moodle_exception('curlerror', 'aiprovider_datacurso', '', 7);

        provider::export_user_data(new approved_contextlist($user, 'local_coursegen', [$context->id]));
        $this->assertDebuggingCalled();

        $writer = writer::with_context($context);
        $remote = $writer->get_data([
            get_string('privacy:metadata:local_coursegen', 'local_coursegen'),
            get_string('privacy:remote_service', 'local_coursegen'),
        ]);
        $this->assertSame(get_string('privacy:remote_unavailable', 'local_coursegen'), $remote->status);
        $sessions = $writer->get_data([
            get_string('privacy:metadata:local_coursegen', 'local_coursegen'),
            get_string('privacy:metadata:local_coursegen_course_sessions', 'local_coursegen'),
        ]);
        $this->assertSame('session-a', $sessions->session_id);
    }
}
