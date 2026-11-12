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

use local_coursegen\local\tenancy;
use local_coursegen\task\erase_remote_data;

/**
 * Erases what the plugin keeps of a person: used by the Privacy API and when a user is deleted.
 *
 * Personal rows (planning sessions with their syllabus files, module jobs) are deleted. The
 * custom prompt of a course is free text a teacher writes, so it goes with its author: the last
 * person who saved it, as recorded in usermodified. The course keeps its configuration row,
 * which without the prompt is no longer a usable AI context until a teacher writes one again.
 *
 * The system instructions are kept: administrators write them as institutional configuration
 * for every course of the site, and deleting them would break the courses that use them. Only
 * the reference to the person who last edited them is removed. The Privacy API declares this.
 *
 * What the Datacurso course service keeps of the same sessions and jobs (prompt, conversation,
 * generated content) is deleted there by an adhoc task queued here, before the rows that hold the
 * thread ids go (Mindfree, LCG-PRIV-001).
 *
 * Mindfree, LCG-PRIV-004.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_data_eraser {
    /**
     * Erase everything the plugin keeps of a user, in every course.
     *
     * @param int $userid
     * @return void
     */
    public static function erase_user(int $userid): void {
        global $DB;

        // The person's threads live in the course service of their own tenant (Workplace).
        erase_remote_data::queue(
            $userid,
            remote_data_service::thread_ids(['userid' => $userid]),
            tenancy::get_tenant_id($userid)
        );
        $sessionids = $DB->get_fieldset_select('local_coursegen_course_sessions', 'id', 'userid = ?', [$userid]);
        self::delete_syllabus_files($sessionids);
        $DB->delete_records('local_coursegen_course_sessions', ['userid' => $userid]);
        $DB->delete_records('local_coursegen_module_jobs', ['userid' => $userid]);

        self::erase_prompts(['usermodified' => $userid]);
        $DB->set_field('local_coursegen_system_instruction', 'usermodified', 0, ['usermodified' => $userid]);
    }

    /**
     * Erase the course-scoped data of a course, for every user or for one.
     *
     * @param int $courseid
     * @param int|null $userid Only this user's data, or null for everyone's.
     * @return void
     */
    public static function erase_course(int $courseid, ?int $userid = null): void {
        global $DB;

        $filter = ['courseid' => $courseid];
        if ($userid !== null) {
            $filter['userid'] = $userid;
        }
        erase_remote_data::queue(null, remote_data_service::thread_ids($filter));
        $sessionids = array_keys($DB->get_records('local_coursegen_course_sessions', $filter, '', 'id'));
        self::delete_syllabus_files($sessionids);
        $DB->delete_records('local_coursegen_course_sessions', $filter);
        $DB->delete_records('local_coursegen_module_jobs', $filter);

        $promptfilter = ['courseid' => $courseid];
        if ($userid !== null) {
            $promptfilter['usermodified'] = $userid;
        }
        self::erase_prompts($promptfilter);
    }

    /**
     * Erase what the plugin keeps of a deleted course, including its configuration row.
     *
     * @param int $courseid
     * @return void
     */
    public static function erase_deleted_course(int $courseid): void {
        global $DB;

        self::erase_course($courseid);
        $DB->delete_records('local_coursegen_course_context', ['courseid' => $courseid]);
    }

    /**
     * Delete the stored syllabus files of a user's planning sessions.
     *
     * @param int $userid
     * @return void
     */
    public static function erase_syllabus_files_of_user(int $userid): void {
        global $DB;

        $sessionids = $DB->get_fieldset_select('local_coursegen_course_sessions', 'id', 'userid = ?', [$userid]);
        self::delete_syllabus_files($sessionids);
    }

    /**
     * Remove the custom prompt text and the reference to its author from the matching course configurations.
     *
     * @param array $conditions Conditions on local_coursegen_course_context.
     * @return void
     */
    private static function erase_prompts(array $conditions): void {
        global $DB;

        $rows = $DB->get_records('local_coursegen_course_context', $conditions, '', 'id');
        foreach ($rows as $row) {
            $DB->update_record('local_coursegen_course_context', (object)[
                'id' => $row->id,
                'prompt_text' => null,
                'usermodified' => 0,
                'timemodified' => time(),
            ]);
        }
    }

    /**
     * Delete the stored syllabus files of the given planning sessions.
     *
     * @param array $sessionids Session record ids (file item ids).
     * @return void
     */
    private static function delete_syllabus_files(array $sessionids): void {
        $fs = get_file_storage();
        $syscontextid = \context_system::instance()->id;
        foreach ($sessionids as $sessionid) {
            $fs->delete_area_files($syscontextid, 'local_coursegen', 'syllabus', (int)$sessionid);
        }
    }
}
