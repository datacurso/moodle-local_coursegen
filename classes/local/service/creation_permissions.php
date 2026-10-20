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

use context_coursecat;
use context_helper;
use context_system;

/**
 * Single source of truth for the capabilities creating a complete course with AI needs (ticket #9539).
 *
 * The set is every capability the editingteacher archetype allows by default,
 * computed at runtime so it follows the installed plugins, plus four more:
 * moodle/course:create, moodle/course:view and the two local_coursegen
 * creation capabilities. The same set hides the "Create with AI" button,
 * refuses the page and refuses every web service of the creation flow, so a
 * user is only ever offered a creation the server will accept.
 *
 * local/coursegen:createcoursewithai is checked on the system context, as the
 * page always did; every other capability is checked on the target category,
 * which the new course inherits from.
 *
 * @package    local_coursegen
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class creation_permissions {
    /** @var string Role archetype whose default CAP_ALLOW capabilities form the base of the set. */
    public const ARCHETYPE = 'editingteacher';

    /** @var string[] Capabilities required on top of the archetype defaults. */
    public const EXTRA_CAPABILITIES = [
        'local/coursegen:createactivitywithai',
        'local/coursegen:createcoursewithai',
        'moodle/course:create',
        'moodle/course:view',
    ];

    /** @var string[] Capabilities of the set checked on the system context instead of the category. */
    public const SYSTEM_CAPABILITIES = [
        'local/coursegen:createcoursewithai',
    ];

    /**
     * @var int Largest number of candidate categories can_create_anywhere() and require_anywhere() inspect.
     *
     * Candidates are the categories where the user holds moodle/course:create,
     * in category sort order. A user holding it in more categories than this
     * without the full set in any of them is treated as unable to create; the
     * per-category check still decides the real request.
     */
    public const CANDIDATE_LIMIT = 200;

    /** @var string[]|null Per-request memo of required_capabilities(). */
    private static ?array $required = null;

    /**
     * The sorted, unique list of capabilities the creation needs.
     *
     * Archetype CAP_ALLOW defaults plus EXTRA_CAPABILITIES, minus any name
     * that is not installed (a stale access.php entry must never block).
     * Memoised for the request only: the installed plugins cannot change
     * within a request, and nothing is cached across requests.
     *
     * @return string[]
     */
    public static function required_capabilities(): array {
        if (self::$required !== null) {
            return self::$required;
        }
        $defaults = get_default_capabilities(self::ARCHETYPE);
        $capabilities = self::EXTRA_CAPABILITIES;
        foreach ($defaults as $capability => $permission) {
            if ((int) $permission === CAP_ALLOW) {
                $capabilities[] = $capability;
            }
        }
        $capabilities = array_filter(
            array_unique($capabilities),
            static fn(string $capability): bool => get_capability_info($capability) !== null
        );
        sort($capabilities, SORT_STRING);
        self::$required = array_values($capabilities);
        return self::$required;
    }

    /**
     * Require every capability of the set: the system ones on the system context, then the rest on the category.
     *
     * The order is deterministic (system capabilities first, then the sorted
     * list) so the exception always names the same capability for the same
     * role definition.
     *
     * @param int $categoryid Category the course would be created in.
     * @param int|null $userid Acting user, the current user when null.
     * @return void
     * @throws \required_capability_exception Naming the first missing capability.
     */
    public static function require_for_category(int $categoryid, ?int $userid = null): void {
        $systemcontext = context_system::instance();
        foreach (self::SYSTEM_CAPABILITIES as $capability) {
            require_capability($capability, $systemcontext, $userid);
        }
        $categorycontext = context_coursecat::instance($categoryid);
        foreach (self::category_capabilities() as $capability) {
            require_capability($capability, $categorycontext, $userid);
        }
    }

    /**
     * Whether the user holds the full set for the given category.
     *
     * @param int $categoryid Category the course would be created in.
     * @param int|null $userid Acting user, the current user when null.
     * @return bool
     */
    public static function can_create_in(int $categoryid, ?int $userid = null): bool {
        return has_all_capabilities(self::SYSTEM_CAPABILITIES, context_system::instance(), $userid)
            && has_all_capabilities(self::category_capabilities(), context_coursecat::instance($categoryid), $userid);
    }

    /**
     * Whether there is at least one category the user could create a course with AI in.
     *
     * Candidates are the categories where the user holds moodle/course:create,
     * in category sort order, at most CANDIDATE_LIMIT of them; the first one
     * holding the full set answers. Cost: about one in-memory capability
     * lookup per required capability per candidate once the access cache is
     * loaded. Nothing is cached across requests.
     *
     * @param int|null $userid Acting user, the current user when null.
     * @return bool
     */
    public static function can_create_anywhere(?int $userid = null): bool {
        $userid = self::resolve_userid($userid);
        if (is_siteadmin($userid)) {
            return true;
        }
        if (!has_all_capabilities(self::SYSTEM_CAPABILITIES, context_system::instance(), $userid)) {
            return false;
        }
        foreach (self::candidate_categories($userid) as $categoryid) {
            if (self::can_create_in($categoryid, $userid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Require that the user could create a course with AI in at least one category.
     *
     * The reported capability is chosen deterministically:
     * 1. a missing system capability, on the system context;
     * 2. moodle/course:create on the system context when the user holds it in no category;
     * 3. otherwise the first missing capability (sorted order) of the candidate
     *    category with the fewest missing capabilities, earliest in sort order
     *    on ties, on that category's context: the smallest change that would
     *    let the user create.
     *
     * @param int|null $userid Acting user, the current user when null.
     * @return void
     * @throws \required_capability_exception When the user can create nowhere.
     */
    public static function require_anywhere(?int $userid = null): void {
        $userid = self::resolve_userid($userid);
        if (is_siteadmin($userid)) {
            return;
        }
        $systemcontext = context_system::instance();
        foreach (self::SYSTEM_CAPABILITIES as $capability) {
            require_capability($capability, $systemcontext, $userid);
        }

        $best = null;
        $bestmissing = null;
        foreach (self::candidate_categories($userid) as $categoryid) {
            $missing = self::missing_in_category($categoryid, $userid);
            if (!$missing) {
                return;
            }
            if ($bestmissing === null || count($missing) < count($bestmissing)) {
                $best = $categoryid;
                $bestmissing = $missing;
            }
        }
        if ($best === null) {
            throw new \required_capability_exception($systemcontext, 'moodle/course:create', 'nopermissions', '');
        }
        throw new \required_capability_exception(
            context_coursecat::instance($best),
            $bestmissing[0],
            'nopermissions',
            ''
        );
    }

    /**
     * The capabilities of the set checked on the category context.
     *
     * @return string[]
     */
    private static function category_capabilities(): array {
        return array_values(array_diff(self::required_capabilities(), self::SYSTEM_CAPABILITIES));
    }

    /**
     * The required capabilities the user lacks on the category, in sorted order.
     *
     * @param int $categoryid Category id.
     * @param int $userid User id.
     * @return string[]
     */
    private static function missing_in_category(int $categoryid, int $userid): array {
        $context = context_coursecat::instance($categoryid);
        $missing = [];
        foreach (self::category_capabilities() as $capability) {
            if (!has_capability($capability, $context, $userid)) {
                $missing[] = $capability;
            }
        }
        return $missing;
    }

    /**
     * Ids of the categories where the user holds moodle/course:create, in sort order, up to CANDIDATE_LIMIT.
     *
     * core_course_category::make_categories_list() only evaluates the current
     * user, so the categories are read directly with their contexts preloaded.
     *
     * @param int $userid User id.
     * @return int[]
     */
    private static function candidate_categories(int $userid): array {
        global $DB;

        $ctxselect = context_helper::get_preload_record_columns_sql('ctx');
        $sql = "SELECT cc.id, cc.sortorder, $ctxselect
                  FROM {course_categories} cc
                  JOIN {context} ctx ON ctx.instanceid = cc.id AND ctx.contextlevel = :contextlevel
              ORDER BY cc.sortorder, cc.id";
        $records = $DB->get_recordset_sql($sql, ['contextlevel' => CONTEXT_COURSECAT]);
        $candidates = [];
        foreach ($records as $record) {
            context_helper::preload_from_record($record);
            if (has_capability('moodle/course:create', context_coursecat::instance($record->id), $userid)) {
                $candidates[] = (int) $record->id;
                if (count($candidates) >= self::CANDIDATE_LIMIT) {
                    break;
                }
            }
        }
        $records->close();
        return $candidates;
    }

    /**
     * The user id to check: the given one, or the current user.
     *
     * @param int|null $userid Given user id or null.
     * @return int
     */
    private static function resolve_userid(?int $userid): int {
        global $USER;
        return $userid ?? (int) $USER->id;
    }
}
