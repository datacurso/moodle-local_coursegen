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

use local_coursegen\local\models\template;
use local_coursegen\local\models\template_activity;

/**
 * Finds the file resources of a template that the teacher fills with a file.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class file_spaces {
    /** @var string The file area of a resource that holds its file. */
    private const RESOURCE_AREA = 'content';

    /**
     * The spaces of a template, in the order of its course.
     *
     * @param int $templateid
     * @return file_space[] Empty when the template or its course does not exist.
     */
    public static function of_template(int $templateid): array {
        global $DB;

        $template = template::get_record(['id' => $templateid]);
        if (!$template) {
            return [];
        }
        $courseid = (int) $template->get('courseid');
        if (!$DB->record_exists('course', ['id' => $courseid])) {
            return [];
        }
        $saved = self::saved_spaces($templateid);
        $modinfo = get_fast_modinfo($courseid);
        return self::spaces_of_modinfo($modinfo, $saved);
    }

    /**
     * The spaces of a template with the files the teacher stored for a generation.
     *
     * @param int $templateid
     * @param int $userid The teacher.
     * @param int $sessionid The generation's session id.
     * @return space_selection
     */
    public static function selection_of_session(int $templateid, int $userid, int $sessionid): space_selection {
        $spaces = self::of_template($templateid);
        $stored = space_file_storage::files_of_session($userid, $sessionid);
        $files = [];
        foreach ($spaces as $space) {
            if (isset($stored[$space->cmid])) {
                $files[$space->cmid] = $stored[$space->cmid];
            }
        }
        return new space_selection($spaces, $files);
    }

    /**
     * The saved space settings of a template, by cmid.
     *
     * @param int $templateid
     * @return array<int,template_activity>
     */
    private static function saved_spaces(int $templateid): array {
        $records = template_activity::get_records(['templateid' => $templateid, 'action' => template_activity::ACTION_SPACE]);
        $saved = [];
        foreach ($records as $record) {
            $saved[(int) $record->get('cmid')] = $record;
        }
        return $saved;
    }

    /**
     * The spaces among the activities of a course, in course order.
     *
     * @param \course_modinfo $modinfo
     * @param array<int,template_activity> $saved
     * @return file_space[]
     */
    private static function spaces_of_modinfo(\course_modinfo $modinfo, array $saved): array {
        $spaces = [];
        foreach ($modinfo->get_cms() as $cm) {
            $record = $saved[(int) $cm->id] ?? null;
            if ($record === null || !space_rules::allows($cm->modname) || $cm->deletioninprogress) {
                continue;
            }
            $spaces[] = self::space_of($cm, $record);
        }
        return $spaces;
    }

    /**
     * One space.
     *
     * @param \cm_info $cm
     * @param template_activity $record
     * @return file_space
     */
    private static function space_of(\cm_info $cm, template_activity $record): file_space {
        $instruction = (string) $record->get('spaceinstruction');
        $required = (bool) $record->get('spacerequired');
        $files = self::resource_files($cm);
        return new file_space((int) $cm->id, $cm->get_formatted_name(), $instruction, $required, $files);
    }

    /**
     * The files of a resource, in the order Moodle shows them.
     *
     * @param \cm_info $cm
     * @return \stored_file[]
     */
    private static function resource_files(\cm_info $cm): array {
        $fs = get_file_storage();
        $files = $fs->get_area_files($cm->context->id, 'mod_resource', self::RESOURCE_AREA, 0, 'sortorder DESC, id ASC', false);
        return array_values($files);
    }
}
