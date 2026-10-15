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

namespace local_coursegen\local\service;

use local_coursegen\event\generation_failed;
use local_coursegen\event\generation_result_applied;
use local_coursegen\local\models\course_session;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/testable_course_import_service.php');

/**
 * Tests for the course import service (ticket #9539, import into an existing course).
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\course_import_service
 */
final class course_import_service_test extends \advanced_testcase {
    /**
     * Create a target course with content and enrolments, and a staging course with different content.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass} Target course, staging course, category.
     */
    private function create_course_pair(): array {
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();

        $target = $generator->create_course([
            'category' => $category->id,
            'shortname' => 'ORIG-101',
            'fullname' => 'Original course',
            'numsections' => 3,
        ]);
        $generator->create_module('page', ['course' => $target->id, 'name' => 'Original page']);
        $generator->create_module('forum', ['course' => $target->id, 'name' => 'Original forum']);
        $generator->create_module('url', [
            'course' => $target->id,
            'name' => 'Original link',
            'externalurl' => 'https://example.com/original',
        ]);

        $staging = $generator->create_course([
            'category' => $category->id,
            'shortname' => 'courseai-stage',
            'fullname' => 'AI generated draft',
            'numsections' => 2,
        ]);
        $generator->create_module('page', ['course' => $staging->id, 'name' => 'AI page']);
        $generator->create_module('url', [
            'course' => $staging->id,
            'name' => 'AI link',
            'externalurl' => 'https://example.com/ai',
        ]);

        $target->id = (int)$target->id;
        $staging->id = (int)$staging->id;

        return [$target, $staging, $category];
    }

    /**
     * Create a planning session that already points at the staging course.
     *
     * @param int $userid Owner.
     * @param int $stagingid Staging course id.
     * @return course_session
     */
    private function create_session(int $userid, int $stagingid): course_session {
        $session = new course_session(0, (object) [
            'courseid' => $stagingid,
            'userid' => $userid,
            'session_id' => 'thread-import-1',
            'status' => course_session::STATUS_CREATED,
            'coursedata' => json_encode(['fullname' => 'Planned', 'local_coursegen_context_type' => 'customprompt']),
        ]);
        $session->create();
        return $session;
    }

