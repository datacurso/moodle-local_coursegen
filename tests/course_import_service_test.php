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
     * Create a user holding exactly the given capabilities, assigned at the category.
     *
     * @param int $categoryid Category of the target and staging courses.
     * @param string[] $capabilities Capabilities to allow.
     * @param string[] $assignableroles Shortnames of the roles the user may assign (role_allow_assign).
     * @return \stdClass
     */
    private function create_importer(int $categoryid, array $capabilities, array $assignableroles = []): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $context = \context_coursecat::instance($categoryid);
        $roleid = create_role('AI importer', 'aiimporter', '');
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        }
        foreach ($assignableroles as $shortname) {
            core_role_set_assign_allowed($roleid, $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST));
        }
        role_assign($roleid, $user->id, $context->id);
        return $user;
    }

    /**
     * The capabilities the import requires, as checked by the service and by the backup/restore engines.
     *
     * @return string[]
     */
    private function required_capabilities(): array {
        return [
            'moodle/backup:backupcourse',
            'moodle/backup:backuptargetimport',
            'moodle/restore:restorecourse',
            'moodle/course:create',
            'moodle/course:visibility',
            'moodle/backup:userinfo',
            'moodle/restore:userinfo',
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
     * Enrol a student and a teacher in the target, put the student in a group and grade the student on a new assignment.
     *
     * @param \stdClass $target Target course record.
     * @return array{student: \stdClass, teacher: \stdClass, group: \stdClass, assign: \stdClass}
     */
    private function populate_target_with_people(\stdClass $target): array {
        $generator = $this->getDataGenerator();
        $student = $generator->create_user(['username' => 'student1']);
        $teacher = $generator->create_user(['username' => 'teacher1']);
        $generator->enrol_user($student->id, $target->id, 'student');
        $generator->enrol_user($teacher->id, $target->id, 'editingteacher');
        $group = $generator->create_group(['courseid' => $target->id, 'name' => 'Group A']);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);
        $assign = $generator->create_module('assign', ['course' => $target->id, 'name' => 'Original assignment']);
        grade_update('mod/assign', $target->id, 'mod', 'assign', $assign->id, 0, [
            'userid' => $student->id,
            'rawgrade' => 75.0,
        ]);
        return ['student' => $student, 'teacher' => $teacher, 'group' => $group, 'assign' => $assign];
    }

    /**
     * Sorted "username:role,role" signature of the users enrolled in a course, read straight from the tables.
     *
     * @param int $courseid Course id.
     * @return string[]
     */
    private function enrolment_signature(int $courseid): array {
        global $DB;
        $context = \context_course::instance($courseid);
        $users = $DB->get_records_sql(
            'SELECT DISTINCT u.id, u.username
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
               JOIN {user} u ON u.id = ue.userid
              WHERE e.courseid = ?',
            [$courseid]
        );
        $rows = [];
        foreach ($users as $user) {
            $roles = array_map(function ($assignment) {
                return $assignment->shortname;
            }, get_user_roles($context, $user->id, false));
            sort($roles);
            $rows[] = $user->username . ':' . implode(',', $roles);
        }
        sort($rows);
        return $rows;
    }

    /**
     * Groups of a course with their members: name => sorted usernames, read straight from the tables.
     *
     * @param int $courseid Course id.
     * @return array<string, string[]>
     */
    private function group_signature(int $courseid): array {
        global $DB;
        $rows = [];
        foreach ($DB->get_records('groups', ['courseid' => $courseid], 'name') as $group) {
            $members = $DB->get_fieldset_sql(
                'SELECT u.username
                   FROM {groups_members} gm
                   JOIN {user} u ON u.id = gm.userid
                  WHERE gm.groupid = ?
               ORDER BY u.username',
                [$group->id]
            );
            $rows[$group->name] = $members;
        }
        return $rows;
    }

    /**
     * Final grade of a user on the (only) assignment grade item of a course, null when there is no item or grade.
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @return float|null
     */
    private function assign_grade(int $courseid, int $userid): ?float {
        global $DB;
        $item = $DB->get_record('grade_items', ['courseid' => $courseid, 'itemtype' => 'mod', 'itemmodule' => 'assign']);
        if (!$item) {
            return null;
        }
        $grade = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $userid]);
        if (!$grade || $grade->finalgrade === null) {
            return null;
        }
        return (float)$grade->finalgrade;
    }

    /**
     * Create a target and a staging course, both "topics", with named sections and a module in every section.
     *
     * Target: section 0 holds "Original intro", sections 1..2 are "Original week N" with one module each.
     * Staging: sections 1..2 are "AI week N" with one module each; section 0 is unnamed and holds
     * "AI intro" unless $stagingintro is false, in which case it is left empty.
     *
     * @param bool $stagingintro Whether the staging section 0 receives the "AI intro" module.
     * @return array{0: \stdClass, 1: \stdClass} Target course, staging course.
     */
    private function create_sectioned_course_pair(bool $stagingintro = true): array {
        global $DB;
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();

        $target = $generator->create_course([
            'category' => $category->id,
            'shortname' => 'ORIG-101',
            'fullname' => 'Original course',
            'format' => 'topics',
            'numsections' => 2,
        ]);
        $DB->set_field('course_sections', 'name', 'Original week 1', ['course' => $target->id, 'section' => 1]);
        $DB->set_field('course_sections', 'name', 'Original week 2', ['course' => $target->id, 'section' => 2]);
        $generator->create_module('page', ['course' => $target->id, 'name' => 'Original intro', 'section' => 0]);
        $generator->create_module('page', ['course' => $target->id, 'name' => 'Original page', 'section' => 1]);
        $generator->create_module('forum', ['course' => $target->id, 'name' => 'Original forum', 'section' => 2]);

        $staging = $generator->create_course([
            'category' => $category->id,
            'shortname' => 'courseai-stage',
            'fullname' => 'AI generated draft',
            'format' => 'topics',
            'numsections' => 2,
        ]);
        $DB->set_field('course_sections', 'name', 'AI week 1', ['course' => $staging->id, 'section' => 1]);
        $DB->set_field('course_sections', 'name', 'AI week 2', ['course' => $staging->id, 'section' => 2]);
        if ($stagingintro) {
            $generator->create_module('page', ['course' => $staging->id, 'name' => 'AI intro', 'section' => 0]);
        }
        $generator->create_module('page', ['course' => $staging->id, 'name' => 'AI page', 'section' => 1]);
        $generator->create_module('url', [
            'course' => $staging->id,
            'name' => 'AI link',
            'externalurl' => 'https://example.com/ai',
            'section' => 2,
        ]);
        rebuild_course_cache($target->id, true);
        rebuild_course_cache($staging->id, true);

        $target->id = (int)$target->id;
        $staging->id = (int)$staging->id;

        return [$target, $staging];
    }

    /**
     * Section layout of a course: number => [name, ordered "modname:name" of its modules].
     *
     * @param int $courseid Course id.
     * @return array<int, array{name: string, modules: string[]}>
     */
    private function section_layout(int $courseid): array {
        $modinfo = get_fast_modinfo($courseid);
        $layout = [];
        foreach ($modinfo->get_section_info_all() as $number => $section) {
            $modules = [];
            foreach ($modinfo->sections[$number] ?? [] as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                $modules[] = $cm->modname . ':' . $cm->name;
            }
            $layout[(int)$number] = ['name' => (string)$section->name, 'modules' => $modules];
        }
        return $layout;
    }

    /**
     * Create a target with sections 0..1 and a staging course numbered from zero, the way the generator builds it.
     *
     * The AI plan numbers its sections from zero: create_course_service renames
     * Moodle's section 0 to the plan's first section and places its activities
     * there. Target: section 0 holds "Original intro", section 1 is
     * "Original week 1" with one module. Staging: section 0 is "AI week 1" with
     * a summary and two modules (unless $zeromodules is false), section 1 is
     * "AI week 2" with two modules.
     *
     * @param bool $zeromodules Whether the staging section 0 receives its two modules.
     * @return array{0: \stdClass, 1: \stdClass} Target course, staging course.
     */
    private function create_zero_based_course_pair(bool $zeromodules = true): array {
        global $DB;
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();

        $target = $generator->create_course([
            'category' => $category->id,
            'shortname' => 'ORIG-101',
            'fullname' => 'Original course',
            'format' => 'topics',
            'numsections' => 1,
        ]);
        $DB->set_field('course_sections', 'name', 'Original week 1', ['course' => $target->id, 'section' => 1]);
        $generator->create_module('page', ['course' => $target->id, 'name' => 'Original intro', 'section' => 0]);
        $generator->create_module('page', ['course' => $target->id, 'name' => 'Original page', 'section' => 1]);

        $staging = $generator->create_course([
            'category' => $category->id,
            'shortname' => 'courseai-stage',
            'fullname' => 'AI generated draft',
            'format' => 'topics',
            'numsections' => 1,
        ]);
        $zero = $DB->get_record('course_sections', ['course' => $staging->id, 'section' => 0], 'id', MUST_EXIST);
        $DB->update_record('course_sections', (object) [
            'id' => $zero->id,
            'name' => 'AI week 1',
            'summary' => '<p>Week 1 summary</p>',
            'summaryformat' => FORMAT_HTML,
        ]);
        $DB->set_field('course_sections', 'name', 'AI week 2', ['course' => $staging->id, 'section' => 1]);
        if ($zeromodules) {
            $generator->create_module('page', ['course' => $staging->id, 'name' => 'AI page 1', 'section' => 0]);
            $generator->create_module('url', [
                'course' => $staging->id,
                'name' => 'AI link 1',
                'externalurl' => 'https://example.com/ai-1',
                'section' => 0,
            ]);
        }
        $generator->create_module('page', ['course' => $staging->id, 'name' => 'AI page 2', 'section' => 1]);
        $generator->create_module('url', [
            'course' => $staging->id,
            'name' => 'AI link 2',
            'externalurl' => 'https://example.com/ai-2',
            'section' => 1,
        ]);
        rebuild_course_cache($target->id, true);
        rebuild_course_cache($staging->id, true);

        $target->id = (int)$target->id;
        $staging->id = (int)$staging->id;

        return [$target, $staging];
    }

    /**
     * Section records of a course as stored, keyed by id: the fields the transient renumbering touches.
     *
     * @param int $courseid Course id.
     * @return \stdClass[]
     */
    private function section_records(int $courseid): array {
        global $DB;
        return $DB->get_records('course_sections', ['course' => $courseid], 'section', 'id, section, name, summary, sequence');
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
     * The copy takes the original names and category; the original is renamed.
     */
    public function test_import_creates_copy_with_original_names(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$target, $staging] = $this->create_course_pair();
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
    }

    /**
     * Import content modes that replace the copy content: explicit replace, unset, and unknown values.
     *
     * @return array[]
     */
    public static function replace_mode_provider(): array {
        return [
            'explicit replace' => ['replace'],
            'setting unset' => [null],
            'unknown value falls back to replace' => ['merge-everything'],
        ];
    }

    /**
     * After the import the copy contains exactly the staging modules and sections, none of the original's.
     *
     * @dataProvider replace_mode_provider
     * @param string|null $mode Value of local_coursegen/importcontentmode, null to leave it unset.
     */
    public function test_import_replaces_copy_content_with_staging_content(?string $mode): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        if ($mode === null) {
            unset_config('importcontentmode', 'local_coursegen');
        } else {
            set_config('importcontentmode', $mode, 'local_coursegen');
        }
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
     * In "add" mode the staging sections are appended after the original ones, never merged into them.
     *
     * Target sections 0..2 stay byte for byte as they were. Staging section 0
     * holds a module, so it is appended as its own (unnamed) section 3, and
     * staging sections 1..2 follow it as sections 4..5 with their own names and
     * modules.
     */
    public function test_add_mode_appends_staging_sections_after_original_sections(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('importcontentmode', 'add', 'local_coursegen');
        [$target, $staging] = $this->create_sectioned_course_pair();
        $session = $this->create_session(get_admin()->id, $staging->id);

        $result = course_import_service::import_into_existing($session->get('id'), $staging->id, $target->id, get_admin()->id);

        $this->assertTrue($result['success'], 'Import must succeed: ' . ($result['errorclass'] ?? ''));
        $copyid = (int)$result['copycourseid'];
        $this->assertSame([
            0 => ['name' => '', 'modules' => ['page:Original intro']],
            1 => ['name' => 'Original week 1', 'modules' => ['page:Original page']],
            2 => ['name' => 'Original week 2', 'modules' => ['forum:Original forum']],
            3 => ['name' => '', 'modules' => ['page:AI intro']],
            4 => ['name' => 'AI week 1', 'modules' => ['page:AI page']],
            5 => ['name' => 'AI week 2', 'modules' => ['url:AI link']],
        ], $this->section_layout($copyid));
        $this->assertSame(6, $DB->count_records('course_sections', ['course' => $copyid]));
        $this->assertSame(5, course_get_format($copyid)->get_last_section_number());
    }

    /**
     * A staging course whose section 0 is unnamed and empty appends only its sections 1..N, shifted by the copy's last number.
     */
    public function test_add_mode_with_empty_staging_section_zero_appends_only_regular_sections(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('importcontentmode', 'add', 'local_coursegen');
        [$target, $staging] = $this->create_sectioned_course_pair(false);
        $this->assertSame(['name' => '', 'modules' => []], $this->section_layout($staging->id)[0]);
        $session = $this->create_session(get_admin()->id, $staging->id);

        $result = course_import_service::import_into_existing($session->get('id'), $staging->id, $target->id, get_admin()->id);

        $this->assertTrue($result['success'], 'Import must succeed: ' . ($result['errorclass'] ?? ''));
        $copyid = (int)$result['copycourseid'];
        $this->assertSame([
            0 => ['name' => '', 'modules' => ['page:Original intro']],
            1 => ['name' => 'Original week 1', 'modules' => ['page:Original page']],
            2 => ['name' => 'Original week 2', 'modules' => ['forum:Original forum']],
            3 => ['name' => 'AI week 1', 'modules' => ['page:AI page']],
            4 => ['name' => 'AI week 2', 'modules' => ['url:AI link']],
        ], $this->section_layout($copyid));
        $this->assertSame(5, $DB->count_records('course_sections', ['course' => $copyid]));
        $this->assertSame(4, course_get_format($copyid)->get_last_section_number());
    }

    /**
     * A zero-based staging (ticket #9539: the AI plan starts at section 0) keeps its first section as a section of its own.
     *
     * Staging section 0 is "AI week 1" with a summary and two modules; it must
     * become section 2 of the copy with that name, summary and exactly those
     * modules in order, and "AI week 2" must follow as section 3. Before the
     * fix the section 0 content was folded into the first shifted section:
     * a single section 2 named "AI week 2" holding all four modules.
     */
    public function test_add_mode_appends_zero_based_staging_section_zero_as_its_own_section(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('importcontentmode', 'add', 'local_coursegen');
        [$target, $staging] = $this->create_zero_based_course_pair();
        $this->assertSame([
            0 => ['name' => 'AI week 1', 'modules' => ['page:AI page 1', 'url:AI link 1']],
            1 => ['name' => 'AI week 2', 'modules' => ['page:AI page 2', 'url:AI link 2']],
        ], $this->section_layout($staging->id));
        $session = $this->create_session(get_admin()->id, $staging->id);

        $result = course_import_service::import_into_existing($session->get('id'), $staging->id, $target->id, get_admin()->id);

        $this->assertTrue($result['success'], 'Import must succeed: ' . ($result['errorclass'] ?? ''));
        $copyid = (int)$result['copycourseid'];
        $this->assertSame([
            0 => ['name' => '', 'modules' => ['page:Original intro']],
            1 => ['name' => 'Original week 1', 'modules' => ['page:Original page']],
            2 => ['name' => 'AI week 1', 'modules' => ['page:AI page 1', 'url:AI link 1']],
            3 => ['name' => 'AI week 2', 'modules' => ['page:AI page 2', 'url:AI link 2']],
        ], $this->section_layout($copyid));
        $this->assertSame(4, $DB->count_records('course_sections', ['course' => $copyid]));
        $this->assertSame(
            '<p>Week 1 summary</p>',
            $DB->get_field('course_sections', 'summary', ['course' => $copyid, 'section' => 2])
        );
        $this->assertSame('', (string)$DB->get_field('course_sections', 'summary', ['course' => $copyid, 'section' => 0]));
        $this->assertSame(3, course_get_format($copyid)->get_last_section_number());
    }

    /**
     * A zero-based staging whose section 0 is named but holds no module still gets that section appended, empty.
     */
    public function test_add_mode_appends_named_empty_staging_section_zero(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('importcontentmode', 'add', 'local_coursegen');
        [$target, $staging] = $this->create_zero_based_course_pair(false);
        $this->assertSame(['name' => 'AI week 1', 'modules' => []], $this->section_layout($staging->id)[0]);
        $session = $this->create_session(get_admin()->id, $staging->id);

        $result = course_import_service::import_into_existing($session->get('id'), $staging->id, $target->id, get_admin()->id);

        $this->assertTrue($result['success'], 'Import must succeed: ' . ($result['errorclass'] ?? ''));
        $copyid = (int)$result['copycourseid'];
        $this->assertSame([
            0 => ['name' => '', 'modules' => ['page:Original intro']],
            1 => ['name' => 'Original week 1', 'modules' => ['page:Original page']],
            2 => ['name' => 'AI week 1', 'modules' => []],
            3 => ['name' => 'AI week 2', 'modules' => ['page:AI page 2', 'url:AI link 2']],
        ], $this->section_layout($copyid));
        $this->assertSame(4, $DB->count_records('course_sections', ['course' => $copyid]));
        $this->assertSame(
            '<p>Week 1 summary</p>',
            $DB->get_field('course_sections', 'summary', ['course' => $copyid, 'section' => 2])
        );
    }

    /**
     * A failed "add" import leaves a zero-based staging with its section 0 name, summary and modules intact.
     */
    public function test_failed_add_import_keeps_zero_based_staging_section_zero(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('importcontentmode', 'add', 'local_coursegen');
        [$target, $staging] = $this->create_zero_based_course_pair();
        $before = $this->section_records($staging->id);
        $layoutbefore = $this->section_layout($staging->id);
        $modulesbefore = $DB->get_records_menu('course_modules', ['course' => $staging->id], '', 'id, section');
        $session = $this->create_session(get_admin()->id, $staging->id);

        $result = testable_course_import_service::import_into_existing(
            $session->get('id'),
            $staging->id,
            $target->id,
            get_admin()->id
        );

        $this->assertFalse($result['success']);
        $this->assertSame(\RuntimeException::class, $result['errorclass']);
        $this->assertTrue($DB->record_exists('course', ['id' => $staging->id]));
        $this->assertEquals($before, $this->section_records($staging->id));
        $this->assertSame($layoutbefore, $this->section_layout($staging->id));
        $this->assertEquals($modulesbefore, $DB->get_records_menu('course_modules', ['course' => $staging->id], '', 'id, section'));
        $this->assertSame(2, $DB->count_records('course_sections', ['course' => $staging->id]));
        $zero = $DB->get_record('course_sections', ['course' => $staging->id, 'section' => 0], '*', MUST_EXIST);
        $this->assertSame('AI week 1', $zero->name);
        $this->assertSame('<p>Week 1 summary</p>', $zero->summary);
        $this->assertSame('ORIG-101', $DB->get_field('course', 'shortname', ['id' => $target->id]));
    }

    /**
     * The renumbering only ever touches the staging course: the original target keeps its exact layout.
     */
    public function test_add_mode_leaves_original_target_sections_untouched(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('importcontentmode', 'add', 'local_coursegen');
        [$target, $staging] = $this->create_sectioned_course_pair();
        $before = $DB->get_records('course_sections', ['course' => $target->id], 'section', 'id, section, name, summary, sequence');
        $layoutbefore = $this->section_layout($target->id);
        $session = $this->create_session(get_admin()->id, $staging->id);

        $result = course_import_service::import_into_existing($session->get('id'), $staging->id, $target->id, get_admin()->id);

        $this->assertTrue($result['success'], 'Import must succeed: ' . ($result['errorclass'] ?? ''));
        $after = $DB->get_records('course_sections', ['course' => $target->id], 'section', 'id, section, name, summary, sequence');
        $this->assertEquals($before, $after);
        $this->assertSame($layoutbefore, $this->section_layout($target->id));
        $this->assertSame([0, 1, 2], array_keys($this->section_layout($target->id)));
    }

    /**
     * A staging course with content only in section 0 appends a single new section holding that content.
     */
    public function test_add_mode_appends_one_section_for_staging_with_only_section_zero_content(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('importcontentmode', 'add', 'local_coursegen');
        [$target] = $this->create_sectioned_course_pair();
        // A single-section staging course: everything the AI produced sits in section 0.
        $staging = $this->getDataGenerator()->create_course([
            'category' => $target->category,
            'shortname' => 'courseai-stage-flat',
            'fullname' => 'AI flat draft',
            'format' => 'topics',
            'numsections' => 0,
        ]);
        $staging->id = (int)$staging->id;
        $this->getDataGenerator()->create_module('page', ['course' => $staging->id, 'name' => 'AI intro', 'section' => 0]);
        $this->getDataGenerator()->create_module('url', [
            'course' => $staging->id,
            'name' => 'AI link',
            'externalurl' => 'https://example.com/ai',
            'section' => 0,
        ]);
        $this->assertSame(1, $DB->count_records('course_sections', ['course' => $staging->id]));
        $session = $this->create_session(get_admin()->id, $staging->id);

        $result = course_import_service::import_into_existing($session->get('id'), $staging->id, $target->id, get_admin()->id);

        $this->assertTrue($result['success'], 'Import must succeed: ' . ($result['errorclass'] ?? ''));
        $copyid = (int)$result['copycourseid'];
        $this->assertSame([
            0 => ['name' => '', 'modules' => ['page:Original intro']],
            1 => ['name' => 'Original week 1', 'modules' => ['page:Original page']],
            2 => ['name' => 'Original week 2', 'modules' => ['forum:Original forum']],
            3 => ['name' => '', 'modules' => ['page:AI intro', 'url:AI link']],
        ], $this->section_layout($copyid));
        $this->assertSame(4, $DB->count_records('course_sections', ['course' => $copyid]));
    }

    /**
     * A failed "add" import leaves the staging course with its original section layout.
     *
     * The staging is only renumbered while it is being backed up; the user must
     * still be able to open it after a failure, so no gap or shifted number may remain.
     */
    public function test_failed_add_import_keeps_staging_section_layout(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('importcontentmode', 'add', 'local_coursegen');
        [$target, $staging] = $this->create_sectioned_course_pair();
        $before = $this->section_records($staging->id);
        $layoutbefore = $this->section_layout($staging->id);
        $session = $this->create_session(get_admin()->id, $staging->id);

        $result = testable_course_import_service::import_into_existing(
            $session->get('id'),
            $staging->id,
            $target->id,
            get_admin()->id
        );

        $this->assertFalse($result['success']);
        $this->assertSame(\RuntimeException::class, $result['errorclass']);
        $this->assertTrue($DB->record_exists('course', ['id' => $staging->id]));
        $this->assertEquals($before, $this->section_records($staging->id));
        $this->assertSame($layoutbefore, $this->section_layout($staging->id));
        $this->assertSame('ORIG-101', $DB->get_field('course', 'shortname', ['id' => $target->id]));
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
        $user = $this->create_importer($category->id, array_values(array_diff(
            $this->required_capabilities(),
            ['moodle/backup:backuptargetimport']
        )));
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
     * On success the original course is hidden (both visibility flags) and the copy stays visible.
     */
    public function test_import_hides_original_and_keeps_copy_visible(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$target, $staging] = $this->create_course_pair();
        $this->assertSame(1, (int)$DB->get_field('course', 'visible', ['id' => $target->id]));
        $session = $this->create_session(get_admin()->id, $staging->id);
        $sink = $this->redirectEvents();

        $result = course_import_service::import_into_existing($session->get('id'), $staging->id, $target->id, get_admin()->id);

        $this->assertTrue($result['success'], 'Import must succeed: ' . ($result['errorclass'] ?? ''));
        $original = $DB->get_record('course', ['id' => $target->id], '*', MUST_EXIST);
        $this->assertSame(0, (int)$original->visible);
        $this->assertSame(0, (int)$original->visibleold);
        $copy = $DB->get_record('course', ['id' => $result['copycourseid']], '*', MUST_EXIST);
        $this->assertSame(1, (int)$copy->visible);
        $this->assertFalse(get_fast_modinfo($target->id)->get_course()->visible == 1);

        $import = $this->import_data($session->get('id'));
        $this->assertSame(1, $import['originalvisible']);
        $this->assertSame(1, $import['originalvisibleold']);

        // Hiding goes through the regular course update path: a course_updated event for the original.
        $updated = array_values(array_filter($sink->get_events(), function ($event) use ($target) {
            return $event instanceof \core\event\course_updated && (int)$event->objectid === $target->id;
        }));
        $this->assertNotEmpty($updated);
        $this->assertSame(0, (int)end($updated)->other['updatedfields']['visible']);
    }

    /**
     * A failed import restores the exact visibility flags the original had before the rename.
     */
    public function test_failed_import_restores_original_visibility(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$target, $staging] = $this->create_course_pair();
        // Distinct flag values so a plain "show course" rollback cannot pass by accident.
        $DB->set_field('course', 'visibleold', 0, ['id' => $target->id]);
        $session = $this->create_session(get_admin()->id, $staging->id);

        $result = testable_course_import_service::import_into_existing(
            $session->get('id'),
            $staging->id,
            $target->id,
            get_admin()->id
        );

        $this->assertFalse($result['success']);
        $original = $DB->get_record('course', ['id' => $target->id], '*', MUST_EXIST);
        $this->assertSame(1, (int)$original->visible);
        $this->assertSame(0, (int)$original->visibleold);
        $this->assertSame('ORIG-101', $original->shortname);
        $this->assertTrue(get_fast_modinfo($target->id)->get_course()->visible == 1);
        $import = $this->import_data($session->get('id'));
        $this->assertSame(1, $import['originalvisible']);
        $this->assertSame(0, $import['originalvisibleold']);
    }

    /**
     * A user who may not change course visibility is rejected before anything changes.
     */
    public function test_import_denies_user_without_visibility_capability(): void {
        global $DB;
        $this->resetAfterTest();
        [$target, $staging, $category] = $this->create_course_pair();
        $user = $this->create_importer($category->id, array_values(array_diff(
            $this->required_capabilities(),
            ['moodle/course:visibility']
        )));
        $this->setUser($user);
        $session = $this->create_session($user->id, $staging->id);
        $coursecount = $DB->count_records('course');

        try {
            course_import_service::import_into_existing($session->get('id'), $staging->id, $target->id, $user->id);
            $this->fail('A user without moodle/course:visibility must be rejected.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('moodle/course:visibility'), $e->a);
        }

        $this->assertSame($coursecount, $DB->count_records('course'));
        $original = $DB->get_record('course', ['id' => $target->id], '*', MUST_EXIST);
        $this->assertSame('ORIG-101', $original->shortname);
        $this->assertSame(1, (int)$original->visible);
        $this->assertSame([], $this->import_data($session->get('id')));
    }

    /**
     * A non-admin user holding the required capabilities can run the whole import and the people reach the copy intact.
     *
     * Such a user lacks moodle/backup:configure and moodle/restore:configure,
     * so the engine locks every backup and restore setting: the people of the
     * course must still reach the copy and survive the replace. The restore
     * engine maps every role of the backup to a role the restorer may assign
     * and enrols a restorer who cannot update the copy as editing teacher, so
     * an exact copy of the people also needs moodle/course:view,
     * moodle/course:update and moodle/role:assign with the course roles allowed.
     */
    public function test_import_succeeds_for_user_with_exact_capabilities(): void {
        global $DB;
        $this->resetAfterTest();
        [$target, $staging, $category] = $this->create_course_pair();
        $people = $this->populate_target_with_people($target);
        $enrolments = $this->enrolment_signature($target->id);
        $groups = $this->group_signature($target->id);
        $user = $this->create_importer(
            $category->id,
            array_merge($this->required_capabilities(), ['moodle/course:view', 'moodle/course:update', 'moodle/role:assign']),
            ['student', 'editingteacher']
        );
        $this->setUser($user);
        $session = $this->create_session($user->id, $staging->id);

        $result = course_import_service::import_into_existing($session->get('id'), $staging->id, $target->id, $user->id);

        $this->assertTrue($result['success'], 'Import must succeed: ' . ($result['errorclass'] ?? ''));
        $copy = $DB->get_record('course', ['id' => $result['copycourseid']], '*', MUST_EXIST);
        $this->assertSame('ORIG-101', $copy->shortname);
        $this->assertSame(['page:AI page', 'url:AI link'], $this->module_signature($copy->id));
        $this->assertFalse($DB->record_exists('course', ['id' => $staging->id]));
        $this->assertSame($enrolments, $this->enrolment_signature((int)$copy->id));
        $this->assertSame($groups, $this->group_signature((int)$copy->id));
        $this->assertNull($this->assign_grade((int)$copy->id, $people['student']->id));
    }

    /**
     * In "add" mode the copy carries the people of the original: enrolments with roles, groups with members and grades.
     *
     * Everything is copied, not moved: the original keeps its own enrolments, groups and grades.
     */
    public function test_add_import_copies_enrolments_groups_and_grades_into_copy(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('importcontentmode', 'add', 'local_coursegen');
        [$target, $staging] = $this->create_course_pair();
        $people = $this->populate_target_with_people($target);
        $enrolments = $this->enrolment_signature($target->id);
        $groups = $this->group_signature($target->id);
        $this->assertSame(['student1:student', 'teacher1:editingteacher'], $enrolments);
        $this->assertSame(['Group A' => ['student1']], $groups);
        $this->assertSame(75.0, $this->assign_grade($target->id, $people['student']->id));
        $userenrolments = $DB->count_records('user_enrolments');
        $session = $this->create_session(get_admin()->id, $staging->id);

        $result = course_import_service::import_into_existing($session->get('id'), $staging->id, $target->id, get_admin()->id);

        $this->assertTrue($result['success'], 'Import must succeed: ' . ($result['errorclass'] ?? ''));
        $copyid = (int)$result['copycourseid'];
        $this->assertSame($enrolments, $this->enrolment_signature($copyid));
        $this->assertSame($groups, $this->group_signature($copyid));
        $this->assertSame(75.0, $this->assign_grade($copyid, $people['student']->id));
        $this->assertContains('assign:Original assignment', $this->module_signature($copyid));
        $this->assertContains('page:AI page', $this->module_signature($copyid));
        // Copied, not moved.
        $this->assertSame($enrolments, $this->enrolment_signature($target->id));
        $this->assertSame($groups, $this->group_signature($target->id));
        $this->assertSame(75.0, $this->assign_grade($target->id, $people['student']->id));
        $this->assertSame($userenrolments * 2, $DB->count_records('user_enrolments'));
    }

    /**
     * In "replace" mode the people, roles and groups survive the content wipe; the original activities and their grades do not.
     */
    public function test_replace_import_keeps_people_roles_and_groups_but_replaces_content(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('importcontentmode', 'replace', 'local_coursegen');
        [$target, $staging] = $this->create_course_pair();
        $people = $this->populate_target_with_people($target);
        $enrolments = $this->enrolment_signature($target->id);
        $groups = $this->group_signature($target->id);
        $this->assertSame(['student1:student', 'teacher1:editingteacher'], $enrolments);
        $this->assertSame(['Group A' => ['student1']], $groups);
        $session = $this->create_session(get_admin()->id, $staging->id);

        $result = course_import_service::import_into_existing($session->get('id'), $staging->id, $target->id, get_admin()->id);

        $this->assertTrue($result['success'], 'Import must succeed: ' . ($result['errorclass'] ?? ''));
        $copyid = (int)$result['copycourseid'];
        $this->assertSame($enrolments, $this->enrolment_signature($copyid));
        $this->assertSame($groups, $this->group_signature($copyid));
        $this->assertSame(['page:AI page', 'url:AI link'], $this->module_signature($copyid));
        $this->assertNull($this->assign_grade($copyid, $people['student']->id));
        $this->assertSame(0, $DB->count_records('grade_items', ['courseid' => $copyid, 'itemtype' => 'mod']));
        // The original keeps its activities, grades and people.
        $this->assertSame(75.0, $this->assign_grade($target->id, $people['student']->id));
        $this->assertSame($enrolments, $this->enrolment_signature($target->id));
        $this->assertSame($groups, $this->group_signature($target->id));
    }

    /**
     * The capabilities that let the import copy user data, each denied on its own.
     *
     * @return array[]
     */
    public static function userinfo_capability_provider(): array {
        return [
            'backup userinfo' => ['moodle/backup:userinfo'],
            'restore userinfo' => ['moodle/restore:userinfo'],
        ];
    }

    /**
     * A user who may not copy user data is rejected before anything changes.
     *
     * @dataProvider userinfo_capability_provider
     * @param string $capability The one required capability the user lacks.
     */
    public function test_import_denies_user_without_userinfo_capability(string $capability): void {
        global $DB;
        $this->resetAfterTest();
        [$target, $staging, $category] = $this->create_course_pair();
        $user = $this->create_importer($category->id, array_values(array_diff(
            $this->required_capabilities(),
            [$capability]
        )));
        $this->setUser($user);
        $session = $this->create_session($user->id, $staging->id);
        $coursecount = $DB->count_records('course');

        try {
            course_import_service::import_into_existing($session->get('id'), $staging->id, $target->id, $user->id);
            $this->fail('A user without ' . $capability . ' must be rejected.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string($capability), $e->a);
        }

        $this->assertSame($coursecount, $DB->count_records('course'));
        $original = $DB->get_record('course', ['id' => $target->id], '*', MUST_EXIST);
        $this->assertSame('ORIG-101', $original->shortname);
        $this->assertSame('Original course', $original->fullname);
        $this->assertSame(1, (int)$original->visible);
        $this->assertTrue($DB->record_exists('course', ['id' => $staging->id]));
        $this->assertSame([], $this->import_data($session->get('id')));
    }
}
