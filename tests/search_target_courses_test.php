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

use core_external\external_api;
use local_coursegen\external\search_target_courses;

/**
 * Contract tests for the target course search web service (ticket #9539).
 *
 * The service lists the courses a user may import an AI-generated course
 * into: courses inside the requested category tree on which the user holds
 * the full import capability set (import_permissions), so the list only
 * offers courses the import will accept.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\search_target_courses
 */
final class search_target_courses_test extends \advanced_testcase {
    /** @var \core_course_category Category A, the one the user may create courses in. */
    private \core_course_category $categorya;

    /** @var \core_course_category Category A1, a sub-category of A. */
    private \core_course_category $categorya1;

    /** @var \core_course_category Category B, outside the user's reach. */
    private \core_course_category $categoryb;

    /** @var \stdClass Course in A, full import set granted. */
    private \stdClass $coursealpha;

    /** @var \stdClass Course in A, full import set granted. */
    private \stdClass $coursebeta;

    /** @var \stdClass Course in A1, full import set granted. */
    private \stdClass $coursegamma;

    /** @var \stdClass Course in A, restore granted but backup missing. */
    private \stdClass $coursenobackup;

    /** @var \stdClass Course in A, backup granted but restore missing. */
    private \stdClass $coursenorestore;

    /** @var \stdClass Course in A, backup and restore granted but moodle/backup:userinfo missing. */
    private \stdClass $coursenouserinfo;

    /** @var \stdClass Course in B, full import set granted. */
    private \stdClass $coursedelta;

    /** @var \stdClass The user under test. */
    private \stdClass $user;

