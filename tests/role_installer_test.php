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

namespace local_coursegen\local;

use context_system;
use local_coursegen\local\service\creation_permissions;
use local_coursegen\local\service\import_permissions;

/**
 * Tests for the installer of the two recommended roles.
 *
 * The installer creates each role only when no role with its shortname
 * exists, names it in the site language at creation time and never touches
 * an existing role, whatever its capabilities.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\role_installer
 */
final class role_installer_test extends \advanced_testcase {
    /** @var string[] Capabilities the creator role adds on top of the archetype defaults. */
    private const CREATOR_EXTRAS = [
        'local/coursegen:createactivitywithai',
        'local/coursegen:createcoursewithai',
        'moodle/course:create',
        'moodle/course:view',
    ];

    /** @var string[] Capabilities the importer role adds on top of the creator set. */
    private const IMPORTER_EXTRAS = [
        'moodle/backup:userinfo',
        'moodle/restore:userinfo',
    ];

    /** @var string[] Stock roles the creator role may assign. */
    private const CREATOR_ASSIGNABLE = ['teacher', 'student'];

    /** @var string[] Stock roles the importer role may assign. */
    private const IMPORTER_ASSIGNABLE = ['editingteacher', 'teacher', 'student'];

    /** @var int[] Context levels both roles are assignable at. */
    private const CONTEXT_LEVELS = [CONTEXT_SYSTEM, CONTEXT_COURSE, CONTEXT_MODULE];

    /**
     * The CAP_ALLOW capability names of the editingteacher archetype plus the given extras, sorted.
     *
     * @param string[] $extras
     * @return string[]
     */
    private static function expected_allowed(array $extras): array {
        $defaults = get_default_capabilities('editingteacher');
        $allowed = array_keys(array_filter($defaults, static fn($permission): bool => (int) $permission === CAP_ALLOW));
        $all = array_values(array_unique(array_merge($allowed, $extras)));
        sort($all, SORT_STRING);
        return $all;
    }

    /**
     * The CAP_ALLOW capability names a role holds on the system context, sorted.
     *
     * @param int $roleid
     * @return string[]
     */
    private static function allowed_capabilities(int $roleid): array {
        global $DB;
        $names = $DB->get_fieldset_select(
            'role_capabilities',
            'capability',
            'roleid = :roleid AND contextid = :contextid AND permission = :permission',
            ['roleid' => $roleid, 'contextid' => context_system::instance()->id, 'permission' => CAP_ALLOW]
        );
        sort($names, SORT_STRING);
        return $names;
    }

    /**
     * Id of a stock role by shortname.
     *
     * @param string $shortname
     * @return int
     */
    private static function role_id(string $shortname): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * Assert a role created by the installer matches its specification.
     *
     * @param string $shortname Role shortname.
     * @param string $name Expected English name.
     * @param string $namekey Lang key of the name.
     * @param string $desckey Lang key of the description.
     * @param string[] $extras Capabilities expected on top of the archetype defaults.
     * @param string[] $assignable Shortnames of the stock roles the role is expected to be allowed to assign, exactly.
     * @return \stdClass The role record.
     */
    private function assert_role_matches_spec(
        string $shortname,
        string $name,
        string $namekey,
        string $desckey,
        array $extras,
        array $assignable
    ): \stdClass {
        global $DB;

        $role = $DB->get_record('role', ['shortname' => $shortname], '*', MUST_EXIST);
        $this->assertSame('editingteacher', $role->archetype);
        $this->assertSame($name, $role->name);
        $this->assertSame(get_string($namekey, 'local_coursegen'), $role->name);
        $this->assertSame(get_string($desckey, 'local_coursegen'), $role->description);
        $this->assertNotSame('', $role->description);

        $this->assertSame(self::expected_allowed($extras), self::allowed_capabilities((int) $role->id));

        $levels = array_values(array_map('intval', get_role_contextlevels((int) $role->id)));
        sort($levels);
        $expectedlevels = self::CONTEXT_LEVELS;
        sort($expectedlevels);
        $this->assertSame($expectedlevels, $levels);

        $expectedassign = array_map([self::class, 'role_id'], $assignable);
        sort($expectedassign);
        $actualassign = array_map('intval', $DB->get_fieldset_select('role_allow_assign', 'allowassign', 'roleid = ?', [$role->id]));
        sort($actualassign);
        $this->assertSame($expectedassign, $actualassign);

        return $role;
    }

