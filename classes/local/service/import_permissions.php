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

use context_course;
use context_coursecat;
use context_helper;

/**
 * Single source of truth for the capabilities an import into an existing course needs (ticket #9539).
 *
 * The same set drives the page flag (is the option offered at all), the target
 * course search (which courses are offered) and the server-side checks of the
 * web service and the import service, so a user is only ever offered an import
 * that the server will accept. The moodle/backup:backuptargetimport check on
 * the staging course is not part of the set: it is about the staging course,
 * which does not exist until the generated course is materialised, and stays
 * in course_import_service.
 *
 * @package    local_coursegen
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class import_permissions {
    /** @var string[] Capabilities checked on the target course context. */
    public const COURSE_CAPABILITIES = [
        'moodle/backup:backupcourse',
        'moodle/restore:restorecourse',
        // The import hides the original course.
        'moodle/course:visibility',
        // The copy carries the user data of the original: backup side on the target.
        'moodle/backup:userinfo',
    ];

    /** @var string[] Capabilities checked on the target's category context (the copy inherits from it). */
    public const CATEGORY_CAPABILITIES = [
        'moodle/course:create',
        // Restore side of the user data, on the copy, which does not exist yet.
        'moodle/restore:userinfo',
    ];

    /**
     * @var string[] Order in which require_for_target() checks the set, kept from the original
     * checks so the reported capability does not change.
     */
    private const CHECK_ORDER = [
        'moodle/backup:backupcourse',
        'moodle/restore:restorecourse',
        'moodle/course:create',
        'moodle/course:visibility',
        'moodle/backup:userinfo',
        'moodle/restore:userinfo',
    ];

    /**
     * @var int Largest number of candidate courses can_import_anywhere() inspects.
     *
     * A user holding moodle/backup:userinfo in more courses than this without
     * the full set in any of them is treated as unable to import; the
     * per-target checks still decide the real request.
     */
    public const CANDIDATE_LIMIT = 500;

    /**
     * Require every capability of the set on the target, in the original check order.
     *
     * @param \stdClass $target Target course record (id and category).
     * @param int|null $userid Acting user, the current user when null.
     * @return void
     * @throws \required_capability_exception Naming the first missing capability.
     */
    public static function require_for_target(\stdClass $target, ?int $userid = null): void {
        $coursecontext = context_course::instance($target->id);
        $categorycontext = context_coursecat::instance($target->category);
        foreach (self::CHECK_ORDER as $capability) {
            $context = in_array($capability, self::COURSE_CAPABILITIES, true) ? $coursecontext : $categorycontext;
            require_capability($capability, $context, $userid);
        }
    }

    /**
     * Whether the user holds the full set on the course and its category.
     *
     * @param \stdClass|\core_course_list_element $course Course (id and category).
     * @param int|null $userid Acting user, the current user when null.
     * @return bool
     */
    public static function can_import_into(\stdClass|\core_course_list_element $course, ?int $userid = null): bool {
        return has_all_capabilities(self::COURSE_CAPABILITIES, context_course::instance($course->id), $userid)
            && has_all_capabilities(self::CATEGORY_CAPABILITIES, context_coursecat::instance($course->category), $userid);
    }

    /**
     * Whether there is at least one course (other than the site course) the user could import into.
     *
     * Candidates are the courses where the user holds moodle/backup:userinfo,
     * the rarest capability of the set, fetched in one query; the first
     * candidate holding the full set answers. The scan stops after
     * CANDIDATE_LIMIT courses. Nothing is cached across requests.
     *
     * @param int|null $userid Acting user, the current user when null.
     * @return bool
     */
    public static function can_import_anywhere(?int $userid = null): bool {
        global $USER;

        $userid = $userid ?? (int) $USER->id;
        if (is_siteadmin($userid)) {
            return true;
        }

        $candidates = get_user_capability_course(
            'moodle/backup:userinfo',
            $userid,
            true,
            'category, ctxid, ctxpath, ctxdepth, ctxlevel, ctxinstance, ctxlocked',
            'id',
            self::CANDIDATE_LIMIT
        );
        if (!$candidates) {
            return false;
        }
        foreach ($candidates as $course) {
            if ((int) $course->id === (int) SITEID) {
                continue;
            }
            context_helper::preload_from_record($course);
            if (self::can_import_into($course, $userid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the import option is offered to the user: the site setting is on and the user can import somewhere.
     *
     * @param int|null $userid Acting user, the current user when null.
     * @return bool
     */
    public static function is_offered(?int $userid = null): bool {
        return !empty(get_config('local_coursegen', 'enableimportexisting')) && self::can_import_anywhere($userid);
    }
}
