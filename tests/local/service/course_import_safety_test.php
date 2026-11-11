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

use local_coursegen\local\models\course_session;
use local_coursegen\local\role_installer;
use local_coursegen\task\import_generated_course_task;
use local_coursegen\task\recover_interrupted_imports;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/coursegen/tests/fixtures/testable_interrupted_import_service.php');
require_once($CFG->dirroot . '/local/coursegen/tests/fixtures/testable_failing_backup_import_service.php');

/**
 * Integrity of the import into an existing course: lock, recovery, revalidation, backups, idnumber and roles.
 *
 * Mindfree, observation 2 of the report of 2026-10-26.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\course_import_service
 * @covers     \local_coursegen\local\service\import_permissions
 * @covers     \local_coursegen\task\import_generated_course_task
 * @covers     \local_coursegen\task\recover_interrupted_imports
 * @covers     \local_coursegen\local\role_installer
 */
final class course_import_safety_test extends \advanced_testcase {
    /**
     * A target course with an idnumber, a teacher and a student, and a staging course, in one category.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass} Target, staging, category.
     */
    private function create_course_pair(): array {
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $target = $generator->create_course([
            'category' => $category->id,
            'shortname' => 'ORIG-101',
            'fullname' => 'Original course',
            'idnumber' => 'SIS-ORIG-101',
        ]);
        $generator->create_module('page', ['course' => $target->id, 'name' => 'Original page']);
        $staging = $generator->create_course([
            'category' => $category->id,
            'shortname' => 'courseai-stage',
            'fullname' => 'AI generated draft',
        ]);
        $generator->create_module('page', ['course' => $staging->id, 'name' => 'AI page']);
        return [$target, $staging, $category];
    }

    /**
     * A planning session pointing at the staging course.
     *
     * @param int $userid Owner.
     * @param int $stagingid Staging course id.
     * @return course_session
     */
    private function create_session(int $userid, int $stagingid): course_session {
        $session = new course_session(0, (object) [
            'courseid' => $stagingid,
            'userid' => $userid,
            'session_id' => 'thread-' . random_string(6),
            'status' => course_session::STATUS_CREATED,
            'coursedata' => json_encode(['fullname' => 'Planned']),
        ]);
        $session->create();
        return $session;
    }

    /**
     * The import bookkeeping of a session.
     *
     * @param int $sessionid Session id.
     * @return array
     */
    private function import_data(int $sessionid): array {
        $coursedata = json_decode((string)(new course_session($sessionid))->get('coursedata'), true);
        return $coursedata['import'] ?? [];
    }

    /**
     * Assert that the target course is exactly as it was before any import.
     *
     * @param \stdClass $target Target course as created.
     */
    private function assert_target_untouched(\stdClass $target): void {
        global $DB;
        $course = $DB->get_record('course', ['id' => $target->id], '*', MUST_EXIST);
        $this->assertSame('ORIG-101', $course->shortname);
        $this->assertSame('Original course', $course->fullname);
        $this->assertSame('SIS-ORIG-101', $course->idnumber);
        $this->assertEquals(1, $course->visible);
        $this->assertSame(1, $DB->count_records('course', ['shortname' => 'ORIG-101']));
    }