    /**
     * Delete the roles the installer creates, to start from a site that has neither.
     *
     * The PHPUnit site is installed with this plugin, so db/install.php has
     * already created both roles there.
     *
     * @return void
     */
    private static function remove_installed_roles(): void {
        global $DB;
        foreach ([role_installer::CREATOR_SHORTNAME, role_installer::IMPORTER_SHORTNAME] as $shortname) {
            $roleid = $DB->get_field('role', 'id', ['shortname' => $shortname]);
            if ($roleid) {
                delete_role((int) $roleid);
            }
        }
    }

    /**
     * A snapshot of every role-related table, to prove a call changed nothing.
     *
     * @return array
     */
    private static function role_tables_snapshot(): array {
        global $DB;
        $snapshot = [];
        foreach (['role', 'role_capabilities', 'role_context_levels', 'role_allow_assign'] as $table) {
            $snapshot[$table] = array_values($DB->get_records($table, [], 'id ASC'));
        }
        return $snapshot;
    }

    /**
     * On a fresh site both roles are created with the full specification.
     */
    public function test_install_missing_roles_creates_both_roles_on_a_fresh_site(): void {
        global $DB;
        $this->resetAfterTest();
        self::remove_installed_roles();

        $this->assertFalse($DB->record_exists('role', ['shortname' => role_installer::CREATOR_SHORTNAME]));
        $this->assertFalse($DB->record_exists('role', ['shortname' => role_installer::IMPORTER_SHORTNAME]));

        $created = role_installer::install_missing_roles();

        $this->assertSame([role_installer::CREATOR_SHORTNAME, role_installer::IMPORTER_SHORTNAME], $created);

        $creator = $this->assert_role_matches_spec(
            role_installer::CREATOR_SHORTNAME,
            'AI course creator (editing teacher)',
            'role_creator_name',
            'role_creator_desc',
            self::CREATOR_EXTRAS,
            self::CREATOR_ASSIGNABLE
        );
        $importer = $this->assert_role_matches_spec(
            role_installer::IMPORTER_SHORTNAME,
            'AI course creator and importer',
            'role_importer_name',
            'role_importer_desc',
            array_merge(self::CREATOR_EXTRAS, self::IMPORTER_EXTRAS),
            self::IMPORTER_ASSIGNABLE
        );

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $creatoruser = $generator->create_user();
        $importeruser = $generator->create_user();
        role_assign((int) $creator->id, $creatoruser->id, context_system::instance()->id);
        role_assign((int) $importer->id, $importeruser->id, context_system::instance()->id);

        $this->assertTrue(creation_permissions::can_create_anywhere((int) $creatoruser->id));
        $this->assertTrue(creation_permissions::can_create_anywhere((int) $importeruser->id));
        $this->assertFalse(import_permissions::can_import_into($course, (int) $creatoruser->id));
        $this->assertTrue(import_permissions::can_import_into($course, (int) $importeruser->id));
    }

    /**
     * A second call creates nothing and changes nothing.
     */
    public function test_install_missing_roles_is_idempotent(): void {
        $this->resetAfterTest();
        self::remove_installed_roles();

        role_installer::install_missing_roles();
        $before = self::role_tables_snapshot();

        $this->assertSame([], role_installer::install_missing_roles());
        $this->assertEquals($before, self::role_tables_snapshot());
    }

    /**
     * A role that already carries one of the shortnames is left untouched, whatever it contains.
     */
    public function test_install_missing_roles_keeps_an_existing_role_untouched(): void {
        global $DB;
        $this->resetAfterTest();
        self::remove_installed_roles();

        $legacyid = create_role('Legacy creator', role_installer::CREATOR_SHORTNAME, 'Built by hand', 'coursecreator');
        reset_role_capabilities($legacyid);
        set_role_contextlevels($legacyid, [CONTEXT_COURSECAT]);
        $legacybefore = $DB->get_record('role', ['id' => $legacyid]);
        $capsbefore = array_values($DB->get_records('role_capabilities', ['roleid' => $legacyid], 'id ASC'));
        $rolecount = $DB->count_records('role');

        $created = role_installer::install_missing_roles();

        $this->assertSame([role_installer::IMPORTER_SHORTNAME], $created);
        $this->assertSame($rolecount + 1, $DB->count_records('role'));
        $this->assertEquals($legacybefore, $DB->get_record('role', ['id' => $legacyid]));
        $this->assertEquals($capsbefore, array_values($DB->get_records('role_capabilities', ['roleid' => $legacyid], 'id ASC')));
        $this->assertSame([CONTEXT_COURSECAT], array_values(array_map('intval', get_role_contextlevels($legacyid))));
        $this->assertSame(0, $DB->count_records('role_allow_assign', ['roleid' => $legacyid]));
        $this->assert_role_matches_spec(
            role_installer::IMPORTER_SHORTNAME,
            'AI course creator and importer',
            'role_importer_name',
            'role_importer_desc',
            array_merge(self::CREATOR_EXTRAS, self::IMPORTER_EXTRAS),
            self::IMPORTER_ASSIGNABLE
        );
    }

