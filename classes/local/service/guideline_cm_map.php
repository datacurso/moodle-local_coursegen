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
 * Maps the uid of each generated activity to the course module that was created for it.
 *
 * The service identifies an activity by its uid and gives it a placeholder (negative) cmid; the creation
 * records placeholder cmid => created cmid, so the two together give uid => created cmid.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class guideline_cm_map {
    /**
     * The created course module of every activity that has one.
     *
     * @param array $activities The result's generated activities.
     * @param array $generatedcms Placeholder cmid => created cmid.
     * @return int[] Activity uid => created course module id.
     */
    public static function by_uid(array $activities, array $generatedcms): array {
        $map = [];
        foreach ($activities as $activity) {
            $uid = (string) ($activity['uid'] ?? '');
            $payloadcmid = (int) ($activity['cmid'] ?? 0);
            if ($uid === '' || !isset($generatedcms[$payloadcmid])) {
                continue;
            }
            $map[$uid] = (int) $generatedcms[$payloadcmid];
        }
        return $map;
    }
}