    /**
     * A second import on a target that another import holds is refused without touching anything.
     */
    public function test_concurrent_import_on_the_same_target_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$target, $staging] = $this->create_course_pair();
        $session = $this->create_session(get_admin()->id, $staging->id);
        $coursecount = $DB->count_records('course');

        $running = course_import_service::acquire_target_lock((int)$target->id);
        $this->assertNotNull($running);
        try {
            $result = course_import_service::import_into_existing(
                $session->get('id'),
                $staging->id,
                $target->id,
                get_admin()->id
            );
        } finally {
            $running->release();
        }

        $this->assertFalse($result['success']);
        $this->assertSame(course_import_service::ERROR_IN_PROGRESS, $result['errorclass']);
        $this->assert_target_untouched($target);
        $this->assertSame($coursecount, $DB->count_records('course'));
        $this->assertTrue($DB->record_exists('course', ['id' => $staging->id]), 'The staging course is kept.');
        $import = $this->import_data($session->get('id'));
        $this->assertSame(course_import_service::STATE_FAILED, $import['state']);
        $this->assertSame(course_session::STATUS_FAILED, (int)(new course_session($session->get('id')))->get('status'));
    }

    /**
     * Another target is not held by an import running elsewhere, and the lock is free once an import ends.
     */
    public function test_lock_is_per_target_and_released_after_the_import(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$target, $staging] = $this->create_course_pair();
        $other = $this->getDataGenerator()->create_course();
        $session = $this->create_session(get_admin()->id, $staging->id);

        $elsewhere = course_import_service::acquire_target_lock((int)$other->id);
        $result = course_import_service::import_into_existing($session->get('id'), $staging->id, $target->id, get_admin()->id);
        $elsewhere->release();

        $this->assertTrue($result['success']);
        $lock = course_import_service::acquire_target_lock((int)$target->id);
        $this->assertNotNull($lock, 'The import releases the lock of its target.');
        $lock->release();
    }

    /**
     * Hold the lock of a target from another process: a second, independent database connection.
     *
     * The database lock factories hold a lock per connection (a MySQL session, a PostgreSQL
     * session), as two PHP processes would. Closing the connection is what the death of that
     * process does to its locks.
     *
     * @param int $targetid Target course id.
     * @return array{0: \moodle_database, 1: \core\lock\lock} The other connection and its lock.
     */
    private function hold_target_in_another_process(int $targetid): array {
        global $CFG, $DB;
        $other = \moodle_database::get_driver_instance($CFG->dbtype, $CFG->dblibrary);
        $other->connect($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname, $CFG->prefix, $CFG->dboptions);
        $main = $DB;
        $DB = $other;
        try {
            // Same lock type as the import service: a factory keeps the connection it was built with.
            $factory = \core\lock\lock_config::get_lock_factory('local_coursegen_import');
        } finally {
            $DB = $main;
        }
        if ($factory instanceof \core\lock\db_record_lock_factory || $factory instanceof \core\lock\file_lock_factory) {
            $other->dispose();
            $this->markTestSkipped('The lock factory of this site is not held per database connection.');
        }
        $lock = $factory->get_lock('course_' . $targetid, 0, course_import_service::LOCK_MAX_LIFETIME);
        $this->assertNotFalse($lock, 'The other process takes the lock of the target.');
        return [$other, $lock];
    }

    /**
     * End the other process: its connection closes, and the database frees its locks.
     *
     * @param \moodle_database $other Connection of the other process.
     * @param \core\lock\lock $lock Its lock, which can no longer be released through it.
     */
    private function kill_other_process(\moodle_database $other, \core\lock\lock $lock): void {
        // Read the factory first: a successful release() drops the lock's reference to it.
        $factory = (fn() => $this->factory)->call($lock);
        if ($factory instanceof \core\lock\timing_wrapper_lock_factory) {
            $factory = $factory->get_real_factory();
        }

        $other->dispose();
        try {
            $lock->release();
        } catch (\Throwable $e) {
            // Expected on some drivers: the connection is gone, and the database freed the lock with it.
            unset($e);
        }

        // Whether release() threw or not, the factory may still list the lock, and its shutdown
        // handler would try to release it again through the closed connection, which fails the run
        // after all tests passed. It forgets it, as the dead process would have. The MySQL factory
        // lists its locks in openlocks and the PostgreSQL factory in resourcetokens.
        foreach (['openlocks', 'resourcetokens'] as $property) {
            if (property_exists($factory, $property)) {
                (fn() => $this->$property = [])->call($factory);
            }
        }
    }

    /**
     * Skip the test when the Datacurso AI provider that keeps the plugin state is not installed.
     *
     * The queued task does nothing while the plugin is not enabled in the provider, and the CI
     * pipeline does not install declared dependencies.
     */
    private function require_datacurso_provider(): void {
        if (!class_exists(\aiprovider_datacurso\local\plugin_settings::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed.');
        }
    }

    /**
     * An import running in another process blocks a second import on the same target.
     */
    public function test_import_held_by_another_process_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$target, $staging] = $this->create_course_pair();
        $session = $this->create_session(get_admin()->id, $staging->id);
        $coursecount = $DB->count_records('course');

        [$other, $lock] = $this->hold_target_in_another_process((int)$target->id);
        try {
            $result = course_import_service::import_into_existing(
                $session->get('id'),
                $staging->id,
                $target->id,
                get_admin()->id
            );
        } finally {
            $lock->release();
            $other->dispose();
        }

        $this->assertFalse($result['success']);
        $this->assertSame(course_import_service::ERROR_IN_PROGRESS, $result['errorclass']);
        $this->assert_target_untouched($target);
        $this->assertSame($coursecount, $DB->count_records('course'));
    }

    /**
     * When the process of an import dies, the database frees its lock and the recovery rolls it back.
     */
    public function test_import_of_a_dead_process_is_rolled_back_once_its_lock_is_freed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$target, $staging] = $this->create_course_pair();
        $session = $this->interrupt_import($target, $staging);
        $copyid = (int)$this->import_data($session->get('id'))['copycourseid'];

        [$other, $lock] = $this->hold_target_in_another_process((int)$target->id);
        $whilealive = course_import_service::recover_interrupted_imports();
        $this->kill_other_process($other, $lock);
        $afterdeath = course_import_service::recover_interrupted_imports();

        $this->assertSame(0, $whilealive, 'An import whose process is alive is left alone.');
        $this->assertSame(1, $afterdeath, 'The lock of a dead process is free, so its import is rolled back.');
        $this->assert_target_untouched($target);
        $this->assertFalse($DB->record_exists('course', ['id' => $copyid]));
    }

    /**
     * The copy takes the names and the idnumber; the original keeps the people and is hidden without idnumber.
     */
    public function test_copy_takes_the_idnumber_and_keeps_the_roles(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$target, $staging] = $this->create_course_pair();
        $generator = $this->getDataGenerator();
        $student = $generator->create_user();
        $teacher = $generator->create_user();
        $generator->enrol_user($student->id, $target->id, 'student');
        $generator->enrol_user($teacher->id, $target->id, 'editingteacher');
        $session = $this->create_session(get_admin()->id, $staging->id);

        $result = course_import_service::import_into_existing($session->get('id'), $staging->id, $target->id, get_admin()->id);

        $this->assertTrue($result['success']);
        $copy = $DB->get_record('course', ['id' => $result['copycourseid']], '*', MUST_EXIST);
        $original = $DB->get_record('course', ['id' => $target->id], '*', MUST_EXIST);
        $this->assertSame('SIS-ORIG-101', $copy->idnumber);
        $this->assertSame('', $original->idnumber);
        $this->assertEquals(0, $original->visible);

        $copycontext = \context_course::instance($copy->id);
        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $teacherrole = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $this->assertTrue(user_has_role_assignment($student->id, $studentrole, $copycontext->id));
        $this->assertTrue(user_has_role_assignment($teacher->id, $teacherrole, $copycontext->id));
    }

    /**
     * A failed import gives the original its idnumber back.
     */
    public function test_failed_import_gives_the_idnumber_back(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$target, $staging] = $this->create_course_pair();
        $session = $this->create_session(get_admin()->id, $staging->id);

        $result = testable_course_import_service::import_into_existing(
            $session->get('id'),
            $staging->id,
            $target->id,
            get_admin()->id
        );

        $this->assertFalse($result['success']);
        $this->assert_target_untouched($target);
    }

    /**
     * Run an import whose process dies before the rollback, and check what it leaves behind.
     *
     * @param \stdClass $target Target course.
     * @param \stdClass $staging Staging course.
     * @return course_session The session of the interrupted import.
     */
    private function interrupt_import(\stdClass $target, \stdClass $staging): course_session {
        global $DB;
        $session = $this->create_session(get_admin()->id, $staging->id);
        try {
            testable_interrupted_import_service::import_into_existing(
                $session->get('id'),
                $staging->id,
                $target->id,
                get_admin()->id
            );
            $this->fail('The simulated process death must escape the import.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Process died', $e->getMessage());
        }

        $import = $this->import_data($session->get('id'));
        $this->assertSame(course_import_service::STATE_RUNNING, $import['state']);
        $this->assertGreaterThan(0, $import['copycourseid'], 'The copy is recorded with the rename.');
        $this->assertNotSame('ORIG-101', $DB->get_field('course', 'shortname', ['id' => $target->id]));
        $this->assertTrue($DB->record_exists('course', ['id' => $import['copycourseid']]));
        return $session;
    }

    /**
     * An import whose process died is rolled back by the scheduled recovery.
     */
    public function test_interrupted_import_is_rolled_back_by_the_scheduled_task(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$target, $staging] = $this->create_course_pair();
        $session = $this->interrupt_import($target, $staging);
        $copyid = (int)$this->import_data($session->get('id'))['copycourseid'];

        ob_start();
        (new recover_interrupted_imports())->execute();
        ob_end_clean();

        $this->assert_target_untouched($target);
        $this->assertFalse($DB->record_exists('course', ['id' => $copyid]), 'The partial copy is deleted.');
        $this->assertTrue($DB->record_exists('course', ['id' => $staging->id]), 'The staging course is kept.');
        $import = $this->import_data($session->get('id'));
        $this->assertSame(course_import_service::STATE_FAILED, $import['state']);
        $this->assertSame(course_import_service::ERROR_INTERRUPTED, $import['errorclass']);
        $this->assertSame(0, course_import_service::recover_interrupted_imports(), 'A recovered import is not recovered again.');
    }

    /**
     * The recovery leaves alone an import still at work, whose lock is held.
     */
    public function test_recovery_leaves_an_import_at_work_alone(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$target, $staging] = $this->create_course_pair();
        $session = $this->interrupt_import($target, $staging);

        $atwork = course_import_service::acquire_target_lock((int)$target->id);
        $recovered = course_import_service::recover_interrupted_imports();
        $atwork->release();

        $this->assertSame(0, $recovered);
        $this->assertSame(course_import_service::STATE_RUNNING, $this->import_data($session->get('id'))['state']);
    }

    /**
     * Retrying an interrupted import undoes it first and then imports from the original course.
     */
    public function test_retry_of_an_interrupted_import_starts_from_the_original(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$target, $staging] = $this->create_course_pair();
        $session = $this->interrupt_import($target, $staging);
        $deadcopyid = (int)$this->import_data($session->get('id'))['copycourseid'];

        course_import_service::mark_queued($session->get('id'), $staging->id, $target->id);
        $this->assertSame(
            course_import_service::STATE_RUNNING,
            $this->import_data($session->get('id'))['state'],
            'Queuing again keeps the bookkeeping of the interrupted attempt.'
        );
        $result = course_import_service::import_into_existing($session->get('id'), $staging->id, $target->id, get_admin()->id);

        $this->assertTrue($result['success']);
        $this->assertFalse($DB->record_exists('course', ['id' => $deadcopyid]));
        $copy = $DB->get_record('course', ['id' => $result['copycourseid']], '*', MUST_EXIST);
        $this->assertSame('ORIG-101', $copy->shortname);
        $this->assertSame('Original course', $copy->fullname);
        $this->assertSame(1, $DB->count_records('course', ['shortname' => 'ORIG-101']));
        $original = $DB->get_record('course', ['id' => $target->id], '*', MUST_EXIST);
        $this->assertStringStartsWith('ORIG-101.', $original->shortname, 'The original is renamed from its own names.');
    }

    /**
     * A backup that fails after it was stored leaves no backup with user data behind.
     */
    public function test_failed_backup_leaves_no_stored_backup(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$target, $staging] = $this->create_course_pair();
        $session = $this->create_session(get_admin()->id, $staging->id);

        $result = testable_failing_backup_import_service::import_into_existing(
            $session->get('id'),
            $staging->id,
            $target->id,
            get_admin()->id
        );

        $this->assertFalse($result['success']);
        $files = get_file_storage()->get_area_files(
            \context_course::instance($target->id)->id,
            'backup',
            'course',
            false,
            'id',
            false
        );
        $this->assertSame([], $files);
        $this->assert_target_untouched($target);
    }

    /**
     * Queue an import task for the admin and run it.
     *
     * @param course_session $session Planning session.
     * @param \stdClass $staging Staging course.
     * @param \stdClass $target Target course.
     * @param int $userid User the task runs as.
     * @return void
     */
    private function run_import_task(course_session $session, \stdClass $staging, \stdClass $target, int $userid): void {
        $task = new import_generated_course_task();
        $task->set_custom_data([
            'sessionid' => $session->get('id'),
            'stagingcourseid' => $staging->id,
            'targetcourseid' => $target->id,
        ]);
        $task->set_userid($userid);
        ob_start();
        $task->execute();
        ob_end_clean();
    }

    /**
     * The queued task does nothing once the feature has been switched off.
     */
    public function test_task_refuses_once_the_feature_is_disabled(): void {
        $this->require_datacurso_provider();
        $this->resetAfterTest();
        $this->setAdminUser();
        [$target, $staging] = $this->create_course_pair();
        $session = $this->create_session(get_admin()->id, $staging->id);
        set_config('enableimportexisting', 0, 'local_coursegen');

        $this->run_import_task($session, $staging, $target, (int)get_admin()->id);

        $this->assert_target_untouched($target);
        $import = $this->import_data($session->get('id'));
        $this->assertSame(course_import_service::STATE_FAILED, $import['state']);
        $this->assertSame('error_import_existing_disabled', $import['errorclass']);
    }

    /**
     * The queued task does nothing once the user may no longer create courses with AI.
     */
    public function test_task_refuses_once_createcoursewithai_is_gone(): void {
        $this->require_datacurso_provider();
        $this->resetAfterTest();
        [$target, $staging, $category] = $this->create_course_pair();
        set_config('enableimportexisting', 1, 'local_coursegen');
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Importer', 'testimporter', '');
        $categorycontext = \context_coursecat::instance($category->id);
        foreach (
            array_merge(
                import_permissions::COURSE_CAPABILITIES,
                import_permissions::CATEGORY_CAPABILITIES,
                ['moodle/backup:backuptargetimport']
            ) as $capability
        ) {
            assign_capability($capability, CAP_ALLOW, $roleid, $categorycontext->id, true);
        }
        role_assign($roleid, $user->id, $categorycontext->id);
        $session = $this->create_session($user->id, $staging->id);

        $this->run_import_task($session, $staging, $target, (int)$user->id);

        $this->assert_target_untouched($target);
        $this->assertSame(course_import_service::STATE_FAILED, $this->import_data($session->get('id'))['state']);
    }

    /**
     * The queued task marks the import failed, instead of failing itself, when the target permissions are gone.
     */
    public function test_task_refuses_once_the_target_permissions_are_gone(): void {
        $this->require_datacurso_provider();
        $this->resetAfterTest();
        [$target, $staging] = $this->create_course_pair();
        set_config('enableimportexisting', 1, 'local_coursegen');
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Creator only', 'testcreator', '');
        assign_capability('local/coursegen:createcoursewithai', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        $session = $this->create_session($user->id, $staging->id);

        $this->run_import_task($session, $staging, $target, (int)$user->id);

        $this->assert_target_untouched($target);
        $import = $this->import_data($session->get('id'));
        $this->assertSame(course_import_service::STATE_FAILED, $import['state']);
        // Namespaced from Moodle 5.0 (core\exception), global on Moodle 4.5.
        $this->assertStringEndsWith('required_capability_exception', $import['errorclass']);
    }

    /**
     * The import needs the right to update the target and to change both of its names.
     *
     * @dataProvider target_change_capabilities_provider
     * @param string $capability Capability left out.
     */
    public function test_import_requires_changing_the_target(string $capability): void {
        $this->resetAfterTest();
        [$target, $staging, $category] = $this->create_course_pair();
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Importer', 'testimporter', '');
        $categorycontext = \context_coursecat::instance($category->id);
        $capabilities = array_merge(
            import_permissions::COURSE_CAPABILITIES,
            import_permissions::CATEGORY_CAPABILITIES,
            ['moodle/backup:backuptargetimport']
        );
        foreach (array_diff($capabilities, [$capability]) as $allowed) {
            assign_capability($allowed, CAP_ALLOW, $roleid, $categorycontext->id, true);
        }
        role_assign($roleid, $user->id, $categorycontext->id);
        $this->setUser($user);
        $session = $this->create_session($user->id, $staging->id);

        try {
            course_import_service::import_into_existing($session->get('id'), $staging->id, $target->id, $user->id);
            $this->fail('The import must require ' . $capability . '.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string($capability), $e->a);
        }
        $this->assert_target_untouched($target);
    }

    /**
     * Capabilities that change the target course.
     *
     * @return array
     */
    public static function target_change_capabilities_provider(): array {
        return [
            'update' => ['moodle/course:update'],
            'shortname' => ['moodle/course:changeshortname'],
            'fullname' => ['moodle/course:changefullname'],
        ];
    }

    /**
     * With the feature off only the creator role is created; enabling it creates the importer, never site-wide.
     */
    public function test_importer_role_only_exists_once_the_feature_is_enabled(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->delete_records_select(
            'role',
            'shortname IN (?, ?)',
            [role_installer::CREATOR_SHORTNAME, role_installer::IMPORTER_SHORTNAME]
        );
        set_config('enableimportexisting', 0, 'local_coursegen');

        $this->assertSame([role_installer::CREATOR_SHORTNAME], role_installer::install_missing_roles());
        $this->assertFalse($DB->record_exists('role', ['shortname' => role_installer::IMPORTER_SHORTNAME]));

        set_config('enableimportexisting', 1, 'local_coursegen');
        $this->assertSame([role_installer::IMPORTER_SHORTNAME], role_installer::install_missing_roles());
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => role_installer::IMPORTER_SHORTNAME], MUST_EXIST);
        $levels = array_map('intval', get_role_contextlevels($roleid));
        sort($levels);
        $this->assertSame([CONTEXT_COURSECAT, CONTEXT_COURSE], $levels);
    }

    /**
     * The upgrade stops an existing importer role from being assignable site-wide.
     */
    public function test_existing_importer_role_is_no_longer_assignable_site_wide(): void {
        global $DB;
        $this->resetAfterTest();
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => role_installer::IMPORTER_SHORTNAME]);
        if (!$roleid) {
            $roleid = create_role('Importer', role_installer::IMPORTER_SHORTNAME, '', 'editingteacher');
        }
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM, CONTEXT_COURSE, CONTEXT_MODULE]);

        $this->assertTrue(role_installer::restrict_importer_context_levels());

        $levels = array_map('intval', get_role_contextlevels($roleid));
        sort($levels);
        $this->assertSame([CONTEXT_COURSECAT, CONTEXT_COURSE], $levels);
    }
}
