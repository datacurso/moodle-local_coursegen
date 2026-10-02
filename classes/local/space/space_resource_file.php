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

namespace local_coursegen\local\space;

/**
 * Puts the teacher's file in the file resource that stands for a space in the new course.
 *
 * The resource is a copy of the template's own, so it has the name, the description and the settings of the template;
 * only the file it shows is the teacher's.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class space_resource_file {
    /**
     * Replace the file of a resource by the teacher's.
     *
     * @param int $cmid The resource's course module id in the new course.
     * @param \stored_file $teacherfile
     */
    public static function replace(int $cmid, \stored_file $teacherfile): void {
        global $DB;

        $context = \context_module::instance($cmid);
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_resource', 'content');
        $new = $fs->create_file_from_storedfile([
            'contextid' => $context->id,
            'component' => 'mod_resource',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $teacherfile->get_filename(),
        ], $teacherfile);
        $new->set_sortorder(1);

        $cm = get_coursemodule_from_id('resource', $cmid, 0, false, MUST_EXIST);
        $resource = $DB->get_record('resource', ['id' => $cm->instance], '*', MUST_EXIST);
        $resource->revision = (int) $resource->revision + 1;
        $resource->timemodified = time();
        $DB->update_record('resource', $resource);
        rebuild_course_cache((int) $cm->course, true);
    }
}