    /**
     * Enable the feature and build the category / course / role fixture.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('enableimportexisting', 1, 'local_coursegen');

        $generator = $this->getDataGenerator();

        $this->categorya = $generator->create_category(['name' => 'Category A']);
        $this->categorya1 = $generator->create_category(['name' => 'Category A1', 'parent' => $this->categorya->id]);
        $this->categoryb = $generator->create_category(['name' => 'Category B']);

        $this->coursealpha = $generator->create_course([
            'category' => $this->categorya->id,
            'fullname' => 'Alpha Physics',
            'shortname' => 'PHY-ALPHA',
        ]);
        $this->coursebeta = $generator->create_course([
            'category' => $this->categorya->id,
            'fullname' => 'Beta Chemistry',
            'shortname' => 'CHEM-BETA',
        ]);
        $this->coursegamma = $generator->create_course([
            'category' => $this->categorya1->id,
            'fullname' => 'Gamma Biology',
            'shortname' => 'BIO-GAMMA',
        ]);
        $this->coursenobackup = $generator->create_course([
            'category' => $this->categorya->id,
            'fullname' => 'No Backup Course',
            'shortname' => 'NOBACKUP',
        ]);
        $this->coursenorestore = $generator->create_course([
            'category' => $this->categorya->id,
            'fullname' => 'No Restore Course',
            'shortname' => 'NORESTORE',
        ]);
        $this->coursenouserinfo = $generator->create_course([
            'category' => $this->categorya->id,
            'fullname' => 'No Userinfo Course',
            'shortname' => 'NOUSERINFO',
        ]);
        $this->coursedelta = $generator->create_course([
            'category' => $this->categoryb->id,
            'fullname' => 'Delta Physics',
            'shortname' => 'PHY-DELTA',
        ]);

        $this->user = $generator->create_user();

        $this->allow_createcoursewithai_at_system($this->user->id);
        $this->allow_course_create_in_category($this->user->id, (int) $this->categorya->id);

        $this->grant_in_course($this->user->id, (int) $this->coursealpha->id, true, true);
        $this->grant_in_course($this->user->id, (int) $this->coursebeta->id, true, true);
        $this->grant_in_course($this->user->id, (int) $this->coursegamma->id, true, true);
        $this->grant_in_course($this->user->id, (int) $this->coursenobackup->id, false, true);
        $this->grant_in_course($this->user->id, (int) $this->coursenorestore->id, true, false);
        $this->grant_in_course($this->user->id, (int) $this->coursenouserinfo->id, true, true, false);
        $this->grant_in_course($this->user->id, (int) $this->coursedelta->id, true, true);
    }

    /**
     * Give the user local/coursegen:createcoursewithai at system level.
     *
     * @param int $userid User id.
     * @return void
     */
    private function allow_createcoursewithai_at_system(int $userid): void {
        $systemcontext = \context_system::instance();
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'aicoursecreator']);
        assign_capability('local/coursegen:createcoursewithai', CAP_ALLOW, $roleid, $systemcontext->id, true);
        role_assign($roleid, $userid, $systemcontext->id);
    }

    /**
     * Give the user the category side of the import set (course:create, restore:userinfo) in one category only.
     *
     * @param int $userid User id.
     * @param int $categoryid Category where the user may create courses.
     * @return void
     */
    private function allow_course_create_in_category(int $userid, int $categoryid): void {
        $categorycontext = \context_coursecat::instance($categoryid);
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'catcoursecreator' . $userid]);
        assign_capability('moodle/course:create', CAP_ALLOW, $roleid, $categorycontext->id, true);
        assign_capability('moodle/restore:userinfo', CAP_ALLOW, $roleid, $categorycontext->id, true);
        role_assign($roleid, $userid, $categorycontext->id);
    }

    /**
     * Assign a per-course custom role granting the requested course-side import capabilities.
     *
     * moodle/course:visibility is always granted; the three flags leave one capability out each.
     *
     * @param int $userid User id.
     * @param int $courseid Course id.
     * @param bool $backup Whether to grant moodle/backup:backupcourse.
     * @param bool $restore Whether to grant moodle/restore:restorecourse.
     * @param bool $userinfo Whether to grant moodle/backup:userinfo.
     * @return void
     */
    private function grant_in_course(int $userid, int $courseid, bool $backup, bool $restore, bool $userinfo = true): void {
        $coursecontext = \context_course::instance($courseid);
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'copier' . $courseid]);
        assign_capability('moodle/course:visibility', CAP_ALLOW, $roleid, $coursecontext->id, true);
        if ($backup) {
            assign_capability('moodle/backup:backupcourse', CAP_ALLOW, $roleid, $coursecontext->id, true);
        }
        if ($restore) {
            assign_capability('moodle/restore:restorecourse', CAP_ALLOW, $roleid, $coursecontext->id, true);
        }
        if ($userinfo) {
            assign_capability('moodle/backup:userinfo', CAP_ALLOW, $roleid, $coursecontext->id, true);
        }
        role_assign($roleid, $userid, $coursecontext->id);
    }

    /**
     * Call the service and return the list of returned course ids.
     *
     * @param int $categoryid Category id.
     * @param string $query Search text.
     * @param int $limit Maximum number of results.
     * @return int[]
     */
    private function returned_ids(int $categoryid, string $query = '', int $limit = 20): array {
        $result = search_target_courses::execute($categoryid, $query, $limit);
        return array_map(static fn(array $course): int => (int) $course['id'], $result);
    }

    /**
     * With the feature switched off the service refuses every caller, admin included.
     */
    public function test_disabled_setting_rejects_even_admin(): void {
        set_config('enableimportexisting', 0, 'local_coursegen');
        $this->setAdminUser();

        try {
            search_target_courses::execute((int) $this->categorya->id, '', 20);
            $this->fail('A disabled setting must reject the call.');
        } catch (\moodle_exception $e) {
            // Anchor on the error code, not on the translated message.
            $this->assertSame('error_import_existing_disabled', $e->errorcode);
            $this->assertSame('local_coursegen', $e->module);
        }
    }

    /**
     * A user without local/coursegen:createcoursewithai is rejected.
     */
    public function test_missing_createcoursewithai_is_rejected(): void {
        $other = $this->getDataGenerator()->create_user();
        $this->allow_course_create_in_category($other->id, (int) $this->categorya->id);
        $this->setUser($other);

        $this->expectException(\required_capability_exception::class);
        search_target_courses::execute((int) $this->categorya->id, '', 20);
    }

    /**
     * A user without moodle/course:create on the requested category is rejected.
     */
    public function test_missing_course_create_on_category_is_rejected(): void {
        $this->setUser($this->user);

        $this->expectException(\required_capability_exception::class);
        search_target_courses::execute((int) $this->categoryb->id, '', 20);
    }

    /**
     * Courses from the category and its sub-categories are listed; other
     * categories and the site course never are.
     */
    public function test_lists_courses_recursively_within_category(): void {
        $this->setUser($this->user);

        $ids = $this->returned_ids((int) $this->categorya->id);

        $this->assertContains((int) $this->coursealpha->id, $ids);
        $this->assertContains((int) $this->coursebeta->id, $ids);
        $this->assertContains((int) $this->coursegamma->id, $ids, 'Sub-category course must be listed');
        $this->assertNotContains((int) $this->coursedelta->id, $ids, 'Course from another category must not be listed');
        $this->assertNotContains(SITEID, $ids, 'Site course must never be listed');
    }

    /**
     * Courses where either backup or restore is missing are excluded.
     */
    public function test_excludes_courses_missing_backup_or_restore(): void {
        $this->setUser($this->user);

        $ids = $this->returned_ids((int) $this->categorya->id);

        $this->assertNotContains((int) $this->coursenobackup->id, $ids, 'Course without backupcourse must be excluded');
        $this->assertNotContains((int) $this->coursenorestore->id, $ids, 'Course without restorecourse must be excluded');
        $this->assertEqualsCanonicalizing(
            [(int) $this->coursealpha->id, (int) $this->coursebeta->id, (int) $this->coursegamma->id],
            $ids
        );
    }

    /**
     * Backup and restore alone are not enough: a course where the user lacks
     * moodle/backup:userinfo is excluded, because the import would reject it.
     */
    public function test_excludes_courses_missing_backup_userinfo(): void {
        $this->setUser($this->user);

        $ids = $this->returned_ids((int) $this->categorya->id);

        $this->assertNotContains((int) $this->coursenouserinfo->id, $ids, 'Course without backup:userinfo must be excluded');
        $this->assertContains((int) $this->coursealpha->id, $ids, 'Course with the full set must be listed');
    }

    /**
     * The query matches fullname and shortname, case-insensitively.
     *
     * @dataProvider query_provider
     * @param string $query Search text.
     * @param string[] $expectedshortnames Shortnames expected in the result.
     */
    public function test_query_filters_by_fullname_and_shortname_case_insensitively(
        string $query,
        array $expectedshortnames
    ): void {
        $this->setUser($this->user);

        $result = search_target_courses::execute((int) $this->categorya->id, $query, 20);
        $shortnames = array_map(static fn(array $course): string => $course['shortname'], $result);

        $this->assertEqualsCanonicalizing($expectedshortnames, $shortnames);
    }

    /**
     * Query cases: fullname match, shortname match, mixed case, empty query.
     *
     * @return array[]
     */
    public static function query_provider(): array {
        return [
            'fullname, lower case' => ['physics', ['PHY-ALPHA']],
            'fullname, mixed case' => ['bIoLoGy', ['BIO-GAMMA']],
            'shortname, lower case' => ['chem-', ['CHEM-BETA']],
            'shortname, upper case' => ['PHY', ['PHY-ALPHA']],
            'no match' => ['zzz-none', []],
            'empty query returns all' => ['', ['PHY-ALPHA', 'CHEM-BETA', 'BIO-GAMMA']],
        ];
    }

    /**
     * The limit is applied after permission filtering.
     */
    public function test_limit_is_honoured(): void {
        $this->setUser($this->user);

        $ids = $this->returned_ids((int) $this->categorya->id, '', 2);

        $this->assertCount(2, $ids);
        $this->assertEmpty(array_diff($ids, [
            (int) $this->coursealpha->id,
            (int) $this->coursebeta->id,
            (int) $this->coursegamma->id,
        ]), 'Only permitted courses may fill the limited result');
    }

    /**
     * A limit of 0 is clamped to 1.
     */
    public function test_limit_zero_is_clamped_to_one(): void {
        $this->setUser($this->user);

        $ids = $this->returned_ids((int) $this->categorya->id, '', 0);

        $this->assertCount(1, $ids);
    }

    /**
     * A limit above 50 is clamped to 50.
     */
    public function test_limit_above_fifty_is_clamped_to_fifty(): void {
        $this->setUser($this->user);

        // 51 permitted courses in A: the 3 from the fixture plus 48 more.
        $generator = $this->getDataGenerator();
        for ($i = 0; $i < 48; $i++) {
            $course = $generator->create_course([
                'category' => $this->categorya->id,
                'fullname' => 'Bulk course ' . $i,
                'shortname' => 'BULK-' . $i,
            ]);
            $this->grant_in_course($this->user->id, (int) $course->id, true, true);
        }

        $ids = $this->returned_ids((int) $this->categorya->id, '', 500);

        $this->assertCount(50, $ids);
    }

    /**
     * The returned structure validates against execute_returns() and carries
     * only the declared fields.
     */
    public function test_returned_structure_matches_declaration(): void {
        $this->setUser($this->user);

        $result = search_target_courses::execute((int) $this->categorya->id, 'alpha', 20);
        $cleaned = external_api::clean_returnvalue(search_target_courses::execute_returns(), $result);

        $this->assertCount(1, $cleaned);
        $course = $cleaned[0];
        $this->assertSame(['id', 'fullname', 'shortname', 'categoryid', 'displayname'], array_keys($course));
        $this->assertSame((int) $this->coursealpha->id, $course['id']);
        $this->assertSame('Alpha Physics', $course['fullname']);
        $this->assertSame('PHY-ALPHA', $course['shortname']);
        $this->assertSame((int) $this->categorya->id, $course['categoryid']);
        $this->assertSame('Alpha Physics (PHY-ALPHA)', $course['displayname']);
    }
}
