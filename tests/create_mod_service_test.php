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
use core\context\course;
use core\exception\moodle_exception;
use local_coursegen\event\generation_warning;
use local_coursegen\local\api_client_factory;
use local_coursegen\local\service\create_mod_service;
use local_coursegen\local\warning_collector;
use local_coursegen\tests\api_testcase;

/**
 * Tests for create_mod_service: optional mod_settings and the non-fatal warning channel.
 *
 * Package-type creation is covered by h5p_create_from_ai_result_test. The AI HTTP client is
 * replaced through the api_client_factory seam, so no network request is performed.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\create_mod_service
 * @covers     \local_coursegen\event\generation_warning
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\local\service\create_mod_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\event\generation_warning::class)]
final class create_mod_service_test extends api_testcase {
    /**
     * Build a page activity result; mod_settings is omitted unless given.
     *
     * @param string $name Activity name.
     * @param array|null $modsettings Optional mod_settings payload.
     * @return array
     */
    private function page_result(string $name, ?array $modsettings = null): array {
        $parameters = [
            'modulename' => 'page',
            'name' => $name,
            'introeditor' => ['text' => '<p>Intro</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
            'page' => ['text' => '<p>Content</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
            'display' => 0,
            'printintro' => 1,
            'printlastmodified' => 1,
            'visible' => 1,
            'cmidnumber' => '',
        ];
        if ($modsettings !== null) {
            $parameters['mod_settings'] = $modsettings;
        }

        return ['resource_type' => 'page', 'parameters' => $parameters];
    }

    /**
     * Filter sink events by class.
     *
     * @param \phpunit_event_sink $sink Event sink.
     * @return generation_warning[]
     */
    private function warning_events(\phpunit_event_sink $sink): array {
        return array_values(array_filter($sink->get_events(), static function ($event): bool {
            return $event instanceof generation_warning;
        }));
    }

    /**
     * A result without mod_settings (modules with no extra settings) creates the module
     * without a notice and without warnings.
     */
    public function test_payload_without_mod_settings_creates_module_without_warnings(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->set_current_course($course);

        $created = create_mod_service::create_from_ai_result_with_warnings($this->page_result('Reading'), $course, 1);
        $this->assertDebuggingNotCalled();

        $this->assertSame([], $created['warnings']);
        $this->assertSame('page', $created['cm']->modulename);
        $this->assertTrue($DB->record_exists('page', ['course' => $course->id, 'name' => 'Reading']));
    }

    /**
     * The plain creation entry point keeps returning the course module.
     */
    public function test_create_from_ai_result_returns_course_module(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->set_current_course($course);

        $newcm = create_mod_service::create_from_ai_result($this->page_result('Plain', []), $course, 1);
        $this->assertDebuggingNotCalled();

        $this->assertTrue($DB->record_exists('course_modules', ['id' => $newcm->coursemodule, 'course' => $course->id]));
    }

    /**
     * Missing resource_type or parameters are rejected with the plugin's localized exceptions.
     */
    public function test_invalid_result_is_rejected_with_moodle_exception(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        foreach (['resource_type' => 'error_missing_resource_type', 'parameters' => 'error_missing_parameters'] as $k => $c) {
            $result = $this->page_result('Broken');
            unset($result[$k]);
            try {
                create_mod_service::create_from_ai_result($result, $course, 1);
                $this->fail("A result without {$k} must be rejected.");
            } catch (moodle_exception $e) {
                $this->assertSame($c, $e->errorcode);
                $this->assertSame('local_coursegen', $e->module);
            }
        }
    }

    /**
     * A folder document that cannot be downloaded is reported as a warning (and audited) while the
     * folder itself is still created; the next activity starts with a clean warning list.
     */
    public function test_folder_download_failure_is_reported_as_warning(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->set_current_course($course);

        $mock = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['download_file'])
            ->getMock();
        $mock->method('download_file')->willThrowException(
            new moodle_exception('curlerror', 'aiprovider_datacurso', '', 'Connection refused')
        );
        api_client_factory::set_test_client($mock);

        $result = [
            'resource_type' => 'folder',
            'parameters' => [
                'modulename' => 'folder',
                'name' => 'Course documents',
                'introeditor' => ['text' => '<p>Documents</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                'display' => 0,
                'showexpanded' => 1,
                'showdownloadfolder' => 1,
                'forcedownload' => 1,
                'visible' => 1,
                'cmidnumber' => '',
                'mod_settings' => ['files' => [
                    ['file_path' => '/tmp/out/notes.pdf', 'file_name' => 'notes.pdf', 'folder_path' => ''],
                ]],
            ],
        ];

        $sink = $this->redirectEvents();
        $created = create_mod_service::create_from_ai_result_with_warnings($result, $course, 1);
        $this->assertDebuggingCalledCount(1);

        // The folder exists, empty.
        $this->assertTrue($DB->record_exists('folder', ['course' => $course->id, 'name' => 'Course documents']));

        // The client gets the localized message naming the file; the raw reason stays out of it.
        $this->assertSame(
            [get_string('generationwarning_folder_file', 'local_coursegen', 'notes.pdf')],
            $created['warnings']
        );
        $this->assertStringNotContainsString('Connection refused', $created['warnings'][0]);

        $events = $this->warning_events($sink);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertEquals(course::instance($course->id)->id, $event->get_context()->id);
        $this->assertSame('folder', $event->other['modname']);
        $this->assertSame(warning_collector::STEP_FOLDER_FILE, $event->other['step']);
        $this->assertSame('notes.pdf', $event->other['subject']);
        $this->assertStringContainsString('Connection refused', $event->other['reason']);

        // Warnings are request-scoped: the next activity does not inherit them.
        $next = create_mod_service::create_from_ai_result_with_warnings($this->page_result('After'), $course, 1);
        $this->assertDebuggingNotCalled();
        $this->assertSame([], $next['warnings']);
        $this->assertCount(1, $this->warning_events($sink));
    }

    /**
     * A database field of an unknown type is skipped with a warning (and audited) while the
     * database and its valid fields are still created.
     */
    public function test_settings_step_failure_is_reported_as_warning(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->set_current_course($course);

        $result = [
            'resource_type' => 'data',
            'parameters' => [
                'modulename' => 'data',
                'name' => 'Book reviews',
                'introeditor' => ['text' => '<p>Reviews</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                'timeavailablefrom' => 0,
                'timeavailableto' => 0,
                'timeviewfrom' => 0,
                'timeviewto' => 0,
                'assessed' => 0,
                'scale' => 0,
                'visible' => 1,
                'cmidnumber' => '',
                'mod_settings' => ['fields' => [
                    ['type' => 'text', 'name' => 'Title'],
                    ['type' => 'bogustype', 'name' => 'Odd'],
                ]],
            ],
        ];

        $sink = $this->redirectEvents();
        $created = create_mod_service::create_from_ai_result_with_warnings($result, $course, 1);
        $this->assertDebuggingCalledCount(1);

        $data = $DB->get_record('data', ['course' => $course->id, 'name' => 'Book reviews'], '*', MUST_EXIST);
        $fields = $DB->get_records('data_fields', ['dataid' => $data->id]);
        $this->assertCount(1, $fields);
        $this->assertSame('Title', reset($fields)->name);

        $this->assertSame(
            [get_string('generationwarning_data_field', 'local_coursegen', 'Odd')],
            $created['warnings']
        );

        $events = $this->warning_events($sink);
        $this->assertCount(1, $events);
        $this->assertSame('data', $events[0]->other['modname']);
        $this->assertSame(warning_collector::STEP_DATA_FIELD, $events[0]->other['step']);
        $this->assertSame('Odd', $events[0]->other['subject']);
        $this->assertNotSame('', $events[0]->other['reason']);
    }

    /**
     * Warnings collected before a later step throws are still audited, and the exception keeps
     * propagating: a generated image fails to download (warning), then the package-contract check
     * rejects the result.
     *
     * Runs in its own process because the editor cleaner caches its AI client for the whole process.
     *
     * @runInSeparateProcess
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function test_warnings_are_audited_when_creation_throws(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->set_current_course($course);

        $mock = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['download_file'])
            ->getMock();
        $mock->method('download_file')->willThrowException(
            new moodle_exception('curlerror', 'aiprovider_datacurso', '', 'Connection refused')
        );
        api_client_factory::set_test_client($mock);

        // The image reference makes the cleaner download (and fail: warning); the package
        // fields on a module without parameters handler make the creation fail afterwards.
        $result = $this->page_result('Broken page', ['file_path' => 'generated/pkg.zip', 'file_name' => 'pkg.zip']);
        $result['parameters']['introeditor']['text'] = '<p><img src="/tmp/generated_images/diagram.png" alt="d"></p>';

        $sink = $this->redirectEvents();
        try {
            create_mod_service::create_from_ai_result_with_warnings($result, $course, 1);
            $this->fail('The package-contract rejection must propagate.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_parameters_handler_unresolved', $e->errorcode);
        }
        $this->assertDebuggingCalledCount(1);

        // Nothing was created, yet the warning was audited in the course context.
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $course->id]));
        $events = $this->warning_events($sink);
        $this->assertCount(1, $events);
        $this->assertEquals(course::instance($course->id)->id, $events[0]->get_context()->id);
        $this->assertSame('page', $events[0]->other['modname']);
        $this->assertSame(warning_collector::STEP_IMAGE_DOWNLOAD, $events[0]->other['step']);
        $this->assertSame('diagram.png', $events[0]->other['subject']);
    }

    /**
     * A settings handler keeps its recorded warnings reachable after a later step throws (the
     * contract create_from_ai_result_with_warnings() relies on to audit them in its finally block).
     */
    public function test_settings_handler_keeps_warnings_after_a_later_step_throws(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->set_current_course($course);

        $handler = new class ((object) [], []) extends \local_coursegen\mod_settings\base_settings {
            /**
             * Record a warning, then fail outside attempt().
             */
            public function add_settings() {
                $this->attempt(static function (): void {
                    throw new \RuntimeException('first step failed');
                }, warning_collector::STEP_DATA_ENTRY);
                throw new \LogicException('second step exploded');
            }
        };

        // Exercise the same contract create_mod_service relies on: the handler keeps its
        // warnings reachable after add_settings() threw.
        try {
            $handler->add_settings();
            $this->fail('The second step must propagate.');
        } catch (\LogicException $e) {
            $this->assertSame('second step exploded', $e->getMessage());
        }
        $this->assertDebuggingCalledCount(1);
        $this->assertCount(1, $handler->get_warnings());
        $this->assertSame(warning_collector::STEP_DATA_ENTRY, $handler->get_warnings()[0]['step']);
    }

    /**
     * The warning event validates its payload keys.
     */
    public function test_generation_warning_event_requires_its_keys(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $event = generation_warning::create_from_warning(
            \core\context\system::instance(),
            'quiz',
            ['step' => warning_collector::STEP_QUIZ_QUESTION, 'subject' => '<b>Q1</b>', 'reason' => str_repeat('r', 300)]
        );
        $this->assertSame(warning_collector::REASON_MAX_LENGTH, \core_text::strlen($event->other['reason']));
        $this->assertSame('Q1', $event->other['subject']);
        $this->assertStringContainsString("'quiz'", $event->get_description());
        $this->assertStringContainsString("('Q1')", $event->get_description());
        $this->assertSame(get_string('event_generation_warning', 'local_coursegen'), generation_warning::get_name());

        $this->expectException(\core\exception\coding_exception::class);
        generation_warning::create([
            'context' => \core\context\system::instance(),
            'other' => ['modname' => 'quiz', 'step' => 'x', 'reason' => 'r'],
        ]);
    }
}
