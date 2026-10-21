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

/**
 * Tests for the single source of truth of the import-into-existing-course capability set (ticket #9539).
 *
 * A user who cannot complete an import must not be offered the option, so the
 * same capability set drives the page flag, the target search and the
 * server-side checks.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\import_permissions
 */
final class import_permissions_test extends \advanced_testcase {
    /** @var string[] The capabilities checked on the target course context. */
    private const COURSE_CAPABILITIES = [
        'moodle/backup:backupcourse',
        'moodle/restore:restorecourse',
        'moodle/course:visibility',
        'moodle/backup:userinfo',
    ];

    /** @var string[] The capabilities checked on the target's category context. */
    private const CATEGORY_CAPABILITIES = [
        'moodle/course:create',
        'moodle/restore:userinfo',
    ];

    /**
     * Create a role allowing the given capabilities in a context and assign it to the user there.
     *
     * @param int $userid User id.
     * @param string[] $capabilities Capabilities to allow.
     * @param \context $context Context of the role assignment and of the overrides.
     * @return void
     */
    private function grant(int $userid, array $capabilities, \context $context): void {
        static $counter = 0;
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'importer' . (++$counter)]);
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        }
        role_assign($roleid, $userid, $context->id);
    }

    /**
     * The six capabilities the import needs.
     *
     * @return string[]
     */
    private static function all_capabilities(): array {
        return array_merge(self::COURSE_CAPABILITIES, self::CATEGORY_CAPABILITIES);
    }

    /**
     * The constants expose exactly the capabilities the import checks today, split by context.
     */
    public function test_constants_list_the_import_capability_set(): void {
        $this->assertSame(self::COURSE_CAPABILITIES, import_permissions::COURSE_CAPABILITIES);
        $this->assertSame(self::CATEGORY_CAPABILITIES, import_permissions::CATEGORY_CAPABILITIES);
    }

    /**
     * A user holding the full set (assigned at system level) may import into any course.
     */
    public function test_can_import_into_is_true_with_the_full_set(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->grant($user->id, self::all_capabilities(), \context_system::instance());

        $this->assertTrue(import_permissions::can_import_into($course, $user->id));
    }

    /**
     * Each capability of the set, one per case.
     *
     * @return array[]
     */
    public static function capability_provider(): array {
        $cases = [];
        foreach (self::all_capabilities() as $capability) {
            $cases[$capability] = [$capability];
        }
        return $cases;
    }

    /**
     * Missing any single capability of the set denies the import into the course.
     *
     * @dataProvider capability_provider
     * @param string $missing The one capability the user lacks.
     */
    public function test_can_import_into_is_false_when_one_capability_is_missing(string $missing): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->grant($user->id, array_diff(self::all_capabilities(), [$missing]), \context_system::instance());

        $this->assertFalse(import_permissions::can_import_into($course, $user->id), 'Missing ' . $missing);
    }

    /**
     * A user without any of the capabilities cannot import anywhere, even though courses exist.
     */
    public function test_can_import_anywhere_is_false_for_user_without_capabilities(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_course();

        $this->assertFalse(import_permissions::can_import_anywhere($user->id));
    }

    /**
     * Capabilities whose absence in every course must make the scan come back empty: one that
     * is the seed of the candidate query and one that is only checked per candidate course.
     *
     * @return array[]
     */
    public static function missing_everywhere_provider(): array {
        return [
            'backup userinfo (seed of the candidate query)' => ['moodle/backup:userinfo'],
            'course visibility (checked per candidate)' => ['moodle/course:visibility'],
            'restore userinfo (category side)' => ['moodle/restore:userinfo'],
        ];
    }

    /**
     * Holding all but one capability in every course is not enough to be offered the import.
     *
     * @dataProvider missing_everywhere_provider
     * @param string $missing The one capability the user lacks everywhere.
     */
    public function test_can_import_anywhere_is_false_when_every_course_lacks_one_capability(string $missing): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_course();
        $this->grant($user->id, array_diff(self::all_capabilities(), [$missing]), \context_system::instance());

        $this->assertFalse(import_permissions::can_import_anywhere($user->id));
    }

    /**
     * One course granting the full set (course capabilities on the course, category ones on
     * its category) is enough, whatever the other courses grant.
     */
    public function test_can_import_anywhere_is_true_when_one_course_grants_the_full_set(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $category = $generator->create_category();
        $generator->create_course();
        $importable = $generator->create_course(['category' => $category->id]);
        $this->grant($user->id, self::COURSE_CAPABILITIES, \context_course::instance($importable->id));
        $this->grant($user->id, self::CATEGORY_CAPABILITIES, \context_coursecat::instance($category->id));

        $this->assertTrue(import_permissions::can_import_anywhere($user->id));
    }

    /**
     * The site course is never an import target, so capabilities held only there do not count.
     */
    public function test_can_import_anywhere_ignores_the_site_course(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->grant($user->id, self::COURSE_CAPABILITIES, \context_course::instance(SITEID));
        $this->grant($user->id, self::CATEGORY_CAPABILITIES, \context_system::instance());

        $this->assertFalse(import_permissions::can_import_anywhere($user->id));
    }

    /**
     * Site administrators may always import.
     */
    public function test_can_import_anywhere_is_true_for_admin(): void {
        $this->resetAfterTest();
        $admin = get_admin();

        $this->assertTrue(import_permissions::can_import_anywhere($admin->id));
    }

    /**
     * Without a user id the current user is checked.
     */
    public function test_methods_default_to_the_current_user(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->grant($user->id, self::all_capabilities(), \context_system::instance());
        $this->setUser($user);

        $this->assertTrue(import_permissions::can_import_into($course));
        $this->assertTrue(import_permissions::can_import_anywhere());
        import_permissions::require_for_target($course);
        $this->assertTrue(import_permissions::is_offered() === false, 'Setting off: not offered');
        set_config('enableimportexisting', 1, 'local_coursegen');
        $this->assertTrue(import_permissions::is_offered());
    }

    /**
     * Held capabilities and the capability expected to be reported first, in today's check
     * order: backupcourse, restorecourse, course:create, visibility, backup:userinfo, restore:userinfo.
     *
     * @return array[]
     */
    public static function first_missing_provider(): array {
        return [
            'nothing held' => [[], 'moodle/backup:backupcourse'],
            'backup only' => [['moodle/backup:backupcourse'], 'moodle/restore:restorecourse'],
            'backup and restore' => [
                ['moodle/backup:backupcourse', 'moodle/restore:restorecourse'],
                'moodle/course:create',
            ],
            'backup, restore and create' => [
                ['moodle/backup:backupcourse', 'moodle/restore:restorecourse', 'moodle/course:create'],
                'moodle/course:visibility',
            ],
            'all but backup userinfo' => [
                ['moodle/backup:backupcourse', 'moodle/restore:restorecourse', 'moodle/course:create',
                    'moodle/course:visibility', 'moodle/restore:userinfo'],
                'moodle/backup:userinfo',
            ],
            'all but restore userinfo' => [
                ['moodle/backup:backupcourse', 'moodle/restore:restorecourse', 'moodle/course:create',
                    'moodle/course:visibility', 'moodle/backup:userinfo'],
                'moodle/restore:userinfo',
            ],
        ];
    }

    /**
     * The exception names the first missing capability in the order the checks always ran.
     *
     * @dataProvider first_missing_provider
     * @param string[] $held Capabilities granted at system level.
     * @param string $expected Capability the exception must name.
     */
    public function test_require_for_target_names_the_first_missing_capability(array $held, string $expected): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->grant($user->id, $held, \context_system::instance());

        try {
            import_permissions::require_for_target($course, $user->id);
            $this->fail('A missing capability must be reported.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string($expected), $e->a);
        }
    }

    /**
     * The full set passes the check silently.
     */
    public function test_require_for_target_passes_with_the_full_set(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->grant($user->id, self::all_capabilities(), \context_system::instance());

        import_permissions::require_for_target($course, $user->id);
        $this->assertTrue(true, 'No exception thrown');
    }

    /**
     * The option is offered only when the site setting is on and the user can import somewhere.
     */
    public function test_is_offered_requires_the_setting_and_an_importable_course(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $generator->create_course();
        $admin = get_admin();
        $able = $generator->create_user();
        $this->grant($able->id, self::all_capabilities(), \context_system::instance());
        $unable = $generator->create_user();
        $this->grant($unable->id, ['local/coursegen:createcoursewithai', 'moodle/course:create'], \context_system::instance());

        set_config('enableimportexisting', 0, 'local_coursegen');
        $this->assertFalse(import_permissions::is_offered($admin->id), 'Setting off: never offered');
        $this->assertFalse(import_permissions::is_offered($able->id), 'Setting off: never offered');

        set_config('enableimportexisting', 1, 'local_coursegen');
        $this->assertTrue(import_permissions::is_offered($admin->id));
        $this->assertTrue(import_permissions::is_offered($able->id));
        $this->assertFalse(import_permissions::is_offered($unable->id), 'Page gate alone does not offer the import');
    }
}
