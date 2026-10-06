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
use local_coursegen\local\tenant_config;
use stdClass;

/**
 * Tests for the course_deleted and tenant_deleted observer cleanups.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\observer
 */
final class observer_test extends \advanced_testcase {
    /**
     * Deleting a course removes its coursegen rows and syllabus files, keeping other courses intact.
     */
    public function test_course_deleted_removes_plugin_data(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $course = $generator->create_course();
        $othercourse = $generator->create_course();

        $sessionid = $this->insert_session($course->id, $user->id);
        $othersessionid = $this->insert_session($othercourse->id, $user->id);
        $this->insert_job($course->id, $user->id);
        $this->insert_job($othercourse->id, $user->id);
        $this->insert_course_context($course->id, $user->id);
        $this->insert_course_context($othercourse->id, $user->id);
        $this->create_syllabus_file($sessionid);
        $this->create_syllabus_file($othersessionid);

        delete_course($course->id, false);

        $this->assertSame(0, $DB->count_records('local_coursegen_course_sessions', ['courseid' => $course->id]));
        $this->assertSame(0, $DB->count_records('local_coursegen_module_jobs', ['courseid' => $course->id]));
        $this->assertSame(0, $DB->count_records('local_coursegen_course_context', ['courseid' => $course->id]));

        $fs = get_file_storage();
        $syscontextid = context_system::instance()->id;
        $this->assertEmpty($fs->get_area_files($syscontextid, 'local_coursegen', 'syllabus', $sessionid, 'id', false));

        // The other course keeps its rows and files.
        $this->assertSame(1, $DB->count_records('local_coursegen_course_sessions', ['courseid' => $othercourse->id]));
        $this->assertSame(1, $DB->count_records('local_coursegen_module_jobs', ['courseid' => $othercourse->id]));
        $this->assertSame(1, $DB->count_records('local_coursegen_course_context', ['courseid' => $othercourse->id]));
        $this->assertNotEmpty(
            $fs->get_area_files($syscontextid, 'local_coursegen', 'syllabus', $othersessionid, 'id', false)
        );
    }

    /**
     * Deleting a tenant removes its settings and instructions, keeping other tenants intact.
     */
    public function test_tenant_deleted_removes_tenant_data(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $tenantgenerator = $generator->get_plugin_generator('tool_tenant');
        $coursegen = $generator->get_plugin_generator('local_coursegen');
        $user = $generator->create_user();
        $tenanta = (int) $tenantgenerator->create_tenant()->id;
        $tenantb = (int) $tenantgenerator->create_tenant()->id;

        tenant_config::set('enablesubsections', 0, $tenanta);
        tenant_config::set('enablesubsections', 0, $tenantb);

        $asi = $coursegen->create_system_instruction(['name' => 'A rule', 'tenantid' => $tenanta]);
        $bsi = $coursegen->create_system_instruction(['name' => 'B rule', 'tenantid' => $tenantb]);

        $courseacontext = $this->insert_course_context($generator->create_course()->id, $user->id, (int) $asi->id);
        $coursebcontext = $this->insert_course_context($generator->create_course()->id, $user->id, (int) $bsi->id);

        $manager = new \tool_tenant\manager();
        $manager->archive_tenant($tenanta);
        $manager->delete_tenant($tenanta);

        // Tenant A data is gone.
        $this->assertSame(0, $DB->count_records('local_coursegen_tenant_config', ['tenantid' => $tenanta]));
        $this->assertSame(0, $DB->count_records('local_coursegen_system_instruction', ['tenantid' => $tenanta]));
        $this->assertNull($DB->get_field('local_coursegen_course_context', 'system_instruction_id', ['id' => $courseacontext]));
        $this->assertTrue($DB->record_exists('local_coursegen_course_context', ['id' => $courseacontext]));

        // Tenant B keeps its data.
        $this->assertSame(1, $DB->count_records('local_coursegen_tenant_config', ['tenantid' => $tenantb]));
        $this->assertTrue($DB->record_exists('local_coursegen_system_instruction', ['id' => $bsi->id]));
        $this->assertEquals(
            $bsi->id,
            $DB->get_field('local_coursegen_course_context', 'system_instruction_id', ['id' => $coursebcontext])
        );
    }

    /**
     * Insert a planning session row.
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @return int Session id.
     */
    private function insert_session(int $courseid, int $userid): int {
        global $DB;
        $record = new stdClass();
        $record->courseid = $courseid;
        $record->userid = $userid;
        $record->session_id = 'sess_' . bin2hex(random_bytes(4));
        $record->status = 1;
        $record->timecreated = time();
        $record->timemodified = time();
        return (int)$DB->insert_record('local_coursegen_course_sessions', $record);
    }

    /**
     * Insert a module job row.
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @return int Job record id.
     */
    private function insert_job(int $courseid, int $userid): int {
        global $DB;
        $record = new stdClass();
        $record->courseid = $courseid;
        $record->userid = $userid;
        $record->job_id = 'job_' . bin2hex(random_bytes(4));
        $record->status = 'execution_started';
        $record->generate_images = 0;
        $record->timecreated = time();
        $record->timemodified = time();
        return (int)$DB->insert_record('local_coursegen_module_jobs', $record);
    }

    /**
     * Insert a course context row.
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @param int|null $systeminstructionid Selected system instruction, if any.
     * @return int Record id.
     */
    private function insert_course_context(int $courseid, int $userid, ?int $systeminstructionid = null): int {
        global $DB;
        $record = new stdClass();
        $record->courseid = $courseid;
        $record->context_type = $systeminstructionid ? 'system_instruction' : 'syllabus';
        $record->system_instruction_id = $systeminstructionid;
        $record->timecreated = time();
        $record->timemodified = time();
        $record->usermodified = $userid;
        return (int)$DB->insert_record('local_coursegen_course_context', $record);
    }

    /**
     * Store a syllabus file in the system context for a planning session.
     *
     * @param int $sessionid Session id used as file item id.
     * @return void
     */
    private function create_syllabus_file(int $sessionid): void {
        $fs = get_file_storage();
        $fs->create_file_from_string((object) [
            'contextid' => context_system::instance()->id,
            'component' => 'local_coursegen',
            'filearea' => 'syllabus',
            'itemid' => $sessionid,
            'filepath' => '/',
            'filename' => 'syllabus.pdf',
        ], '%PDF-1.4 test syllabus');
    }
}
