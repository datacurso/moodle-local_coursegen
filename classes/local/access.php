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

use core\context;
use core\context\course;

/**
 * Permission gates shared by the AI generation endpoints.
 *
 * Every endpoint of a flow enforces the same pair of capabilities, in the same
 * order, right after validate_context(): the generic Moodle capability for the
 * action and the plugin capability that allows spending AI credits on it. The
 * capability names are kept here so db/services.php, the UI entry points and
 * the external functions cannot drift apart.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class access {
    /** @var string[] Capabilities required to plan and create a course with AI, in check order. */
    public const COURSE_CREATION = ['moodle/course:create', 'local/coursegen:createcoursewithai'];

    /** @var string[] Capabilities required to generate an activity with AI, in check order. */
    public const ACTIVITY_CREATION = ['moodle/course:manageactivities', 'local/coursegen:createactivitywithai'];

    /**
     * Require the course planning/creation capabilities in the given context.
     *
     * @param context $context Usually the system context, where the planning endpoints run.
     * @return void
     * @throws \core\exception\required_capability_exception When a capability is missing.
     */
    public static function require_course_creation(context $context): void {
        foreach (self::COURSE_CREATION as $capability) {
            require_capability($capability, $context);
        }
    }

    /**
     * Require the activity generation capabilities in the course the activity is generated in.
     *
     * @param course $context Context of the target course.
     * @return void
     * @throws \core\exception\required_capability_exception When a capability is missing.
     */
    public static function require_activity_creation(course $context): void {
        foreach (self::ACTIVITY_CREATION as $capability) {
            require_capability($capability, $context);
        }
    }
}
