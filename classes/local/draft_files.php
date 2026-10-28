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

namespace local_coursegen\local;

/**
 * The files of the unfinished work of the AI activity editor: the pictures and the reference file a person adds while
 * editing, kept in Moodle until the activity is created so nothing goes to the AI service before that.
 *
 * The files live in the file area of the user, in a folder per course and kind of activity, and are named by a key that
 * the saved draft refers to.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class draft_files {
    /** The file area. */
    const AREA = 'activitydraft';

    /** A picture of a slide. */
    const ROLE_IMAGE = 'image';

    /** The file the AI reads as a reference. */
    const ROLE_REFERENCE = 'reference';

    /** The most a file can weigh, in bytes: what the AI service accepts. */
    const MAX_BYTES = 10485760;

    /** The most files a draft can have: a picture per slide and the reference. */
    const MAX_FILES = 31;

    /** How long a file is kept when its draft is gone, in seconds. */
    const ORPHAN_LIFETIME = 2 * DAYSECS;

    /** What a key looks like: letters, digits, dashes and an extension. */
    const KEY_PATTERN = '/^[A-Za-z0-9_-]{8,64}\.[A-Za-z0-9]{2,5}$/';

    /** The extensions of a picture of a slide. */
    const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif'];

    /** The kinds of picture the AI service accepts. */
    const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif'];

    /**
     * Keep a file the person just added, taking it from the draft area of the file picker.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $kind
     * @param string $key
     * @param int $draftitemid
     * @param string $role
     */
    public static function save(int $userid, int $courseid, string $kind, string $key, int $draftitemid, string $role): void {
        if (!preg_match(self::KEY_PATTERN, $key) || !in_array($role, [self::ROLE_IMAGE, self::ROLE_REFERENCE], true)) {
            throw new \moodle_exception('invalidparameter', 'debug');
        }
        $fs = get_file_storage();
        $usercontext = \context_user::instance($userid);
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);
        $file = reset($files);
        if (!$file instanceof \stored_file) {
            throw new \moodle_exception('nofile', 'error');
        }
        if ($file->get_filesize() > self::MAX_BYTES) {
            throw new \moodle_exception('error_draft_filetoolarge', 'local_coursegen');
        }
        if ($role === self::ROLE_IMAGE) {
            $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));
            $info = $file->get_imageinfo();
            $isimage = $info && in_array($info['mimetype'], self::IMAGE_TYPES, true);
            if (!in_array($extension, self::IMAGE_EXTENSIONS, true) || !$isimage) {
                throw new \moodle_exception('error_draft_notimage', 'local_coursegen');
            }
        }

        $record = self::record($userid, $courseid, $kind, $key);
        $existing = $fs->get_file(...array_values($record));
        if ($existing) {
            $existing->delete();
        } else if (count(self::files_of($userid, $courseid, $kind)) >= self::MAX_FILES) {
            throw new \moodle_exception('error_draft_toomanyfiles', 'local_coursegen');
        }
        $fs->create_file_from_storedfile($record, $file);
    }

    /**
     * The file a draft keeps under a key.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $kind
     * @param string $key
     * @return \stored_file|null
     */
    public static function get(int $userid, int $courseid, string $kind, string $key): ?\stored_file {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            return null;
        }
        $file = get_file_storage()->get_file(...array_values(self::record($userid, $courseid, $kind, $key)));
        return $file ?: null;
    }

    /**
     * Delete the files of a draft that no longer have a use.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $kind
     * @param string[] $keep The keys the saved draft still refers to.
     * @param int $before Only files kept before this time are deleted: a file just added may not be in the draft yet.
     */
    public static function prune(int $userid, int $courseid, string $kind, array $keep, int $before): void {
        foreach (self::files_of($userid, $courseid, $kind) as $file) {
            if (!in_array($file->get_filename(), $keep, true) && $file->get_timecreated() < $before) {
                $file->delete();
            }
        }
    }

    /**
     * Delete all the files of a draft.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $kind
     */
    public static function delete_all(int $userid, int $courseid, string $kind): void {
        foreach (self::files_of($userid, $courseid, $kind) as $file) {
            $file->delete();
        }
    }

    /**
     * Delete the files of every user in a course.
     *
     * @param int $courseid
     */
    public static function delete_for_course(int $courseid): void {
        global $DB;

        $fs = get_file_storage();
        $contexts = $DB->get_fieldset_sql(
            'SELECT DISTINCT contextid FROM {files} WHERE component = :component AND filearea = :area AND itemid = :course',
            ['component' => 'local_coursegen', 'area' => self::AREA, 'course' => $courseid]
        );
        foreach ($contexts as $contextid) {
            $fs->delete_area_files($contextid, 'local_coursegen', self::AREA, $courseid);
        }
    }

    /**
     * Delete the files of a user in every course.
     *
     * @param int $userid
     */
    public static function delete_for_user(int $userid): void {
        get_file_storage()->delete_area_files(\context_user::instance($userid)->id, 'local_coursegen', self::AREA);
    }

    /**
     * Delete the files that have no draft any more, after some time: the draft was used or deleted, or never saved.
     */
    public static function delete_orphans(): void {
        global $DB;

        $fs = get_file_storage();
        $path = $DB->sql_concat("'/'", 'd.kind', "'/'");
        $sql = "SELECT f.id
                  FROM {files} f
                  JOIN {context} c ON c.id = f.contextid AND c.contextlevel = :userlevel
             LEFT JOIN {local_coursegen_activity_draft} d
                    ON d.userid = c.instanceid AND d.courseid = f.itemid AND f.filepath = $path
                 WHERE f.component = :component AND f.filearea = :area AND f.filename <> '.'
                       AND f.timecreated < :limit AND d.id IS NULL";
        $ids = $DB->get_fieldset_sql($sql, [
            'userlevel' => CONTEXT_USER,
            'component' => 'local_coursegen',
            'area' => self::AREA,
            'limit' => time() - self::ORPHAN_LIFETIME,
        ]);
        foreach ($ids as $id) {
            $file = $fs->get_file_by_id($id);
            if ($file) {
                $file->delete();
            }
        }
    }

    /**
     * Where a file of a draft is, in the order the file storage asks for it.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $kind
     * @param string $key
     * @return array
     */
    private static function record(int $userid, int $courseid, string $kind, string $key): array {
        return [
            'contextid' => \context_user::instance($userid)->id,
            'component' => 'local_coursegen',
            'filearea' => self::AREA,
            'itemid' => $courseid,
            'filepath' => '/' . $kind . '/',
            'filename' => $key,
        ];
    }

    /**
     * The files a draft keeps.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $kind
     * @return \stored_file[]
     */
    private static function files_of(int $userid, int $courseid, string $kind): array {
        return get_file_storage()->get_directory_files(
            \context_user::instance($userid)->id,
            'local_coursegen',
            self::AREA,
            $courseid,
            '/' . $kind . '/',
            false,
            false
        );
    }
}
