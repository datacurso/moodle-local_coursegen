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

namespace local_coursegen\local\reference;

use moodle_url;
use stored_file;

/**
 * Where the files a teacher brings are kept until the course exists.
 *
 * They live in the teacher's own user context, under this plugin, in two file
 * areas that never mix. Before the generation starts there is no session to
 * keep them under, so they are staged per template: one file per slot, a
 * new upload replacing the old one. Starting the generation hands them to the
 * session, and the session's copy is what the preview and the creation read.
 * Nothing here is the teacher's draft area, and nothing outlives the
 * generation: the session's files are deleted once the course is built, and
 * what is never used is purged by age.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reference_file_storage {
    /** @var string The component of every file kept here. */
    public const COMPONENT = 'local_coursegen';

    /** @var string The file area of the files staged before the generation starts, per template. */
    public const STAGED_AREA = 'referencestaged';

    /** @var string The file area of the files a generation uses, per session. */
    public const SESSION_AREA = 'referencefile';

    /**
     * Keep a file in a slot, replacing the one it had.
     *
     * @param int $userid The teacher.
     * @param int $templateid
     * @param string $slotkey
     * @param string $filename A name already made safe.
     * @param string $pathname Where the content is now.
     * @return stored_file
     */
    public static function stage(int $userid, int $templateid, string $slotkey, string $filename, string $pathname): stored_file {
        self::remove($userid, $templateid, $slotkey);
        $record = self::record(self::STAGED_AREA, $userid, $templateid, $slotkey);
        $record['filename'] = $filename;
        $storage = get_file_storage();
        return $storage->create_file_from_pathname($record, $pathname);
    }

    /**
     * Empty a slot.
     *
     * @param int $userid
     * @param int $templateid
     * @param string $slotkey
     */
    public static function remove(int $userid, int $templateid, string $slotkey): void {
        $file = self::staged_file($userid, $templateid, $slotkey);
        if ($file === null) {
            return;
        }
        $file->delete();
    }

    /**
     * The file staged in a slot.
     *
     * @param int $userid
     * @param int $templateid
     * @param string $slotkey
     * @return stored_file|null
     */
    public static function staged_file(int $userid, int $templateid, string $slotkey): ?stored_file {
        $record = self::record(self::STAGED_AREA, $userid, $templateid, $slotkey);
        return self::file_in($record);
    }

    /**
     * The slots that have a staged file, for one template.
     *
     * @param int $userid
     * @param int $templateid
     * @return string[]
     */
    public static function staged_keys(int $userid, int $templateid): array {
        $context = \context_user::instance($userid);
        $storage = get_file_storage();
        $files = $storage->get_area_files($context->id, self::COMPONENT, self::STAGED_AREA, $templateid, 'filepath', false);
        $keys = [];
        foreach ($files as $file) {
            $keys[] = trim($file->get_filepath(), '/');
        }
        return $keys;
    }

    /**
     * Hand the staged files of a template to a session.
     *
     * @param int $userid
     * @param int $templateid
     * @param int $sessionid
     */
    public static function adopt(int $userid, int $templateid, int $sessionid): void {
        $context = \context_user::instance($userid);
        $storage = get_file_storage();
        $files = $storage->get_area_files($context->id, self::COMPONENT, self::STAGED_AREA, $templateid, 'filepath', false);
        foreach ($files as $file) {
            $storage->create_file_from_storedfile([
                'filearea' => self::SESSION_AREA,
                'itemid' => $sessionid,
            ], $file);
            $file->delete();
        }
    }

    /**
     * The file a session uses for a slot.
     *
     * @param int $userid
     * @param int $sessionid
     * @param string $slotkey
     * @return stored_file|null
     */
    public static function session_file(int $userid, int $sessionid, string $slotkey): ?stored_file {
        $record = self::record(self::SESSION_AREA, $userid, $sessionid, $slotkey);
        return self::file_in($record);
    }

    /**
     * Delete every file a session holds.
     *
     * @param int $userid
     * @param int $sessionid
     */
    public static function delete_session(int $userid, int $sessionid): void {
        $context = \context_user::instance($userid);
        $storage = get_file_storage();
        $storage->delete_area_files($context->id, self::COMPONENT, self::SESSION_AREA, $sessionid);
    }

    /**
     * Delete every file of both areas that a user holds.
     *
     * @param int $userid
     */
    public static function delete_user_files(int $userid): void {
        $context = \context_user::instance($userid);
        $storage = get_file_storage();
        $storage->delete_area_files($context->id, self::COMPONENT, self::STAGED_AREA);
        $storage->delete_area_files($context->id, self::COMPONENT, self::SESSION_AREA);
    }

    /**
     * Whether a file is one a generation of this teacher uses.
     *
     * @param stored_file $file
     * @param int $userid
     * @return bool
     */
    public static function is_session_file_of(stored_file $file, int $userid): bool {
        if ($file->get_component() !== self::COMPONENT || $file->get_filearea() !== self::SESSION_AREA) {
            return false;
        }
        $context = \context_user::instance($userid);
        return (int) $file->get_contextid() === (int) $context->id;
    }

    /**
     * The address a file is served from, to its owner.
     *
     * @param stored_file $file
     * @return moodle_url
     */
    public static function url_of(stored_file $file): moodle_url {
        return moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        );
    }

    /**
     * Delete the files of both areas that were stored before a time, for every user.
     *
     * @param int $before A timestamp.
     * @return int How many were deleted.
     */
    public static function purge_older_than(int $before): int {
        global $DB;

        $areas = [self::STAGED_AREA, self::SESSION_AREA];
        [$insql, $params] = $DB->get_in_or_equal($areas, SQL_PARAMS_NAMED);
        $params['component'] = self::COMPONENT;
        $params['before'] = $before;
        $select = "component = :component AND filearea $insql AND timecreated < :before";
        $rows = $DB->get_recordset_select('files', $select, $params);
        $storage = get_file_storage();
        $deleted = 0;
        foreach ($rows as $row) {
            $file = $storage->get_file_instance($row);
            $file->delete();
            $deleted++;
        }
        $rows->close();
        return $deleted;
    }

    /**
     * The file record of a slot in one of the areas.
     *
     * @param string $area
     * @param int $userid
     * @param int $itemid
     * @param string $slotkey
     * @return array
     */
    private static function record(string $area, int $userid, int $itemid, string $slotkey): array {
        $context = \context_user::instance($userid);
        return [
            'contextid' => $context->id,
            'component' => self::COMPONENT,
            'filearea' => $area,
            'itemid' => $itemid,
            'filepath' => '/' . $slotkey . '/',
            'userid' => $userid,
        ];
    }

    /**
     * The only file in the directory a record names.
     *
     * @param array $record
     * @return stored_file|null
     */
    private static function file_in(array $record): ?stored_file {
        $storage = get_file_storage();
        $files = $storage->get_directory_files(
            $record['contextid'],
            $record['component'],
            $record['filearea'],
            $record['itemid'],
            $record['filepath'],
            false,
            false
        );
        $file = reset($files);
        if (!$file) {
            return null;
        }
        return $file;
    }
}
