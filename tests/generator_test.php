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

use core\context\system;
use core\exception\coding_exception;
use local_coursegen\local\models\course_context;
use local_coursegen\local\models\course_session;

/**
 * Tests for the local_coursegen data generator.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen_generator
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen_generator::class)]
final class generator_test extends \advanced_testcase {
    /**
     * Get the plugin generator.
     *
     * @return \local_coursegen_generator
     */
    private function generator(): \local_coursegen_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_coursegen');
    }

    /**
     * A seeded system instruction is stored as a visible guideline row.
     */
    public function test_create_system_instruction(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $instruction = $this->generator()->create_system_instruction([
            'name' => 'Quality policy',
            'content' => 'All courses must include a welcome forum.',
        ]);

        $record = $DB->get_record('local_coursegen_system_instruction', ['id' => $instruction->id], '*', MUST_EXIST);
        $this->assertSame('Quality policy', $record->name);
        $this->assertSame('All courses must include a welcome forum.', $record->content);
        $this->assertEquals(0, $record->deleted);
        $this->assertEquals(get_admin()->id, $record->usermodified);
    }

    /**
     * Explicit timestamps and owner of a system instruction are stored as given.
     */
    public function test_create_system_instruction_keeps_explicit_timestamps(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $owner = $this->getDataGenerator()->create_user();

        $instruction = $this->generator()->create_system_instruction([
            'name' => 'Dated policy',
            'usermodified' => $owner->id,
            'timecreated' => 100,
            'timemodified' => 200,
        ]);

        $record = $DB->get_record('local_coursegen_system_instruction', ['id' => $instruction->id], '*', MUST_EXIST);
        $this->assertEquals(100, $record->timecreated);
        $this->assertEquals(200, $record->timemodified);
        $this->assertEquals($owner->id, $record->usermodified);
        $this->assertEquals(100, $instruction->timecreated);
    }

    /**
     * A system instruction needs a name.
     */
    public function test_create_system_instruction_requires_name(): void {
        $this->resetAfterTest();

        $this->expectException(coding_exception::class);
        $this->generator()->create_system_instruction([]);
    }

    /**
     * A course session defaults to a pending session of the current user with planning course data.
     */
    public function test_create_course_session_defaults(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $session = $this->generator()->create_course_session();

        $record = $DB->get_record('local_coursegen_course_sessions', ['id' => $session->get('id')], '*', MUST_EXIST);
        $this->assertEquals($user->id, $record->userid);
        $this->assertNull($record->courseid);
        $this->assertEquals(course_session::STATUS_PENDING, $record->status);
        $this->assertStringStartsWith('sess_', $record->session_id);
        $this->assertSame($record->session_id, $session->get('session_id'));
        $coursedata = json_decode($record->coursedata, true);
        $this->assertSame('customprompt', $coursedata['local_coursegen_context_type']);
        $this->assertArrayHasKey('local_coursegen_custom_prompt', $coursedata);
        $this->assertGreaterThan(0, $record->timecreated);
    }

    /**
     * Every course session column can be overridden, including the timestamps.
     */
    public function test_create_course_session_overrides(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $owner = $generator->create_user();
        $course = $generator->create_course();

        $session = $this->generator()->create_course_session([
            'userid' => $owner->id,
            'courseid' => $course->id,
            'session_id' => 'thread-42',
            'status' => course_session::STATUS_CREATED,
            'coursedata' => ['fullname' => 'Planned course'],
            'timecreated' => 1000,
            'timemodified' => 2000,
        ]);

        $record = $DB->get_record('local_coursegen_course_sessions', ['id' => $session->get('id')], '*', MUST_EXIST);
        $this->assertEquals($owner->id, $record->userid);
        $this->assertEquals($course->id, $record->courseid);
        $this->assertSame('thread-42', $record->session_id);
        $this->assertEquals(course_session::STATUS_CREATED, $record->status);
        $this->assertSame(['fullname' => 'Planned course'], json_decode($record->coursedata, true));
        $this->assertEquals(1000, $record->timecreated);
        $this->assertEquals(2000, $record->timemodified);
        $this->assertEquals(1000, $session->get('timecreated'));
    }

    /**
     * A course session needs an owner when nobody is logged in.
     */
    public function test_create_course_session_requires_user(): void {
        $this->resetAfterTest();
        $this->setUser(null);

        $this->expectException(coding_exception::class);
        $this->generator()->create_course_session();
    }

    /**
     * A module job is stored for the course and current user with an execution status.
     */
    public function test_create_module_job(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->setUser($user);

        $job = $this->generator()->create_module_job(['courseid' => $course->id]);

        $record = $DB->get_record('local_coursegen_module_jobs', ['id' => $job->get('id')], '*', MUST_EXIST);
        $this->assertEquals($course->id, $record->courseid);
        $this->assertEquals($user->id, $record->userid);
        $this->assertStringStartsWith('job_', $record->job_id);
        $this->assertSame('execution_started', $record->status);
        $this->assertEquals(0, $record->generate_images);
        $this->assertNull($record->context_type);
        $this->assertNull($record->sectionnum);

        $custom = $this->generator()->create_module_job([
            'courseid' => $course->id,
            'job_id' => 'job-custom',
            'status' => 'completed',
            'generate_images' => 1,
            'context_type' => course_context::CONTEXT_TYPE_CUSTOM_PROMPT,
            'system_instruction_name' => 'Guideline',
            'sectionnum' => 2,
            'beforemod' => 7,
        ]);
        $record = $DB->get_record('local_coursegen_module_jobs', ['id' => $custom->get('id')], '*', MUST_EXIST);
        $this->assertSame('job-custom', $record->job_id);
        $this->assertSame('completed', $record->status);
        $this->assertEquals(1, $record->generate_images);
        $this->assertSame(course_context::CONTEXT_TYPE_CUSTOM_PROMPT, $record->context_type);
        $this->assertSame('Guideline', $record->system_instruction_name);
        $this->assertEquals(2, $record->sectionnum);
        $this->assertEquals(7, $record->beforemod);
    }

    /**
     * A module job needs a course.
     */
    public function test_create_module_job_requires_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(coding_exception::class);
        $this->generator()->create_module_job();
    }

    /**
     * A course context row defaults to the syllabus type owned by the current user.
     */
    public function test_create_course_context(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $owner = $this->getDataGenerator()->create_user();
        $instruction = $this->generator()->create_system_instruction(['name' => 'Guideline']);

        $context = $this->generator()->create_course_context(['courseid' => $course->id]);

        $record = $DB->get_record('local_coursegen_course_context', ['id' => $context->get('id')], '*', MUST_EXIST);
        $this->assertEquals($course->id, $record->courseid);
        $this->assertSame(course_context::CONTEXT_TYPE_SYLLABUS, $record->context_type);
        $this->assertNull($record->system_instruction_id);
        $this->assertEquals(get_admin()->id, $record->usermodified);

        $custom = $this->generator()->create_course_context([
            'courseid' => $course->id,
            'context_type' => course_context::CONTEXT_TYPE_CUSTOM_PROMPT,
            'system_instruction_id' => $instruction->id,
            'lang' => 'es',
            'prompt_text' => 'Use formal language.',
            'usermodified' => $owner->id,
        ]);
        $record = $DB->get_record('local_coursegen_course_context', ['id' => $custom->get('id')], '*', MUST_EXIST);
        $this->assertSame(course_context::CONTEXT_TYPE_CUSTOM_PROMPT, $record->context_type);
        $this->assertEquals($instruction->id, $record->system_instruction_id);
        $this->assertSame('es', $record->lang);
        $this->assertSame('Use formal language.', $record->prompt_text);
        $this->assertEquals($owner->id, $record->usermodified);
        $this->assertEquals($owner->id, $custom->get('usermodified'));
    }

    /**
     * A course context needs a course and a known context type.
     */
    public function test_create_course_context_validates_input(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        try {
            $this->generator()->create_course_context();
            $this->fail('A course context without courseid must be rejected.');
        } catch (coding_exception $e) {
            $this->assertStringContainsString('courseid', $e->getMessage());
        }

        $this->expectException(coding_exception::class);
        $this->generator()->create_course_context(['courseid' => $course->id, 'context_type' => 'system_instruction']);
    }

    /**
     * A syllabus file is stored in the system context syllabus area under the session id.
     */
    public function test_create_syllabus_file(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $session = $this->generator()->create_course_session();
        $file = $this->generator()->create_syllabus_file($session, 'plan.pdf', '%PDF-1.4 plan');

        $this->assertSame('plan.pdf', $file->get_filename());
        $this->assertSame('%PDF-1.4 plan', $file->get_content());

        $files = get_file_storage()->get_area_files(
            system::instance()->id,
            'local_coursegen',
            'syllabus',
            (int) $session->get('id'),
            'id',
            false
        );
        $this->assertCount(1, $files);
        $this->assertSame($file->get_id(), reset($files)->get_id());

        $default = $this->generator()->create_syllabus_file($this->generator()->create_course_session());
        $this->assertSame('syllabus.pdf', $default->get_filename());
        $this->assertSame('%PDF-1.4 test', $default->get_content());
    }

    /**
     * A syllabus file needs a stored session.
     */
    public function test_create_syllabus_file_requires_stored_session(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(coding_exception::class);
        $this->generator()->create_syllabus_file(new course_session());
    }
}
