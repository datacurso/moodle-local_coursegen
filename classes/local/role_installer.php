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

/**
 * Installer of the two recommended roles: the AI course creator and the AI course creator and importer.
 *
 * Both roles are editing teachers (archetype defaults) plus the capabilities
 * the creation flow needs; the importer also holds the user-data backup and
 * restore capabilities the import into an existing course needs. The creator
 * may assign the teacher and student roles; the importer may also assign the
 * editing teacher role, so the restore of the import maps editing teachers of
 * the original course to editingteacher instead of downgrading them to teacher
 * (restore_dbops::get_best_assignable_role()); a stock role missing from the
 * site is skipped. A role is created only when no role with its shortname
 * exists; an existing one is never modified or removed, whatever it contains.
 * Names and descriptions come from the plugin strings in the site language at
 * creation time.
 *
 * @package    local_coursegen
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class role_installer {
    /** @var string Shortname of the AI course creator role. */
    public const CREATOR_SHORTNAME = 'editingteachercoursegenai';

    /** @var string Shortname of the AI course creator and importer role. */
    public const IMPORTER_SHORTNAME = 'editingteachercoursegenimportai';

    /** @var string[] Capabilities the importer role adds on top of the creator set. */
    private const IMPORT_CAPABILITIES = [
        'moodle/backup:userinfo',
        'moodle/restore:userinfo',
    ];

    /** @var int[] Context levels both roles are assignable at. */
    private const CONTEXT_LEVELS = [CONTEXT_SYSTEM, CONTEXT_COURSE, CONTEXT_MODULE];

    /**
     * Create the roles that do not exist yet.
     *
     * @return string[] Shortnames of the roles created, in creation order; empty when both existed.
     */
    public static function install_missing_roles(): array {
        global $DB;

        $specs = [
            self::CREATOR_SHORTNAME => [
                'name' => 'role_creator_name',
                'description' => 'role_creator_desc',
                'capabilities' => creation_permissions::EXTRA_CAPABILITIES,
                'assignable' => ['teacher', 'student'],
            ],
            self::IMPORTER_SHORTNAME => [
                'name' => 'role_importer_name',
                'description' => 'role_importer_desc',
                'capabilities' => array_merge(creation_permissions::EXTRA_CAPABILITIES, self::IMPORT_CAPABILITIES),
                'assignable' => ['editingteacher', 'teacher', 'student'],
            ],
        ];

        $created = [];
        foreach ($specs as $shortname => $spec) {
            if ($DB->record_exists('role', ['shortname' => $shortname])) {
                continue;
            }
            self::ensure_plugin_capabilities_registered();
            self::create_role_from_spec(
                $shortname,
                $spec['name'],
                $spec['description'],
                $spec['capabilities'],
                $spec['assignable']
            );
            $created[] = $shortname;
        }
        return $created;
    }

    /**
     * Register the plugin's own capabilities when they are not in the database yet.
     *
     * On a fresh install db/install.php runs before core registers the plugin's
     * db/access.php (lib/upgradelib.php calls update_capabilities() right after
     * it), and assign_capability() refuses an unregistered capability. Registering
     * them here is what core's own lib/db/install.php does for the default roles;
     * the later core call finds nothing left to do.
     *
     * @return void
     */
    private static function ensure_plugin_capabilities_registered(): void {
        if (get_capability_info('local/coursegen:createcoursewithai', false) === null) {
            update_capabilities('local_coursegen');
        }
    }

    /**
     * Create one role: archetype defaults, extra capabilities, context levels and allowed assignments.
     *
     * @param string $shortname Role shortname.
     * @param string $namekey Lang key of the role name.
     * @param string $descriptionkey Lang key of the role description.
     * @param string[] $capabilities Capabilities allowed on the system context on top of the archetype defaults.
     * @param string[] $assignable Shortnames of the stock roles holders may assign; missing ones are skipped.
     * @return int The new role id.
     */
    private static function create_role_from_spec(
        string $shortname,
        string $namekey,
        string $descriptionkey,
        array $capabilities,
        array $assignable
    ): int {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        $roleid = create_role(
            get_string($namekey, 'local_coursegen'),
            $shortname,
            get_string($descriptionkey, 'local_coursegen'),
            creation_permissions::ARCHETYPE
        );
        reset_role_capabilities($roleid);

        $systemcontextid = context_system::instance()->id;
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $systemcontextid, true);
        }

        set_role_contextlevels($roleid, self::CONTEXT_LEVELS);

        foreach ($assignable as $assignableshortname) {
            $assignableid = $DB->get_field('role', 'id', ['shortname' => $assignableshortname]);
            if ($assignableid) {
                core_role_set_assign_allowed($roleid, (int) $assignableid);
            }
        }

        $transaction->allow_commit();

        return $roleid;
    }
}
