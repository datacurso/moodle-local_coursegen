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
 * Finds, in the result of a template run, the file resources whose file the run replaced.
 *
 * Such a resource is not written by the AI: the course gets a copy of the template resource and the file the
 * teacher brought takes the place of its own.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_file_resources {
    /** @var string Module name of a file resource. */
    private const RESOURCE = 'resource';

    /** @var string Role the service gives the file a resource shows. */
    private const MAIN_ROLE = 'main';

    /**
     * The file resources of a result that carry a replacement file.
     *
     * @param array $activities generated_activities of the result document.
     * @return array[] One entry per resource: uid, cmid and file (file_id, filename, content_type, role).
     */
    public static function select(array $activities): array {
        $selected = [];
        foreach ($activities as $activity) {
            $entry = self::entry_of($activity);
            if ($entry !== null) {
                $selected[] = $entry;
            }
        }
        return $selected;
    }

    /**
     * The template cmids of a selection.
     *
     * @param array[] $selected Output of select().
     * @return int[] Template cmids, for example [11340].
     */
    public static function cmids(array $selected): array {
        $cmids = [];
        foreach ($selected as $entry) {
            $cmids[] = (int) $entry['cmid'];
        }
        return $cmids;
    }

    /**
     * Whether an activity of the result is a file resource with a replacement file.
     *
     * @param array $activity One entry of generated_activities.
     * @return bool True when the activity is handled as a file replacement.
     */
    public static function is_file_resource(array $activity): bool {
        return self::entry_of($activity) !== null;
    }

    /**
     * The entry of one activity, or null when it is not a file resource with a usable file.
     *
     * @param array $activity One entry of generated_activities.
     * @return array|null uid, cmid and file.
     */
    private static function entry_of(array $activity): ?array {
        $rawtype = $activity['resource_type'] ?? '';
        $type = (string) $rawtype;
        $rawcmid = $activity['cmid'] ?? 0;
        $cmid = (int) $rawcmid;
        if ($type !== self::RESOURCE || $cmid <= 0) {
            return null;
        }
        $files = $activity['generated_files'] ?? [];
        $file = self::main_file($files);
        if ($file === null) {
            return null;
        }
        $rawuid = $activity['uid'] ?? '';
        $uid = (string) $rawuid;
        if ($uid === '') {
            return null;
        }
        return ['uid' => $uid, 'cmid' => $cmid, 'file' => $file];
    }

    /**
     * The file a resource shows: the one with the main role, else the first one.
     *
     * @param mixed $files generated_files of an activity.
     * @return array|null The file entry, or null when there is none with an id and a name.
     */
    private static function main_file($files): ?array {
        if (!is_array($files)) {
            return null;
        }
        $first = null;
        foreach ($files as $file) {
            if (!self::is_usable($file)) {
                continue;
            }
            if (($file['role'] ?? '') === self::MAIN_ROLE) {
                return $file;
            }
            if ($first === null) {
                $first = $file;
            }
        }
        return $first;
    }

    /**
     * Whether a file entry has the id and the name the download needs.
     *
     * @param mixed $file Entry of generated_files.
     * @return bool True when file_id and filename are non-empty text.
     */
    private static function is_usable($file): bool {
        if (!is_array($file)) {
            return false;
        }
        $fileid = $file['file_id'] ?? '';
        $filename = $file['filename'] ?? '';
        return is_string($fileid) && $fileid !== '' && is_string($filename) && $filename !== '';
    }
}
