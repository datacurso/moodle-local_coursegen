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
 * The files a teacher sends with the start of a generation, one for each space they filled.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class space_files_request {
    /**
     * Check what the teacher sent against the spaces of the template.
     *
     * @param int $templateid
     * @param int $userid The teacher.
     * @param array[] $given Each: cmid, draftitemid.
     * @return array<int,int> The draft item id of each filled space, by the space's cmid.
     * @throws \invalid_parameter_exception A file is for something that is not a space of the template.
     * @throws \moodle_exception A draft area holds no file, or a required space has none.
     */
    public static function validated(int $templateid, int $userid, array $given): array {
        $spaces = file_spaces::of_template($templateid);
        $drafts = self::drafts_by_cmid($spaces, $given);
        foreach ($drafts as $cmid => $draftitemid) {
            if (space_file_storage::draft_file($userid, $draftitemid) === null) {
                throw new \moodle_exception('spacefilemissing', 'local_coursegen', '', $cmid);
            }
        }
        $missing = self::missing_required_names($spaces, $drafts);
        if ($missing !== []) {
            throw new \moodle_exception('spacerequiredmissing', 'local_coursegen', '', implode(', ', $missing));
        }
        return $drafts;
    }

    /**
     * Keep the files of a validated request for a generation.
     *
     * @param array<int,int> $drafts What validated() returned.
     * @param int $userid
     * @param int $sessionid
     */
    public static function store(array $drafts, int $userid, int $sessionid): void {
        foreach ($drafts as $cmid => $draftitemid) {
            space_file_storage::store_draft($userid, $sessionid, (int) $cmid, (int) $draftitemid);
        }
    }

    /**
     * The draft of each filled space, by cmid.
     *
     * @param file_space[] $spaces
     * @param array[] $given
     * @return array<int,int>
     * @throws \invalid_parameter_exception
     */
    private static function drafts_by_cmid(array $spaces, array $given): array {
        $cmids = array_map(static fn(file_space $space) => $space->cmid, $spaces);
        $drafts = [];
        foreach ($given as $entry) {
            $cmid = (int) $entry['cmid'];
            if (!in_array($cmid, $cmids, true)) {
                throw new \invalid_parameter_exception('Not a space of the template: ' . $cmid);
            }
            $drafts[$cmid] = (int) $entry['draftitemid'];
        }
        return $drafts;
    }

    /**
     * The names of the required spaces that have no file.
     *
     * @param file_space[] $spaces
     * @param array<int,int> $drafts
     * @return string[]
     */
    private static function missing_required_names(array $spaces, array $drafts): array {
        $names = [];
        foreach ($spaces as $space) {
            if ($space->required && !isset($drafts[$space->cmid])) {
                $names[] = $space->name;
            }
        }
        return $names;
    }
}
