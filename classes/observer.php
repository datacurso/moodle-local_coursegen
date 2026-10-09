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
     * their stored syllabus files) and the module jobs of the course.
     *
     * @param \core\event\course_deleted $event The course_deleted event.
     * @return void
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;

        $courseid = (int)$event->objectid;

        $fs = get_file_storage();
        $syscontextid = \context_system::instance()->id;
        $sessionids = $DB->get_fieldset_select('local_coursegen_course_sessions', 'id', 'courseid = ?', [$courseid]);
        foreach ($sessionids as $sessionid) {
            $fs->delete_area_files($syscontextid, 'local_coursegen', 'syllabus', (int)$sessionid);
        }

        $DB->delete_records('local_coursegen_course_sessions', ['courseid' => $courseid]);
        $DB->delete_records('local_coursegen_module_jobs', ['courseid' => $courseid]);
        $DB->delete_records('local_coursegen_course_context', ['courseid' => $courseid]);
    }
}
