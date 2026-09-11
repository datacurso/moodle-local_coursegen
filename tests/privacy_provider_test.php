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

use context_course;
use context_system;
use context_user;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_coursegen\privacy\provider;
use stdClass;

/**
 * Tests for Course Creator AI
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class privacy_provider_test extends provider_testcase {
    /**
     * Tests set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The metadata declares the four plugin tables, the coursedata field and the external service link.
     *
     * @covers \local_coursegen\privacy\provider::get_metadata
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('local_coursegen'));
        $items = $collection->get_collection();

        $tables = [];
        $links = [];
        foreach ($items as $item) {
            if ($item instanceof \core_privacy\local\metadata\types\database_table) {
                $tables[$item->get_name()] = $item;
            } else if ($item instanceof \core_privacy\local\metadata\types\external_location) {
                $links[$item->get_name()] = $item;
            }
        }

        $this->assertArrayHasKey('local_coursegen_system_instruction', $tables);
        $this->assertArrayHasKey('local_coursegen_course_context', $tables);
        $this->assertArrayHasKey('local_coursegen_course_sessions', $tables);
        $this->assertArrayHasKey('local_coursegen_module_jobs', $tables);

        // The free-form prompt stored in the session must be declared.
        $sessionfields = $tables['local_coursegen_course_sessions']->get_privacy_fields();
        $this->assertArrayHasKey('coursedata', $sessionfields);

        // The external Datacurso course service link must declare the data actually sent.
        $this->assertArrayHasKey('datacurso_course_service', $links);
        $linkfields = $links['datacurso_course_service']->get_privacy_fields();
        $expected = ['prompt', 'instructions', 'syllabus_file', 'lang', 'with_images', 'userid', 'site_id', 'site_url',
            'timezone'];
        foreach ($expected as $field) {
            $this->assertArrayHasKey($field, $linkfields);
        }
    }

    /**
     * Check that user and course contexts are returned if there is any user data for this user.
     *
     * @covers \local_coursegen\privacy\provider::get_contexts_for_userid
     */
    public function test_get_contexts_for_userid(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->assertEmpty(provider::get_contexts_for_userid($user->id));

        // Create user records.
        $records = $this->create_userdata($user->id);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $this->assertCount(2, $contextlist);

        $usercontext = context_user::instance($user->id);
        $coursecontext = context_course::instance($records['local_coursegen_course_sessions']->courseid);
        $this->assertContainsEquals($usercontext->id, $contextlist->get_contextids());
        $this->assertContainsEquals($coursecontext->id, $contextlist->get_contextids());
    }

    /**
     * Test that only users with a user context are fetched.
     *
     * @covers \local_coursegen\privacy\provider::get_users_in_context
     */
    public function test_get_users_in_context(): void {
        $component = 'local_coursegen';
        $user = $this->getDataGenerator()->create_user();
        $usercontext = context_user::instance($user->id);

        $userlist = new userlist($usercontext, $component);
        provider::get_users_in_context($userlist);
        $this->assertCount(0, $userlist);

        // Create user records.
        $this->create_userdata($user->id);

        provider::get_users_in_context($userlist);
        $this->assertCount(1, $userlist);
        $expected = [$user->id];
        $actual = $userlist->get_userids();
        $this->assertEquals($expected, $actual);

        // The list of users for system context should not return any users.
        $userlist = new userlist(context_system::instance(), $component);
        provider::get_users_in_context($userlist);
        $this->assertCount(0, $userlist);
    }

    /**
     * Test that user data is exported correctly.
     *
     * @covers \local_coursegen\privacy\provider::export_user_data
     */
    public function test_export_user_data(): void {
        $user = $this->getDataGenerator()->create_user();
        $userrecords = $this->create_userdata($user->id);

        $usercontext = context_user::instance($user->id);
        $writer = writer::with_context($usercontext);
        $this->assertFalse($writer->has_any_data());

        $approvedlist = new approved_contextlist($user, 'local_coursegen', [$usercontext->id]);
        provider::export_user_data($approvedlist);

        foreach ($userrecords as $table => $record) {
            $data = $writer->get_data([
                get_string('privacy:metadata:local_coursegen', 'local_coursegen'),
                get_string('privacy:metadata:' . $table, 'local_coursegen'),
            ]);
            foreach ($record as $k => $v) {
                // Values are written as scalars; cast for comparison where needed.
                $this->assertEquals((string)$v, isset($data->$k) ? (string)$data->$k : null);
            }
        }
    }

    /**
     * Test deleting all user data for a specific context.
     *
     * @covers \local_coursegen\privacy\provider::delete_data_for_all_users_in_context
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        $user1 = $this->getDataGenerator()->create_user();
        $records1 = $this->create_userdata($user1->id);
        $user1context = context_user::instance($user1->id);

        $user2 = $this->getDataGenerator()->create_user();
        $records2 = $this->create_userdata($user2->id);

        // There should be one record per user in each table.
        $userfieldmap = [
            'local_coursegen_system_instruction' => 'usermodified',
            'local_coursegen_course_context' => 'usermodified',
            'local_coursegen_course_sessions' => 'userid',
            'local_coursegen_module_jobs' => 'userid',
        ];
        foreach (array_keys($records1) as $table) {
            $field = $userfieldmap[$table];
            $this->assertCount(1, $DB->get_records($table, [$field => $user1->id]));
            $this->assertCount(1, $DB->get_records($table, [$field => $user2->id]));
        }

        provider::delete_data_for_all_users_in_context($user1context);

        // Personal rows are really deleted.
        $this->assertCount(0, $DB->get_records('local_coursegen_course_sessions', ['userid' => $user1->id]));
        $this->assertCount(0, $DB->get_records('local_coursegen_module_jobs', ['userid' => $user1->id]));

        // Shared configuration rows persist but are anonymized (usermodified reset).
        $this->assertCount(0, $DB->get_records('local_coursegen_system_instruction', ['usermodified' => $user1->id]));
        $this->assertCount(0, $DB->get_records('local_coursegen_course_context', ['usermodified' => $user1->id]));
        $instruction = $DB->get_record(
            'local_coursegen_system_instruction',
            ['id' => $records1['local_coursegen_system_instruction']->id],
            '*',
            MUST_EXIST
        );
        $this->assertEquals(0, $instruction->usermodified);
        $coursecontext = $DB->get_record(
            'local_coursegen_course_context',
            ['id' => $records1['local_coursegen_course_context']->id],
            '*',
            MUST_EXIST
        );
        $this->assertEquals(0, $coursecontext->usermodified);

        // Ensure records for user2 remain intact (one per table for user2).
        foreach (array_keys($records1) as $table) {
            $field = $userfieldmap[$table];
            $this->assertCount(1, $DB->get_records($table, [$field => $user2->id]));
        }
    }

    /**
     * Test deleting user data via approved context list.
     *
     * @covers \local_coursegen\privacy\provider::delete_data_for_user
     */
    public function test_delete_data_for_user(): void {
        global $DB;

        $user1 = $this->getDataGenerator()->create_user();
        $records1 = $this->create_userdata($user1->id);
        $user1context = context_user::instance($user1->id);

        $user2 = $this->getDataGenerator()->create_user();
        $records2 = $this->create_userdata($user2->id);

        // There should be one record per user in each table.
        $userfieldmap = [
            'local_coursegen_system_instruction' => 'usermodified',
            'local_coursegen_course_context' => 'usermodified',
            'local_coursegen_course_sessions' => 'userid',
            'local_coursegen_module_jobs' => 'userid',
        ];
        foreach (array_keys($records1) as $table) {
            $field = $userfieldmap[$table];
            $this->assertCount(1, $DB->get_records($table, [$field => $user1->id]));
            $this->assertCount(1, $DB->get_records($table, [$field => $user2->id]));
        }

        $approvedlist = new approved_contextlist($user1, 'local_coursegen', [$user1context->id]);
        provider::delete_data_for_user($approvedlist);

        // Personal rows are really deleted.
        $this->assertCount(0, $DB->get_records('local_coursegen_course_sessions', ['userid' => $user1->id]));
        $this->assertCount(0, $DB->get_records('local_coursegen_module_jobs', ['userid' => $user1->id]));

        // Shared configuration rows persist but are anonymized (usermodified reset).
        $this->assertCount(0, $DB->get_records('local_coursegen_system_instruction', ['usermodified' => $user1->id]));
        $this->assertCount(0, $DB->get_records('local_coursegen_course_context', ['usermodified' => $user1->id]));
        $instruction = $DB->get_record(
            'local_coursegen_system_instruction',
            ['id' => $records1['local_coursegen_system_instruction']->id],
            '*',
            MUST_EXIST
        );
        $this->assertEquals(0, $instruction->usermodified);
        $coursecontext = $DB->get_record(
            'local_coursegen_course_context',
            ['id' => $records1['local_coursegen_course_context']->id],
            '*',
            MUST_EXIST
        );
        $this->assertEquals(0, $coursecontext->usermodified);

        // Ensure records for user2 remain intact (one per table for user2).
        foreach (array_keys($records1) as $table) {
            $field = $userfieldmap[$table];
            $this->assertCount(1, $DB->get_records($table, [$field => $user2->id]));
        }
    }

    /**
     * Test that data for users in approved userlist is deleted.
     *
     * @covers \local_coursegen\privacy\provider::delete_data_for_users
     */
    public function test_delete_data_for_users(): void {
        $component = 'local_coursegen';

        // Create user 1 and user 2 with data.
        $user1 = $this->getDataGenerator()->create_user();
        $this->create_userdata($user1->id);
        $usercontext1 = context_user::instance($user1->id);

        $user2 = $this->getDataGenerator()->create_user();
        $this->create_userdata($user2->id);
        $usercontext2 = context_user::instance($user2->id);

        // Verify userlist for each context has the correct user.
        $userlist1 = new userlist($usercontext1, $component);
        provider::get_users_in_context($userlist1);
        $this->assertCount(1, $userlist1);
        $this->assertEquals([$user1->id], $userlist1->get_userids());

        $userlist2 = new userlist($usercontext2, $component);
        provider::get_users_in_context($userlist2);
        $this->assertCount(1, $userlist2);
        $this->assertEquals([$user2->id], $userlist2->get_userids());

        // Approve deletion for userlist1 and perform deletion.
        $approvedlist = new \core_privacy\local\request\approved_userlist($usercontext1, $component, $userlist1->get_userids());
        provider::delete_data_for_users($approvedlist);

        // Re-fetch users for context1: should be empty now.
        $userlist1 = new userlist($usercontext1, $component);
        provider::get_users_in_context($userlist1);
        $this->assertCount(0, $userlist1);

        // System context should not affect user2.
        $systemcontext = context_system::instance();
        $approvedlist = new \core_privacy\local\request\approved_userlist($systemcontext, $component, $userlist2->get_userids());
        provider::delete_data_for_users($approvedlist);

        // User2 still present in their user context.
        $userlist2 = new userlist($usercontext2, $component);
        provider::get_users_in_context($userlist2);
        $this->assertCount(1, $userlist2);
    }

    /**
     * Users with sessions or jobs in a course are listed for that course context.
     *
     * @covers \local_coursegen\privacy\provider::get_users_in_context
     */
    public function test_get_users_in_context_for_course_context(): void {
        $component = 'local_coursegen';
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $records = $this->create_userdata($user->id);
        $coursecontext = context_course::instance($records['local_coursegen_course_sessions']->courseid);

        $userlist = new userlist($coursecontext, $component);
        provider::get_users_in_context($userlist);
        $this->assertEquals([$user->id], $userlist->get_userids());

        // A user without rows in the course is not listed.
        $this->assertNotContains((int)$other->id, $userlist->get_userids());
    }

    /**
     * The export includes the syllabus files stored for the user's planning sessions.
     *
     * @covers \local_coursegen\privacy\provider::export_user_data
     */
    public function test_export_user_data_includes_syllabus_files(): void {
        $user = $this->getDataGenerator()->create_user();
        $records = $this->create_userdata($user->id);
        $sessionid = (int)$records['local_coursegen_course_sessions']->id;
        $this->create_syllabus_file($sessionid);

        $usercontext = context_user::instance($user->id);
        $approvedlist = new approved_contextlist($user, 'local_coursegen', [$usercontext->id]);
        provider::export_user_data($approvedlist);

        $writer = writer::with_context($usercontext);
        $files = $writer->get_files([
            get_string('privacy:metadata:local_coursegen', 'local_coursegen'),
            get_string('privacy:metadata:local_coursegen_course_sessions', 'local_coursegen'),
        ]);
        $this->assertArrayHasKey('syllabus.pdf', $files);
    }

    /**
     * Session and job rows of a course are exported under that course context.
     *
     * @covers \local_coursegen\privacy\provider::export_user_data
     */
    public function test_export_user_data_for_course_context(): void {
        $user = $this->getDataGenerator()->create_user();
        $records = $this->create_userdata($user->id);
        $coursecontext = context_course::instance($records['local_coursegen_course_sessions']->courseid);

        $approvedlist = new approved_contextlist($user, 'local_coursegen', [$coursecontext->id]);
        provider::export_user_data($approvedlist);

        $writer = writer::with_context($coursecontext);
        $this->assertTrue($writer->has_any_data());
    }

    /**
     * Deleting user data also removes the stored syllabus files of their sessions.
     *
     * @covers \local_coursegen\privacy\provider::delete_data_for_all_users_in_context
     */
    public function test_delete_user_data_removes_syllabus_files(): void {
        $user = $this->getDataGenerator()->create_user();
        $records = $this->create_userdata($user->id);
        $sessionid = (int)$records['local_coursegen_course_sessions']->id;
        $this->create_syllabus_file($sessionid);

        $fs = get_file_storage();
        $syscontextid = context_system::instance()->id;
        $this->assertNotEmpty($fs->get_area_files($syscontextid, 'local_coursegen', 'syllabus', $sessionid, 'id', false));

        provider::delete_data_for_all_users_in_context(context_user::instance($user->id));

        $this->assertEmpty($fs->get_area_files($syscontextid, 'local_coursegen', 'syllabus', $sessionid, 'id', false));
    }

    /**
     * Deleting a whole course context removes sessions and jobs of every user for that course only.
     *
     * @covers \local_coursegen\privacy\provider::delete_data_for_all_users_in_context
     */
    public function test_delete_data_for_all_users_in_course_context(): void {
        global $DB;

        $user1 = $this->getDataGenerator()->create_user();
        $records1 = $this->create_userdata($user1->id);
        $courseid = (int)$records1['local_coursegen_course_sessions']->courseid;

        // Second user with rows in the same course.
        $user2 = $this->getDataGenerator()->create_user();
        $session2 = $this->create_course_session($courseid, $user2->id);
        $this->create_syllabus_file((int)$session2->id);
        $this->create_module_job($courseid, $user2->id);

        // A third user with data in another course must not be affected.
        $user3 = $this->getDataGenerator()->create_user();
        $records3 = $this->create_userdata($user3->id);

        provider::delete_data_for_all_users_in_context(context_course::instance($courseid));

        $this->assertCount(0, $DB->get_records('local_coursegen_course_sessions', ['courseid' => $courseid]));
        $this->assertCount(0, $DB->get_records('local_coursegen_module_jobs', ['courseid' => $courseid]));

        // The syllabus file of the deleted session is gone.
        $fs = get_file_storage();
        $syscontextid = context_system::instance()->id;
        $this->assertEmpty(
            $fs->get_area_files($syscontextid, 'local_coursegen', 'syllabus', (int)$session2->id, 'id', false)
        );

        // Shared configuration for the course persists, anonymized.
        $coursecontextrow = $DB->get_record(
            'local_coursegen_course_context',
            ['id' => $records1['local_coursegen_course_context']->id],
            '*',
            MUST_EXIST
        );
        $this->assertEquals(0, $coursecontextrow->usermodified);

        // The other course keeps its data.
        $othercourseid = (int)$records3['local_coursegen_course_sessions']->courseid;
        $this->assertCount(1, $DB->get_records('local_coursegen_course_sessions', ['courseid' => $othercourseid]));
        $this->assertCount(1, $DB->get_records('local_coursegen_module_jobs', ['courseid' => $othercourseid]));
    }

    /**
     * Deleting approved users in a course context removes only those users' rows in that course.
     *
     * @covers \local_coursegen\privacy\provider::delete_data_for_users
     */
    public function test_delete_data_for_users_in_course_context(): void {
        global $DB;

        $user1 = $this->getDataGenerator()->create_user();
        $records1 = $this->create_userdata($user1->id);
        $courseid = (int)$records1['local_coursegen_course_sessions']->courseid;

        $user2 = $this->getDataGenerator()->create_user();
        $this->create_course_session($courseid, $user2->id);
        $this->create_module_job($courseid, $user2->id);

        $coursecontext = context_course::instance($courseid);
        $approvedlist = new \core_privacy\local\request\approved_userlist($coursecontext, 'local_coursegen', [$user1->id]);
        provider::delete_data_for_users($approvedlist);

        $this->assertCount(0, $DB->get_records('local_coursegen_course_sessions', ['userid' => $user1->id]));
        $this->assertCount(0, $DB->get_records('local_coursegen_module_jobs', ['userid' => $user1->id]));
        $this->assertCount(1, $DB->get_records('local_coursegen_course_sessions', ['userid' => $user2->id]));
        $this->assertCount(1, $DB->get_records('local_coursegen_module_jobs', ['userid' => $user2->id]));
    }

    /**
     * Create user-related data across local_coursegen tables.
     *
     * @param int $userid
     * @return array<string, stdClass> insert records per table keyed by table name
     */
    private function create_userdata(int $userid): array {
        $course = $this->course();
        $systeminstruction = $this->create_system_instruction($userid);
        $context = $this->create_course_context($course->id, $systeminstruction->id, $userid);
        $session = $this->create_course_session($course->id, $userid);
        $job = $this->create_module_job($course->id, $userid);

        return [
            'local_coursegen_system_instruction' => $systeminstruction,
            'local_coursegen_course_context' => $context,
            'local_coursegen_course_sessions' => $session,
            'local_coursegen_module_jobs' => $job,
        ];
    }

    /**
     * Create a course.
     *
     * @return stdClass
     */
    private function course(): stdClass {
        return get_course($this->course_rec()->id);
    }

    /**
     * Create a course record via generator and return its record (helper).
     *
     * @return stdClass
     */
    private function course_rec(): stdClass {
        return $this->getDataGenerator()->create_course();
    }

    /**
     * Create a system instruction (local_coursegen_system_instruction) for a user.
     *
     * @param int $userid
     * @return stdClass
     */
    private function create_system_instruction(int $userid): stdClass {
        global $DB;
        $record = new stdClass();
        $record->name = 'Test system instruction';
        $record->content = 'System instruction content';
        $record->deleted = 0;
        $record->timecreated = time();
        $record->timemodified = time();
        $record->usermodified = $userid;
        $record->id = $DB->insert_record('local_coursegen_system_instruction', $record);
        return $record;
    }

    /**
     * Create a course_context record.
     *
     * @param int $courseid
     * @param int $systeminstructionid
     * @param int $userid
     * @return stdClass
     */
    private function create_course_context(int $courseid, int $systeminstructionid, int $userid): stdClass {
        global $DB;
        $record = new stdClass();
        $record->courseid = $courseid;
        $record->context_type = ai_context::CONTEXT_TYPE_SYSTEM_INSTRUCTION;
        $record->system_instruction_id = $systeminstructionid;
        $record->timecreated = time();
        $record->timemodified = time();
        $record->usermodified = $userid;
        $record->id = $DB->insert_record('local_coursegen_course_context', $record);
        return $record;
    }

    /**
     * Create a course_session record.
     *
     * @param int $courseid
     * @param int $userid
     * @return stdClass
     */
    private function create_course_session(int $courseid, int $userid): stdClass {
        global $DB;
        $record = new stdClass();
        $record->courseid = $courseid;
        $record->userid = $userid;
        $record->session_id = 'sess_' . bin2hex(random_bytes(4));
        $record->status = 1;
        $record->coursedata = json_encode(['local_coursegen_custom_prompt' => 'Create a course about privacy']);
        $record->timecreated = time();
        $record->timemodified = time();
        $record->id = $DB->insert_record('local_coursegen_course_sessions', $record);
        return $record;
    }

    /**
     * Store a syllabus file in the system context for a planning session.
     *
     * @param int $sessionid Session id used as file item id.
     * @param string $filename File name.
     * @return \stored_file
     */
    private function create_syllabus_file(int $sessionid, string $filename = 'syllabus.pdf'): \stored_file {
        $fs = get_file_storage();
        return $fs->create_file_from_string((object) [
            'contextid' => context_system::instance()->id,
            'component' => 'local_coursegen',
            'filearea' => 'syllabus',
            'itemid' => $sessionid,
            'filepath' => '/',
            'filename' => $filename,
        ], '%PDF-1.4 test syllabus');
    }

    /**
     * Create a module_job record.
     *
     * @param int $courseid
     * @param int $userid
     * @return stdClass
     */
    private function create_module_job(int $courseid, int $userid): stdClass {
        global $DB;
        $record = new stdClass();
        $record->courseid = $courseid;
        $record->userid = $userid;
        $record->job_id = 'job_' . bin2hex(random_bytes(4));
        $record->status = 'execution_started';
        $record->generate_images = 0;
        $record->context_type = ai_context::CONTEXT_TYPE_SYSTEM_INSTRUCTION;
        $record->system_instruction_name = 'Test system instruction';
        $record->sectionnum = 1;
        $record->beforemod = null;
        $record->timecreated = time();
        $record->timemodified = time();
        $record->id = $DB->insert_record('local_coursegen_module_jobs', $record);
        return $record;
    }
}
