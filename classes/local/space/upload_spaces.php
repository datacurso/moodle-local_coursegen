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

use local_coursegen\local\models\template_space;
use local_coursegen\local\service\teacher_space_options;

/**
 * The places of a template where the teacher uploads a file of their own, added with "Add activity or space" (no file
 * of the template course behind them): the new course gets a File resource with that file, in that place.
 *
 * They go through the same request and storage as the files of the spaces of the template course, under a placeholder
 * course module id of their own (negative, so it never meets a real one).
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upload_spaces {
    /** The placeholder course module ids of these spaces start here, below every instance's and every choice's. */
    const CMID_BASE = 2000000000;

    /** The module the teacher's file becomes. */
    const MODNAME = 'resource';

    /**
     * The upload spaces of a template, as file spaces.
     *
     * @param int $templateid
     * @return file_space[]
     */
    public static function of_template(int $templateid): array {
        $spaces = [];
        foreach (self::records_of($templateid) as $space) {
            $instruction = trim((string) $space->get('instruction'));
            $spaces[] = new file_space(
                self::cmid_of($space),
                $instruction !== '' ? $instruction : get_string('modulename', 'mod_' . self::MODNAME),
                $instruction,
                (int) $space->get('required') === 1,
                []
            );
        }
        return $spaces;
    }

    /**
     * The saved spaces of a template that are upload spaces, by uid.
     *
     * @param int $templateid
     * @return array<string,template_space>
     */
    public static function records_of(int $templateid): array {
        $spaces = [];
        foreach (template_space::get_records(['templateid' => $templateid]) as $space) {
            if ((string) $space->get('modname') === self::MODNAME && !teacher_space_options::decode($space->get('options'))) {
                $spaces[(string) $space->get('uid')] = $space;
            }
        }
        return $spaces;
    }

    /**
     * The placeholder course module id of an upload space.
     *
     * @param template_space $space
     * @return int
     */
    public static function cmid_of(template_space $space): int {
        return -(self::CMID_BASE + (int) $space->get('id'));
    }

    /**
     * Create, in the new course, the File resource of each upload space the teacher filled.
     *
     * @param int $templateid
     * @param int $courseid The new course.
     * @param space_selection $selection The spaces and the files the teacher stored.
     * @return array<string,int> The created course module id, by the uid of its space.
     */
    public static function create_resources(int $templateid, int $courseid, space_selection $selection): array {
        $template = \local_coursegen\local\models\template::get_record(['id' => $templateid]);
        if (!$template || $courseid <= 0) {
            return [];
        }
        $sectionnums = [];
        foreach (get_fast_modinfo((int) $template->get('courseid'))->get_section_info_all() as $section) {
            $sectionnums[(int) $section->id] = (int) $section->section;
        }
        $bycmid = [];
        foreach ($selection->filled() as $filled) {
            $bycmid[$filled->cmid] = $filled;
        }
        $created = [];
        foreach (self::records_of($templateid) as $uid => $space) {
            $filled = $bycmid[self::cmid_of($space)] ?? null;
            if ($filled === null) {
                continue;
            }
            $file = $selection->file_of($filled);
            $section = $sectionnums[(int) $space->get('sectionid')] ?? 0;
            $created[$uid] = self::create_resource($courseid, $section, $file);
        }
        return $created;
    }

    /**
     * One File resource with the teacher's file, named after the file.
     *
     * @param int $courseid
     * @param int $section The section number.
     * @param \stored_file $file
     * @return int The course module id.
     */
    private static function create_resource(int $courseid, int $section, \stored_file $file): int {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->libdir . '/resourcelib.php');

        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_storedfile([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $file->get_filename(),
        ], $file);
        $name = pathinfo($file->get_filename(), PATHINFO_FILENAME);
        $moduleinfo = (object) [
            'modulename' => self::MODNAME,
            'course' => $courseid,
            'section' => $section,
            'visible' => 1,
            'name' => $name !== '' ? $name : $file->get_filename(),
            'introeditor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0],
            'files' => $draftitemid,
            'display' => RESOURCELIB_DISPLAY_AUTO,
            'printintro' => 0,
            'showsize' => 0,
            'showtype' => 0,
            'showdate' => 0,
            'filterfiles' => 0,
        ];
        $cm = create_module($moduleinfo);
        return (int) $cm->coursemodule;
    }
}
