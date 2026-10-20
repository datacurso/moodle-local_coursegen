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
 * Tests for the single source of truth of the "create a course with AI" capability set (ticket #9539).
 *
 * The set is every capability the editingteacher archetype allows by default
 * plus four more. A user missing any one of them in a category cannot start
 * the creation there, and a user who can start it nowhere is not offered it.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\creation_permissions
 */
final class creation_permissions_test extends \advanced_testcase {
    /** @var string[] The four capabilities added on top of the archetype. */
    private const EXTRA = [
        'local/coursegen:createactivitywithai',
        'local/coursegen:createcoursewithai',
        'moodle/course:create',
        'moodle/course:view',
    ];

    /**
     * The expected set, computed independently from the archetype definitions.
     *
     * @return string[]
     */
    private static function expected_capabilities(): array {
        $defaults = get_default_capabilities('editingteacher');
        $allowed = array_keys(array_filter($defaults, static fn($permission): bool => (int) $permission === CAP_ALLOW));
        $all = array_unique(array_merge($allowed, self::EXTRA));
        $installed = array_values(array_filter($all, static fn(string $cap): bool => get_capability_info($cap) !== null));
        sort($installed, SORT_STRING);
        return $installed;
    }

    /**
     * Create a role holding the full set: archetype defaults plus the four extras.
     *
     * @return int Role id.
     */
    private function create_full_role(): int {
        return $this->getDataGenerator()->get_plugin_generator('local_coursegen')->create_course_creator_role();
    }

    /**
     * Create a user and assign them the full role in the given context.
     *
     * @param \context $context Context of the role assignment.
     * @return array{0: \stdClass, 1: int} The user and the role id.
     */
    private function create_user_with_full_role(\context $context): array {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->create_full_role();
        role_assign($roleid, $user->id, $context->id);
        return [$user, $roleid];
    }

    /**
     * Create a user holding the stock coursecreator role at system level, optionally with extra capabilities.
     *
     * @param string[] $extra Capabilities added to a second role assigned at system level.
     * @return \stdClass The user.
     */
    private function create_coursecreator_user(array $extra = []): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $systemcontext = \context_system::instance();
        $coursecreator = $DB->get_field('role', 'id', ['shortname' => 'coursecreator'], MUST_EXIST);
        role_assign($coursecreator, $user->id, $systemcontext->id);
        if ($extra) {
            $roleid = $this->getDataGenerator()->create_role(['shortname' => 'extra' . $user->id]);
            foreach ($extra as $capability) {
                assign_capability($capability, CAP_ALLOW, $roleid, $systemcontext->id, true);
            }
            role_assign($roleid, $user->id, $systemcontext->id);
        }
        return $user;
    }

    /**
     * The set is the archetype's CAP_ALLOW defaults plus the four extras, installed only, sorted and unique.
     */
    public function test_required_capabilities_are_the_archetype_defaults_plus_the_extras(): void {
        $required = creation_permissions::required_capabilities();

        $this->assertSame(self::expected_capabilities(), $required);
        $this->assertSame($required, array_values(array_unique($required)));
        $sorted = $required;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $required);
        foreach (self::EXTRA as $capability) {
            $this->assertContains($capability, $required);
        }
        foreach ($required as $capability) {
            $this->assertNotNull(get_capability_info($capability), $capability . ' must be installed');
        }
        $this->assertGreaterThan(100, count($required), 'The archetype grants far more than the four extras');
        $this->assertSame(['local/coursegen:createcoursewithai'], creation_permissions::SYSTEM_CAPABILITIES);
        $this->assertSame('editingteacher', creation_permissions::ARCHETYPE);
    }

    /**
     * The full role assigned at system level may create in any category.
     */
    public function test_can_create_in_is_true_for_the_full_role(): void {
        $this->resetAfterTest();
        [$user] = $this->create_user_with_full_role(\context_system::instance());
        $category = $this->getDataGenerator()->create_category();

        $this->assertTrue(creation_permissions::can_create_in((int) $category->id, (int) $user->id));
    }

    /**
     * The stock coursecreator role, even with the page capability, is not enough.
     */
    public function test_can_create_in_is_false_for_the_coursecreator_archetype(): void {
        $this->resetAfterTest();
        $user = $this->create_coursecreator_user(['local/coursegen:createcoursewithai']);
        $category = $this->getDataGenerator()->create_category();

        $this->assertFalse(creation_permissions::can_create_in((int) $category->id, (int) $user->id));
    }

    /**
     * A representative sample of the set across components, one per case.
     *
     * @return array[]
     */
    public static function sample_capability_provider(): array {
        $sample = [
            'moodle/course:create',
            'moodle/course:view',
            'local/coursegen:createcoursewithai',
            'local/coursegen:createactivitywithai',
            'moodle/course:manageactivities',
            'mod/quiz:addinstance',
            'mod/forum:startdiscussion',
            'moodle/question:add',
        ];
        $cases = [];
        foreach ($sample as $capability) {
            $cases[$capability] = [$capability];
        }
        return $cases;
    }

    /**
     * Removing any single capability from the full role denies the creation.
     *
     * @dataProvider sample_capability_provider
     * @param string $missing The one capability removed from the role.
     */
    public function test_can_create_in_is_false_when_one_capability_is_missing(string $missing): void {
        $this->resetAfterTest();
        [$user, $roleid] = $this->create_user_with_full_role(\context_system::instance());
        $category = $this->getDataGenerator()->create_category();
        $this->assertTrue(creation_permissions::can_create_in((int) $category->id, (int) $user->id));

        unassign_capability($missing, $roleid, \context_system::instance()->id);

        $this->assertFalse(creation_permissions::can_create_in((int) $category->id, (int) $user->id), 'Missing ' . $missing);
    }

    /**
     * Every capability of the set is load-bearing: prohibiting each one in turn denies the creation.
     *
     * Prohibit rather than unassign: a few of the capabilities (block/online_users:viewlist, ...)
     * are also granted by the stock Authenticated user role, so unassigning them from the
     * creator role alone would not take them away from the user.
     */
    public function test_can_create_in_is_false_whichever_capability_is_missing(): void {
        $this->resetAfterTest();
        [$user, $roleid] = $this->create_user_with_full_role(\context_system::instance());
        $systemcontextid = \context_system::instance()->id;
        $categoryid = (int) $this->getDataGenerator()->create_category()->id;
        $userid = (int) $user->id;

        foreach (creation_permissions::required_capabilities() as $capability) {
            assign_capability($capability, CAP_PROHIBIT, $roleid, $systemcontextid, true);
            $this->assertFalse(creation_permissions::can_create_in($categoryid, $userid), 'Missing ' . $capability);
            assign_capability($capability, CAP_ALLOW, $roleid, $systemcontextid, true);
        }
        $this->assertTrue(creation_permissions::can_create_in($categoryid, $userid), 'Full set restored');
    }

    /**
     * The full role held only in a course does not allow creating anywhere: the new course has no course parent.
     */
    public function test_can_create_anywhere_is_false_with_the_full_role_in_a_course_only(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        [$user] = $this->create_user_with_full_role(\context_course::instance($course->id));

        $this->assertFalse(creation_permissions::can_create_anywhere((int) $user->id));
    }

    /**
     * The full role in one category (and the system capability at system level) is enough.
     */
    public function test_can_create_anywhere_is_true_with_the_full_role_in_one_category(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $generator->create_category();
        $category = $generator->create_category();
        [$user] = $this->create_user_with_full_role(\context_coursecat::instance($category->id));
        $systemrole = $generator->create_role(['shortname' => 'pagegate']);
        assign_capability('local/coursegen:createcoursewithai', CAP_ALLOW, $systemrole, \context_system::instance()->id, true);
        role_assign($systemrole, $user->id, \context_system::instance()->id);

        $this->assertTrue(creation_permissions::can_create_anywhere((int) $user->id));
    }

    /**
     * The full role in a category without the system-level page capability is not enough.
     */
    public function test_can_create_anywhere_is_false_without_the_system_capability(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        [$user] = $this->create_user_with_full_role(\context_coursecat::instance($category->id));

        $this->assertFalse(creation_permissions::can_create_anywhere((int) $user->id));
    }

    /**
     * Site administrators may always create.
     */
    public function test_can_create_anywhere_is_true_for_admin(): void {
        $this->resetAfterTest();

        $this->assertTrue(creation_permissions::can_create_anywhere((int) get_admin()->id));
    }

    /**
     * A plain authenticated user cannot create anywhere.
     */
    public function test_can_create_anywhere_is_false_for_an_authenticated_user(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_category();

        $this->assertFalse(creation_permissions::can_create_anywhere((int) $user->id));
    }

    /**
     * The exception names the capability removed from the role, on the category context.
     */
    public function test_require_for_category_names_the_missing_capability(): void {
        $this->resetAfterTest();
        [$user, $roleid] = $this->create_user_with_full_role(\context_system::instance());
        $category = $this->getDataGenerator()->create_category();
        unassign_capability('mod/forum:startdiscussion', $roleid, \context_system::instance()->id);

        try {
            creation_permissions::require_for_category((int) $category->id, (int) $user->id);
            $this->fail('A missing capability must be reported.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('mod/forum:startdiscussion'), $e->a);
        }
    }

    /**
     * The system capability is reported first, on the system context, whatever else is missing.
     */
    public function test_require_for_category_reports_the_system_capability_first(): void {
        $this->resetAfterTest();
        [$user, $roleid] = $this->create_user_with_full_role(\context_system::instance());
        $category = $this->getDataGenerator()->create_category();
        $systemcontextid = \context_system::instance()->id;
        unassign_capability('local/coursegen:createcoursewithai', $roleid, $systemcontextid);
        unassign_capability('atto/h5p:addembed', $roleid, $systemcontextid);

        try {
            creation_permissions::require_for_category((int) $category->id, (int) $user->id);
            $this->fail('A missing capability must be reported.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('local/coursegen:createcoursewithai'), $e->a);
        }
    }

    /**
     * The full set passes the check silently.
     */
    public function test_require_for_category_passes_with_the_full_set(): void {
        $this->resetAfterTest();
        [$user] = $this->create_user_with_full_role(\context_system::instance());
        $category = $this->getDataGenerator()->create_category();

        creation_permissions::require_for_category((int) $category->id, (int) $user->id);
        $this->assertTrue(true, 'No exception thrown');
    }

    /**
     * The system capability is reported first, on the system context, even when the user holds the full
     * role in a category: the page gate is checked before any category.
     */
    public function test_require_anywhere_names_the_system_capability_when_it_is_missing(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        [$user] = $this->create_user_with_full_role(\context_coursecat::instance($category->id));

        try {
            creation_permissions::require_anywhere((int) $user->id);
            $this->fail('A user without the system capability must be refused.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('local/coursegen:createcoursewithai'), $e->a);
        }
    }

    /**
     * A stock coursecreator (which holds the page capability: it is cloned from moodle/course:create at
     * install) is told the first missing capability, in sorted order, of the first category where they
     * may create courses.
     */
    public function test_require_anywhere_names_the_first_sorted_missing_capability_of_a_candidate(): void {
        $this->resetAfterTest();
        $user = $this->create_coursecreator_user(['local/coursegen:createcoursewithai']);
        $category = $this->getDataGenerator()->create_category();
        $context = \context_coursecat::instance($category->id);
        $expected = null;
        foreach (creation_permissions::required_capabilities() as $capability) {
            if (!has_capability($capability, $context, $user->id)) {
                $expected = $capability;
                break;
            }
        }
        $this->assertNotNull($expected);

        try {
            creation_permissions::require_anywhere((int) $user->id);
            $this->fail('A coursecreator must be refused.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string($expected), $e->a);
        }
    }

    /**
     * Without moodle/course:create in any category there is no candidate, so that capability is reported.
     */
    public function test_require_anywhere_names_course_create_when_there_is_no_candidate_category(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $systemcontext = \context_system::instance();
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'pageonly']);
        assign_capability('local/coursegen:createcoursewithai', CAP_ALLOW, $roleid, $systemcontext->id, true);
        role_assign($roleid, $user->id, $systemcontext->id);
        $this->getDataGenerator()->create_category();

        try {
            creation_permissions::require_anywhere((int) $user->id);
            $this->fail('A user without course:create must be refused.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('moodle/course:create'), $e->a);
        }
    }

    /**
     * Among candidate categories the one with the fewest missing capabilities is reported, so the user
     * learns the smallest change that would let them create.
     */
    public function test_require_anywhere_reports_the_candidate_with_the_fewest_missing_capabilities(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $first = $generator->create_category(['name' => 'First']);
        $second = $generator->create_category(['name' => 'Second']);
        $user = $generator->create_user();
        $systemcontextid = \context_system::instance()->id;

        $pagerole = $generator->create_role(['shortname' => 'pagegate']);
        assign_capability('local/coursegen:createcoursewithai', CAP_ALLOW, $pagerole, $systemcontextid, true);
        role_assign($pagerole, $user->id, $systemcontextid);

        // First category (in sort order): two capabilities missing.
        $tworole = $this->create_full_role();
        unassign_capability('mod/forum:startdiscussion', $tworole, $systemcontextid);
        unassign_capability('mod/quiz:addinstance', $tworole, $systemcontextid);
        role_assign($tworole, $user->id, \context_coursecat::instance($first->id)->id);

        // Second category: one capability missing.
        $onerole = $this->create_full_role();
        unassign_capability('moodle/question:add', $onerole, $systemcontextid);
        role_assign($onerole, $user->id, \context_coursecat::instance($second->id)->id);

        try {
            creation_permissions::require_anywhere((int) $user->id);
            $this->fail('A user missing capabilities everywhere must be refused.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('moodle/question:add'), $e->a);
        }
    }

    /**
     * The full role in one category passes require_anywhere silently.
     */
    public function test_require_anywhere_passes_with_the_full_role_in_one_category(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        [$user] = $this->create_user_with_full_role(\context_coursecat::instance($category->id));
        $systemrole = $this->getDataGenerator()->create_role(['shortname' => 'pagegate']);
        assign_capability('local/coursegen:createcoursewithai', CAP_ALLOW, $systemrole, \context_system::instance()->id, true);
        role_assign($systemrole, $user->id, \context_system::instance()->id);

        creation_permissions::require_anywhere((int) $user->id);
        $this->assertTrue(true, 'No exception thrown');
    }

    /**
     * Without a user id the current user is checked.
     */
    public function test_methods_default_to_the_current_user(): void {
        $this->resetAfterTest();
        [$user] = $this->create_user_with_full_role(\context_system::instance());
        $category = $this->getDataGenerator()->create_category();
        $this->setUser($user);

        $this->assertTrue(creation_permissions::can_create_in((int) $category->id));
        $this->assertTrue(creation_permissions::can_create_anywhere());
        creation_permissions::require_for_category((int) $category->id);
        creation_permissions::require_anywhere();

        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(creation_permissions::can_create_in((int) $category->id));
        $this->assertFalse(creation_permissions::can_create_anywhere());
    }
}
