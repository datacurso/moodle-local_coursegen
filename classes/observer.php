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

/**
 * Event observers for local_coursegen.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Remove the plugin data attached to a deleted course.
     *
     * Deletes the course context configuration, the planning sessions (with
     * their stored syllabus files) and the module jobs of the course, and queues
     * the deletion of their threads at the Datacurso course service.
     *
     * @param \core\event\course_deleted $event The course_deleted event.
     * @return void
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        local\service\user_data_eraser::erase_deleted_course((int)$event->objectid);
    }

    /**
     * Erase what the plugin keeps of a deleted user: sessions, jobs and the custom prompts they wrote.
     *
     * Moodle does not run the Privacy API when it deletes a user, so the plugin does it here
     * (Mindfree, LCG-PRIV-004).
     *
     * @param \core\event\user_deleted $event The user_deleted event.
     * @return void
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        local\service\user_data_eraser::erase_user((int)$event->objectid);
    }
}