    /**
     * The names and descriptions are taken from the site language at creation time.
     *
     * The PHPUnit dataroot has no language pack, and the string manager only
     * loads a plugin's lang/xx file when the core pack's langconfig.php exists,
     * so a minimal es pack is written to the dataroot for this test; the
     * dataroot is wiped on reset.
     */
    public function test_install_missing_roles_names_the_roles_in_the_site_language(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        self::remove_installed_roles();

        $langdir = $CFG->langotherroot . '/es';
        check_dir_exists($langdir);
        file_put_contents($langdir . '/langconfig.php', "<?php\n\$string['parentlanguage'] = '';\n\$string['thislanguage'] = 'Español';\n");
        get_string_manager()->reset_caches(true);
        $CFG->lang = 'es';

        role_installer::install_missing_roles();

        $creator = $DB->get_record('role', ['shortname' => role_installer::CREATOR_SHORTNAME], '*', MUST_EXIST);
        $importer = $DB->get_record('role', ['shortname' => role_installer::IMPORTER_SHORTNAME], '*', MUST_EXIST);
        $this->assertSame('Profesor Creador de curso IA', $creator->name);
        $this->assertSame('Importador Creador de curso IA', $importer->name);
        $this->assertSame(get_string('role_creator_desc', 'local_coursegen'), $creator->description);
        $this->assertSame(get_string('role_importer_desc', 'local_coursegen'), $importer->description);
        $english = get_string_manager()->get_string('role_creator_desc', 'local_coursegen', null, 'en');
        $this->assertNotSame($english, $creator->description);
    }

    /**
     * On a fresh install db/install.php runs before the plugin's capabilities are
     * registered (lib/upgradelib.php installs them in upgrade_component_updated()
     * right after), so the installer must register them itself or fail.
     */
    public function test_install_missing_roles_works_before_the_plugin_capabilities_are_registered(): void {
        global $DB;
        $this->resetAfterTest();
        self::remove_installed_roles();

        $DB->delete_records('capabilities', ['component' => 'local_coursegen']);
        \cache::make('core', 'capabilities')->delete('core_capabilities');
        $this->assertNull(get_capability_info('local/coursegen:createcoursewithai', false));

        $created = role_installer::install_missing_roles();

        $this->assertSame([role_installer::CREATOR_SHORTNAME, role_installer::IMPORTER_SHORTNAME], $created);
        $this->assert_role_matches_spec(
            role_installer::CREATOR_SHORTNAME,
            'AI course creator (editing teacher)',
            'role_creator_name',
            'role_creator_desc',
            self::CREATOR_EXTRAS,
            self::CREATOR_ASSIGNABLE
        );
    }

    /**
     * Only the importer role lets its holder assign editing teachers in a course.
     *
     * This is what lets the restore of the import map editing teachers of the
     * original course to editingteacher instead of downgrading them to teacher.
     */
    public function test_only_the_importer_role_can_assign_editing_teachers_in_a_course(): void {
        global $DB;
        $this->resetAfterTest();
        self::remove_installed_roles();

        role_installer::install_missing_roles();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $coursecontext = \context_course::instance($course->id);
        $creatoruser = $generator->create_user();
        $importeruser = $generator->create_user();
        $creatorid = (int) $DB->get_field('role', 'id', ['shortname' => role_installer::CREATOR_SHORTNAME], MUST_EXIST);
        $importerid = (int) $DB->get_field('role', 'id', ['shortname' => role_installer::IMPORTER_SHORTNAME], MUST_EXIST);
        role_assign($creatorid, $creatoruser->id, context_system::instance()->id);
        role_assign($importerid, $importeruser->id, context_system::instance()->id);

        $importerroles = get_assignable_roles($coursecontext, ROLENAME_SHORT, false, $importeruser->id);
        $creatorroles = get_assignable_roles($coursecontext, ROLENAME_SHORT, false, $creatoruser->id);

        $this->assertContains('editingteacher', $importerroles);
        $this->assertContains('teacher', $creatorroles);
        $this->assertNotContains('editingteacher', $creatorroles);
    }
}
