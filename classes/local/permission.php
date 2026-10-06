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
use core_course_category;

/**
 * Permission helper for the AI course creation entry points.
 *
 * Course creation with AI needs two things: the plugin capability
 * local/coursegen:createcoursewithai and the ability to create a course
 * somewhere. Both may be held at system level or in a course category, so the
 * check mirrors what core does on My courses rather than requiring system-level
 * capabilities. The authoritative check on the effective target category still
 * happens in create_course_service when the course is created.
 *
 * @package    local_coursegen
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class permission {
    /** @var string Plugin capability required to create courses with AI. */
    public const CAP_CREATE_WITH_AI = 'local/coursegen:createcoursewithai';

    /**
     * Whether the user may create courses with AI.
     *
     * For the current user the core category helpers are used (they are cached
     * and tenant aware). For another user the categories are inspected one by
     * one, which is slower but gives the same answer.
     *
     * @param int|null $userid User id, or null for the current user.
     * @return bool
     */
    public static function can_create_course_with_ai(?int $userid = null): bool {
        global $USER;

        $userid = $userid ?? (int) ($USER->id ?? 0);
        if ($userid <= 0 || isguestuser($userid)) {
            return false;
        }

        if ($userid === (int) $USER->id) {
            return self::current_user_has_ai_capability() && self::current_user_can_create_course();
        }

        return self::user_has_capability_anywhere(self::CAP_CREATE_WITH_AI, $userid)
            && self::user_has_capability_anywhere('moodle/course:create', $userid);
    }

    /**
     * Throws when the current user may not create courses with AI.
     *
     * @return void
     * @throws \required_capability_exception
     */
    public static function require_create_course_with_ai(): void {
        if (!self::can_create_course_with_ai()) {
            throw new \required_capability_exception(
                context_system::instance(),
                self::CAP_CREATE_WITH_AI,
                'nopermissions',
                ''
            );
        }
    }

    /**
     * Whether the current user holds the AI capability at system level or in any category.
     *
     * @return bool
     */
    private static function current_user_has_ai_capability(): bool {
        if (has_capability(self::CAP_CREATE_WITH_AI, context_system::instance())) {
            return true;
        }
        return !empty(core_course_category::make_categories_list(self::CAP_CREATE_WITH_AI));
    }

    /**
     * Whether the current user can create a course in some category they can reach.
     *
     * @return bool
     */
    private static function current_user_can_create_course(): bool {
        $top = core_course_category::user_top();
        if (!$top) {
            return false;
        }
        return core_course_category::get_nearest_editable_subcategory($top, ['create']) !== null;
    }

    /**
     * Whether a user holds a capability at system level or in at least one course category.
     *
     * @param string $capability Capability name.
     * @param int $userid User id.
     * @return bool
     */
    private static function user_has_capability_anywhere(string $capability, int $userid): bool {
        if (has_capability($capability, context_system::instance(), $userid)) {
            return true;
        }
        foreach (core_course_category::get_all() as $category) {
            if (has_capability($capability, \context_coursecat::instance($category->id), $userid)) {
                return true;
            }
        }
        return false;
    }
}
