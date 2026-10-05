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

namespace local_coursegen\local\template;

use context_course;
use context_system;

/**
 * Who can manage the templates, and which courses they can base a template on.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_access {
    /**
     * Require the capability that manages the templates.
     *
     * @throws \required_capability_exception When the user does not have it.
     */
    public static function require_manage(): void {
        $context = context_system::instance();
        require_capability('local/coursegen:managetemplates', $context);
    }

    /**
     * Whether the user can manage the templates.
     *
     * @return bool
     */
    public static function can_manage(): bool {
        $context = context_system::instance();

        return has_capability('local/coursegen:managetemplates', $context);
    }

    /**
     * Require that the user can see a course, so its structure is not shown to someone who could not open it.
     *
     * A course that does not exist is left to the service, which rejects it with its own message.
     *
     * @param int $courseid Course id, for example 42.
     * @throws \required_capability_exception When the user cannot see the course.
     */
    public static function require_course_visible(int $courseid): void {
        if (!course_structure::is_usable_course($courseid)) {
            return;
        }

        $context = context_course::instance($courseid);
        require_capability('moodle/course:view', $context);
    }
}