    /**
     * Create a user holding exactly the capabilities the import requires, assigned at the category.
     *
     * @param int $categoryid Category of the target and staging courses.
     * @param string[] $capabilities Capabilities to allow.
     * @return \stdClass
     */
    private function create_importer(int $categoryid, array $capabilities): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $context = \context_coursecat::instance($categoryid);
        $roleid = create_role('AI importer', 'aiimporter', '');
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        }
        role_assign($roleid, $user->id, $context->id);
        return $user;
    }

    /**
     * The capabilities the import requires, as checked by the service and by the backup engine.
     *
     * @return string[]
     */
    private function required_capabilities(): array {
        return [
            'moodle/backup:backupcourse',
            'moodle/backup:backuptargetimport',
            'moodle/restore:restorecourse',
            'moodle/course:create',
        ];
    }

    /**
     * Sorted "modname:name" signature of the visible modules of a course.
     *
     * @param int $courseid Course id.
     * @return string[]
     */
    private function module_signature(int $courseid): array {
        $modules = [];
        foreach (get_fast_modinfo($courseid)->get_cms() as $cm) {
            $modules[] = $cm->modname . ':' . $cm->name;
        }
        sort($modules);
        return $modules;
    }

    /**
     * Decode the import bookkeeping stored in the session JSON.
     *
     * @param int $sessionid Session id.
     * @return array
     */
    private function import_data(int $sessionid): array {
        $session = new course_session($sessionid);
        $coursedata = json_decode((string)$session->get('coursedata'), true);
        return $coursedata['import'] ?? [];
    }

    /**
     * Renamed shortname follows the delete_user() pattern and the fullname gets the same stamp.
     */
    public function test_compute_renamed_names_follows_delete_user_pattern(): void {
        $this->resetAfterTest();

        $longshort = str_repeat('ñ', 120);
        $longfull = str_repeat('é', 300);
        $stamp = 1700000000;

        $names = course_import_service::compute_renamed_names($longshort, $longfull, $stamp);

        $this->assertMatchesRegularExpression('/\.\d{10}$/', $names['shortname']);
        $this->assertSame(100, \core_text::strlen($names['shortname']));
        $this->assertSame(str_repeat('ñ', 89) . '.' . $stamp, $names['shortname']);
        $this->assertSame(254, \core_text::strlen($names['fullname']));
        $this->assertStringEndsWith(' (' . $stamp . ')', $names['fullname']);
        $this->assertSame($stamp, $names['stamp']);

        $short = course_import_service::compute_renamed_names('ABC', 'Short course', $stamp);
        $this->assertSame('ABC.' . $stamp, $short['shortname']);
        $this->assertSame('Short course (' . $stamp . ')', $short['fullname']);
    }

    /**
     * When the first candidate shortname already exists the stamp is incremented.
     */
    public function test_compute_renamed_names_increments_stamp_on_collision(): void {
        $this->resetAfterTest();
        $stamp = 1700000000;
        $this->getDataGenerator()->create_course(['shortname' => 'ABC.' . $stamp]);
        $this->getDataGenerator()->create_course(['shortname' => 'ABC.' . ($stamp + 1)]);

        $names = course_import_service::compute_renamed_names('ABC', 'Original', $stamp);

        $this->assertSame('ABC.' . ($stamp + 2), $names['shortname']);
        $this->assertSame('Original (' . ($stamp + 2) . ')', $names['fullname']);
        $this->assertSame($stamp + 2, $names['stamp']);
    }

    /**
     * The copy takes the original names, the original is renamed, and no enrolment is copied.
     */
    public function test_import_creates_copy_with_original_names_and_no_enrolments(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$target, $staging] = $this->create_course_pair();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $target->id, 'student');
        $this->assertSame(1, $DB->count_records('user_enrolments'));
        $session = $this->create_session(get_admin()->id, $staging->id);

        $result = course_import_service::import_into_existing($session->get('id'), $staging->id, $target->id, get_admin()->id);

        $this->assertTrue($result['success'], 'Import must succeed: ' . ($result['errorclass'] ?? ''));
        $copy = $DB->get_record('course', ['id' => $result['copycourseid']], '*', MUST_EXIST);
        $this->assertNotEquals($target->id, $copy->id);
        $this->assertSame('ORIG-101', $copy->shortname);
        $this->assertSame('Original course', $copy->fullname);
        $this->assertSame((int)$target->category, (int)$copy->category);

        $original = $DB->get_record('course', ['id' => $target->id], '*', MUST_EXIST);
        $this->assertMatchesRegularExpression('/^ORIG-101\.\d{10}$/', $original->shortname);
        $this->assertMatchesRegularExpression('/^Original course \(\d{10}\)$/', $original->fullname);

        // The original keeps its enrolment; the copy has none.
        $copyenrolids = $DB->get_fieldset_select('enrol', 'id', 'courseid = ?', [$copy->id]);
        if ($copyenrolids) {
            [$insql, $params] = $DB->get_in_or_equal($copyenrolids);
            $this->assertSame(0, $DB->count_records_select('user_enrolments', "enrolid $insql", $params));
        }
        $this->assertSame(1, $DB->count_records('user_enrolments'));
        $this->assertCount(0, get_enrolled_users(\context_course::instance($copy->id)));
    }

    /**
     * After the import the copy contains exactly the staging modules and sections, none of the original's.
     */
    public function test_import_replaces_copy_content_with_staging_content(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$target, $staging] = $this->create_course_pair();
        $stagingmodules = $this->module_signature($staging->id);
        $stagingsections = $DB->count_records('course_sections', ['course' => $staging->id]);
        $session = $this->create_session(get_admin()->id, $staging->id);

        $result = course_import_service::import_into_existing($session->get('id'), $staging->id, $target->id, get_admin()->id);

        $this->assertTrue($result['success']);
        $copyid = (int)$result['copycourseid'];
        $this->assertSame(['page:AI page', 'url:AI link'], $stagingmodules);
        $this->assertSame($stagingmodules, $this->module_signature($copyid));
        $this->assertSame($stagingsections, $DB->count_records('course_sections', ['course' => $copyid]));
        // The original is untouched apart from its names.
        $this->assertSame(
            ['forum:Original forum', 'page:Original page', 'url:Original link'],
            $this->module_signature($target->id)
        );
    }

    /**
     * On success the staging course is deleted, bypassing the recycle bin, and the session points at the copy.
     */
    public function test_import_deletes_staging_without_recycle_bin_and_updates_session(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('categorybinenable', 1, 'tool_recyclebin');
        [$target, $staging] = $this->create_course_pair();
        $session = $this->create_session(get_admin()->id, $staging->id);
        $sink = $this->redirectEvents();

        $result = course_import_service::import_into_existing($session->get('id'), $staging->id, $target->id, get_admin()->id);

        $this->assertTrue($result['success']);
        $this->assertFalse($DB->record_exists('course', ['id' => $staging->id]));
        $this->assertSame(0, $DB->count_records('tool_recyclebin_category'));

        $session = new course_session($session->get('id'));
        $this->assertSame(course_session::STATUS_CREATED, (int)$session->get('status'));
        $this->assertSame((int)$result['copycourseid'], (int)$session->get('courseid'));
        $import = $this->import_data($session->get('id'));
        $this->assertSame('done', $import['state']);
        $this->assertSame($target->id, $import['targetcourseid']);
        $this->assertSame($staging->id, $import['stagingcourseid']);
        $this->assertSame((int)$result['copycourseid'], $import['copycourseid']);
        $this->assertSame('ORIG-101', $import['originalshortname']);
        $this->assertSame('Original course', $import['originalfullname']);
        $this->assertMatchesRegularExpression('/^ORIG-101\.\d{10}$/', $import['renamedshortname']);
        $this->assertSame('', $import['errorclass']);
        $this->assertGreaterThanOrEqual($import['timestarted'], $import['timefinished']);
        // Existing keys of the JSON bag survive the merge.
        $coursedata = json_decode((string)$session->get('coursedata'), true);
        $this->assertSame('customprompt', $coursedata['local_coursegen_context_type']);

        $applied = array_values(array_filter($sink->get_events(), function ($event) {
            return $event instanceof generation_result_applied;
        }));
        $this->assertCount(1, $applied);
        $this->assertSame((int)$result['copycourseid'], (int)$applied[0]->other['courseid']);
        $this->assertSame(\context_course::instance($result['copycourseid'])->id, (int)$applied[0]->contextid);
    }

    /**
     * A failure while replacing the copy rolls everything back and keeps the staging course.
     */
    public function test_failed_import_rolls_back_copy_and_names_and_keeps_staging(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('categorybinenable', 1, 'tool_recyclebin');
        [$target, $staging] = $this->create_course_pair();
        $session = $this->create_session(get_admin()->id, $staging->id);
        $coursecount = $DB->count_records('course');
        $sink = $this->redirectEvents();

        $result = testable_course_import_service::import_into_existing(
            $session->get('id'),
            $staging->id,
            $target->id,
            get_admin()->id
        );

        $this->assertFalse($result['success']);
        $this->assertSame(\RuntimeException::class, $result['errorclass']);
        $this->assertSame(0, (int)$result['copycourseid']);

        // The copy is gone, not in the recycle bin, and the course count is back to the start.
        $this->assertSame($coursecount, $DB->count_records('course'));
        $this->assertSame(0, $DB->count_records('tool_recyclebin_category'));

        // The original has its exact names back and the staging course still exists with its content.
        $original = $DB->get_record('course', ['id' => $target->id], '*', MUST_EXIST);
        $this->assertSame('ORIG-101', $original->shortname);
        $this->assertSame('Original course', $original->fullname);
        $this->assertTrue($DB->record_exists('course', ['id' => $staging->id]));
        $this->assertSame(['page:AI page', 'url:AI link'], $this->module_signature($staging->id));

        $session = new course_session($session->get('id'));
        $this->assertSame(course_session::STATUS_FAILED, (int)$session->get('status'));
        $this->assertSame($staging->id, (int)$session->get('courseid'));
        $import = $this->import_data($session->get('id'));
        $this->assertSame('failed', $import['state']);
        $this->assertSame(\RuntimeException::class, $import['errorclass']);

        $failed = array_values(array_filter($sink->get_events(), function ($event) {
            return $event instanceof generation_failed;
        }));
        $this->assertCount(1, $failed);
        $this->assertSame(\RuntimeException::class, $failed[0]->other['reason']);
    }

    /**
     * A user missing a required capability is rejected before anything changes.
     */
    public function test_import_denies_user_without_required_capabilities(): void {
        global $DB;
        $this->resetAfterTest();
        [$target, $staging, $category] = $this->create_course_pair();
        // Everything except moodle/backup:backuptargetimport on the staging course.
        $user = $this->create_importer($category->id, [
            'moodle/backup:backupcourse',
            'moodle/restore:restorecourse',
            'moodle/course:create',
        ]);
        $this->setUser($user);
        $session = $this->create_session($user->id, $staging->id);
        $coursecount = $DB->count_records('course');

        try {
            course_import_service::import_into_existing($session->get('id'), $staging->id, $target->id, $user->id);
            $this->fail('A user without the import capabilities must be rejected.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('moodle/backup:backuptargetimport'), $e->a);
        }

        $this->assertSame($coursecount, $DB->count_records('course'));
        $original = $DB->get_record('course', ['id' => $target->id], '*', MUST_EXIST);
        $this->assertSame('ORIG-101', $original->shortname);
        $this->assertSame('Original course', $original->fullname);
        $session = new course_session($session->get('id'));
        $this->assertSame(course_session::STATUS_CREATED, (int)$session->get('status'));
        $this->assertSame($staging->id, (int)$session->get('courseid'));
        $this->assertSame([], $this->import_data($session->get('id')));
        $this->assertSame(0, $DB->count_records('task_adhoc'));
    }

    /**
     * A non-admin user holding exactly the required capabilities can run the whole import.
     */
    public function test_import_succeeds_for_user_with_exact_capabilities(): void {
        global $DB;
        $this->resetAfterTest();
        [$target, $staging, $category] = $this->create_course_pair();
        $user = $this->create_importer($category->id, $this->required_capabilities());
        $this->setUser($user);
        $session = $this->create_session($user->id, $staging->id);

        $result = course_import_service::import_into_existing($session->get('id'), $staging->id, $target->id, $user->id);

        $this->assertTrue($result['success'], 'Import must succeed: ' . ($result['errorclass'] ?? ''));
        $copy = $DB->get_record('course', ['id' => $result['copycourseid']], '*', MUST_EXIST);
        $this->assertSame('ORIG-101', $copy->shortname);
        $this->assertSame(['page:AI page', 'url:AI link'], $this->module_signature($copy->id));
        $this->assertFalse($DB->record_exists('course', ['id' => $staging->id]));
    }
}
