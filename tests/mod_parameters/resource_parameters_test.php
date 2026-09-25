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

namespace local_coursegen\mod_parameters;

use local_coursegen\event\package_download_skipped;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/aiprovider_datacurso_stub.php');

/**
 * Unit tests for resource_parameters — provider failure must not abort module creation.
 *
 * Exactly one failure path runs per site, depending on whether the DataCurso
 * provider plugin is installed:
 * - provider installed: on a test site no provider instance is enabled, so the
 *   real ai_course_api constructor throws instance_disabled (client build fails);
 * - provider not installed: the stub fixture is used, its constructor succeeds
 *   and download_file() throws (download fails).
 * Both paths must end the same way: module created without its package, one
 * package_download_skipped event and one debugging notice.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\mod_parameters\resource_parameters
 * @covers \local_coursegen\mod_parameters\base_parameters
 * @covers \local_coursegen\event\package_download_skipped
 */
final class resource_parameters_test extends \advanced_testcase {
    /**
     * A provider failure is reported (event + debugging) and the parameters are
     * returned without the package reference instead of throwing.
     */
    public function test_provider_failure_degrades_gracefully(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = (object) [
            'name' => 'AI resource',
            'mod_settings' => ['file_path' => '/tmp/generated/package.zip', 'file_name' => 'package.zip'],
        ];

        $sink = $this->redirectEvents();
        $out = (new resource_parameters($params))->get_parameters();
        $events = $sink->get_events();
        $sink->close();

        $this->assertDebuggingCalledCount(1);
        $this->assertFalse(isset($out->files));
        $this->assertSame('AI resource', $out->name);

        $skipped = array_values(array_filter($events, static function ($event) {
            return $event instanceof package_download_skipped;
        }));
        $this->assertCount(1, $skipped);
        $event = reset($skipped);
        $this->assertSame('resource', $event->other['modname']);
        $this->assertSame('package.zip', $event->other['filename']);
        $this->assertNotSame('', $event->other['reason']);
        $this->assertLessThanOrEqual(package_download_skipped::REASON_MAX_LENGTH, strlen($event->other['reason']));
        // No course known: the event is logged against the system context.
        $this->assertEquals(\context_system::instance()->id, $event->contextid);
        $this->assertNotEmpty($event->get_description());
    }

    /**
     * When the target course is known the skip event is logged in the course context.
     */
    public function test_skip_event_uses_course_context_when_known(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $params = (object) [
            'course' => $course->id,
            'mod_settings' => ['file_path' => '/tmp/generated/package.zip', 'file_name' => 'package.zip'],
        ];

        $sink = $this->redirectEvents();
        (new resource_parameters($params))->get_parameters();
        $events = $sink->get_events();
        $sink->close();
        $this->resetDebugging();

        $skipped = array_values(array_filter($events, static function ($event) {
            return $event instanceof package_download_skipped;
        }));
        $this->assertCount(1, $skipped);
        $this->assertEquals(\context_course::instance($course->id)->id, reset($skipped)->contextid);
    }

    /**
     * Missing package info is a payload contract error and still surfaces as an exception.
     */
    public function test_missing_package_info_throws(): void {
        $this->resetAfterTest();

        $this->expectException(\moodle_exception::class);
        (new resource_parameters((object) ['mod_settings' => []]))->get_parameters();
    }
}
