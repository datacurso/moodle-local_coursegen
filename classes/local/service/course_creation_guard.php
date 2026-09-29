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

/**
 * Turns a failed create_course_service::create_course() result into a thrown
 * exception, at the webservice boundary that must not silently carry on with
 * a synthetic courseid of 0.
 *
 * create_course_service already reports failure correctly - it catches
 * everything that can go wrong while building the course, marks the session
 * failed, and returns {success: false, courseid: 0, message: <real reason>}
 * rather than letting the exception escape (classes/external/create_course.php
 * depends on exactly that array shape, success included, for its own
 * response). A caller that only reads courseid and never checks success
 * treats that 0 as "nothing to attach the template's kept activities to" and
 * quietly reports the run as completed, discarding the real reason in
 * message and leaving the professor with no course and no error.
 */
class course_creation_guard {
    /**
     * @param array $result Whatever create_course_service::create_course() returned.
     * @throws \moodle_exception When the result reports failure.
     */
    public static function ensure_created(array $result): void {
        if (!empty($result['success'])) {
            return;
        }
        $errormessage = $result['message'] ?? '';
        throw new \moodle_exception('error_creating_course', 'local_coursegen', '', $errormessage);
    }
}
