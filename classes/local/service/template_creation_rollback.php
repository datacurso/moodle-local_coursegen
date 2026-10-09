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

use local_coursegen\local\models\course_session;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Undoes a course creation that failed half way.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_creation_rollback {
    /**
     * Deletes the course the creation had reached and leaves the session as a failed one that points at no course.
     *
     * @param course_session $session The session of the creation.
     * @return void
     */
    public static function undo(course_session $session): void {
        global $DB;

        $courseid = (int) $session->get('courseid');
        if ($courseid > 0) {
            $course = $DB->get_record('course', ['id' => $courseid]);
            if ($course) {
                delete_course($course, false);
            }
        }

        $session->set('courseid', null);
        $session->set('status', course_session::STATUS_FAILED);
        $session->set('timemodified', time());
        $session->update();
    }
}
