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
 * Keeps the files a teacher brought for the spaces of one generation until the course is made.
 *
 * A draft area is cleaned out after a few hours, a generation can wait longer than that for the teacher's review, so
 * the file is copied into the teacher's own files when the generation starts. It is stored by the generation's
 * session id and the space's cmid, and deleted once the course exists.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class space_file_storage {
    /** @var string The component that owns the files. */
    public const COMPONENT = 'local_coursegen';

    /** @var string The file area of the files a teacher brought for a generation. */
    public const AREA = 'spacefile';

    /**
     * The first file of a draft area of a user.
     *
     * @param int $userid
     * @param int $draftitemid
     * @return \stored_file|null Null when the draft area holds no file.
     */
    public static function draft_file(int $userid, int $draftitemid): ?\stored_file {
        if ($draftitemid <= 0) {
            return null;
        }
        $context = \context_user::instance($userid);
        $files = get_file_storage()->get_area_files($context->id, 'user', 'draft', $draftitemid, 'itemid, filepath, filename', false);
        $file = reset($files);
        if (!$file) {
            return null;
        }
        return $file;
    }

    /**
     * Copy the file of a draft area for a generation and a space.
     *
     * @param int $userid The teacher.
     * @param int $sessionid The generation's session id.
     * @param int $cmid The space's cmid in the template's course.
     * @param int $draftitemid
     * @return \stored_file The stored copy.
     * @throws \moodle_exception The draft area holds no file.
     */
    public static function store_draft(int $userid, int $sessionid, int $cmid, int $draftitemid): \stored_file {
        $draft = self::draft_file($userid, $draftitemid);
        if ($draft === null) {
            throw new \moodle_exception('spacefilemissing', 'local_coursegen', '', $cmid);
        }
        $record = self::record($userid, $sessionid, $cmid);
        $record['filename'] = $draft->get_filename();
        return get_file_storage()->create_file_from_storedfile($record, $draft);
    }

    /**
     * The files stored for a generation, by the cmid of their space.
     *
     * @param int $userid
     * @param int $sessionid
     * @return \stored_file[]
     */
    public static function files_of_session(int $userid, int $sessionid): array {
        $context = \context_user::instance($userid);
        $files = get_file_storage()->get_area_files($context->id, self::COMPONENT, self::AREA, $sessionid, 'filepath', false);
        $bycmid = [];
        foreach ($files as $file) {
            $bycmid[(int) trim($file->get_filepath(), '/')] = $file;
        }
        return $bycmid;
    }

    /**
     * Delete the files stored for a generation.
     *
     * @param int $userid
     * @param int $sessionid
     */
    public static function delete_session(int $userid, int $sessionid): void {
        $context = \context_user::instance($userid);
        get_file_storage()->delete_area_files($context->id, self::COMPONENT, self::AREA, $sessionid);
    }

    /**
     * Delete every file stored for a user.
     *
     * @param int $userid
     */
    public static function delete_user_files(int $userid): void {
        $context = \context_user::instance($userid);
        get_file_storage()->delete_area_files($context->id, self::COMPONENT, self::AREA);
    }

    /**
     * Delete the files stored before a time.
     *
     * @param int $before Unix time.
     * @return int How many files were deleted.
     */
    public static function purge_older_than(int $before): int {
        global $DB;

        $select = 'component = :component AND filearea = :area AND timecreated < :before AND filename <> :dot';
        $params = ['component' => self::COMPONENT, 'area' => self::AREA, 'before' => $before, 'dot' => '.'];
        $rows = $DB->get_recordset_select('files', $select, $params);
        $storage = get_file_storage();
        $deleted = 0;
        foreach ($rows as $row) {
            $storage->get_file_instance($row)->delete();
            $deleted++;
        }
        $rows->close();
        return $deleted;
    }

    /**
     * The record of a stored file, without its name.
     *
     * @param int $userid
     * @param int $sessionid
     * @param int $cmid
     * @return array
     */
    private static function record(int $userid, int $sessionid, int $cmid): array {
        $context = \context_user::instance($userid);
        return [
            'contextid' => $context->id,
            'component' => self::COMPONENT,
            'filearea' => self::AREA,
            'itemid' => $sessionid,
            'filepath' => '/' . $cmid . '/',
            'userid' => $userid,
        ];
    }
}
