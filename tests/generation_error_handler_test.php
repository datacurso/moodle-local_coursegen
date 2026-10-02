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

use core\context\course;
use core\context\system;
use core\exception\required_capability_exception;
use local_coursegen\event\generation_denied;
use local_coursegen\event\generation_failed;
use local_coursegen\local\generation_error_handler;

/**
 * Tests for the shared in-band error handler of the activity generation endpoints.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\generation_error_handler
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\local\generation_error_handler::class)]
final class generation_error_handler_test extends \advanced_testcase {
    /** @var string Technical marker that must never surface in a client response. */
    private const TECHNICALDETAIL = 'TECH-SECRET curl error 500 at https://internal-api.invalid/activity/init';

    /**
     * Filter sink events by class.
     *
     * @param \phpunit_event_sink $sink Event sink.
     * @param string $classname Fully qualified event class name.
     * @return array
     */
    private function events_of_class(\phpunit_event_sink $sink, string $classname): array {
        return array_values(array_filter($sink->get_events(), static function ($event) use ($classname): bool {
            return $event instanceof $classname;
        }));
    }

    /**
     * The debugging output collected so far, as plain strings (cleared afterwards).
     *
     * @return string[]
     */
    private function debugging_messages(): array {
        $messages = array_map(static function ($debug): string {
            return (string) $debug->message;
        }, $this->getDebuggingMessages());
        $this->resetDebugging();

        return $messages;
    }

    /**
     * A capability rejection answers the localized permission message and audits the capability.
     */
    public function test_handle_denied_returns_permission_message_and_audits(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $context = course::instance($course->id);
        $exception = new required_capability_exception($context, 'moodle/course:manageactivities', 'nopermissions', '');

        $sink = $this->redirectEvents();
        $result = generation_error_handler::handle_denied($exception, $context, ['jobid' => 'job-1']);
        $this->assertDebuggingCalledCount(1);

        $this->assertSame(['ok' => false, 'message' => $exception->getMessage()], $result);
        $this->assertStringContainsString(
            get_string('nopermissions', 'error', get_capability_string('moodle/course:manageactivities')),
            $result['message']
        );

        $events = $this->events_of_class($sink, generation_denied::class);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertEquals($context->id, $event->get_context()->id);
        $this->assertSame(get_capability_string('moodle/course:manageactivities'), $event->other['capability']);
        $this->assertSame('job-1', $event->other['jobid']);
    }

    /**
     * Any other failure answers the generic localized message: the technical detail stays in
     * debugging and only the exception class is audited.
     */
    public function test_handle_failure_hides_technical_detail(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $context = system::instance();
        $exception = new \RuntimeException(self::TECHNICALDETAIL);

        $sink = $this->redirectEvents();
        $result = generation_error_handler::handle_failure($exception, $context, ['jobid' => 'job-2']);

        $this->assertSame(
            ['ok' => false, 'message' => get_string('error_generating_resource', 'local_coursegen')],
            $result
        );
        $this->assertStringNotContainsString(self::TECHNICALDETAIL, json_encode($result));

        $messages = $this->debugging_messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString(self::TECHNICALDETAIL, $messages[0]);

        $events = $this->events_of_class($sink, generation_failed::class);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertEquals($context->id, $event->get_context()->id);
        $this->assertSame('RuntimeException', $event->other['reason']);
        $this->assertSame('job-2', $event->other['jobid']);
        $this->assertStringNotContainsString(self::TECHNICALDETAIL, json_encode($event->other));
    }

    /**
     * A PHP error (TypeError) is handled like an exception: it must not bypass the localized reply,
     * and its origin (file name and line, no path) is audited so the failure can be traced.
     */
    public function test_handle_failure_accepts_php_errors(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $sink = $this->redirectEvents();
        $error = new \TypeError('bad type');
        $expectedorigin = basename(__FILE__) . ':' . $error->getLine();
        $result = generation_error_handler::handle_failure($error, system::instance());

        $messages = $this->debugging_messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('bad type', $messages[0]);

        $this->assertSame(
            ['ok' => false, 'message' => get_string('error_generating_resource', 'local_coursegen')],
            $result
        );
        $events = $this->events_of_class($sink, generation_failed::class);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertSame('TypeError', $event->other['reason']);
        $this->assertSame($expectedorigin, $event->other['origin']);
        $this->assertStringNotContainsString(DIRECTORY_SEPARATOR, $event->other['origin']);
        $this->assertStringNotContainsString('bad type', json_encode($event->other));
    }

    /**
     * The origin is audited for Moodle exceptions too, without the directory.
     */
    public function test_origin_is_file_name_and_line(): void {
        $exception = new \LogicException('x');
        $this->assertSame(basename(__FILE__) . ':' . $exception->getLine(), generation_error_handler::origin($exception));
    }

    /**
     * Callers cannot override the audited keys through the extra event data.
     */
    public function test_extra_event_data_never_overrides_audited_keys(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $context = system::instance();
        $sink = $this->redirectEvents();

        generation_error_handler::handle_failure(new \LogicException('x'), $context, ['reason' => 'forged']);
        generation_error_handler::handle_denied(
            new required_capability_exception($context, 'moodle/course:create', 'nopermissions', ''),
            $context,
            ['capability' => 'forged']
        );
        $this->assertDebuggingCalledCount(2);

        $failed = $this->events_of_class($sink, generation_failed::class);
        $this->assertSame('LogicException', reset($failed)->other['reason']);
        $denied = $this->events_of_class($sink, generation_denied::class);
        $this->assertSame(get_capability_string('moodle/course:create'), reset($denied)->other['capability']);
    }
}
