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
use local_coursegen\local\files\activity_file_pass;
use local_coursegen\local\files\file_copy_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Gives a new activity every file its texts reference.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class new_activity_files {
    /**
     * Give the new activity every file its texts reference.
     *
     * Runs once the activity and everything its settings create exist, so the rows of every text are real whatever
     * the module is. When a file cannot be given, the activity is removed again and the error is raised.
     *
     * @param string $modname Module plugin name.
     * @param object $newcm Newly created course module: coursemodule, instance and course.
     * @param string $name The activity's name, for the error.
     * @param int|null $sourcecourseid Course whose files the texts may reference.
     * @return void
     * @throws file_copy_exception When a file the texts reference cannot be given.
     */
    public static function give(string $modname, $newcm, string $name, ?int $sourcecourseid): void {
        $activity = (object) [
            'id' => (int) $newcm->coursemodule,
            'instance' => (int) $newcm->instance,
            'modname' => $modname,
            'course' => (int) $newcm->course,
        ];
        $pass = activity_file_pass::for_new_activity($sourcecourseid);
        try {
            $pass->run($activity, $name);
        } catch (file_copy_exception $exception) {
            // An activity whose files are missing would stay in the course half made.
            course_delete_module($activity->id);
            throw $exception;
        }
    }
}
