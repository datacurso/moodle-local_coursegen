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
 * Which course module each activity uid ends up as.
 *
 * Both the activities the AI wrote and the kept ones that were copied are
 * targets: a generated one is found by its uid in the build of the generated
 * activities, and a kept one by its course module in the copy of the kept ones.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class link_targets {
    /**
     * The created cmid of every activity that can be linked to.
     *
     * An entry that has no uid, no cmid, or no created module is not a
     * target: a link to it is reported as unresolved.
     *
     * @param array $payloadactivities Every activity entry of the result, kept ones included.
     * @param array $generatedcms Activity key (generated_activity_key) => created cmid, for the generated activities.
     * @param array $keptcms Template cmid => created cmid, for the copied kept activities.
     * @return array<string,int> uid => created cmid.
     * @throws \moodle_exception When two entries share a uid.
     */
    public static function build(array $payloadactivities, array $generatedcms, array $keptcms): array {
        $seen = [];
        $targets = [];
        foreach ($payloadactivities as $entry) {
            $uid = (string) ($entry['uid'] ?? '');
            if ($uid === '') {
                continue;
            }
            if (isset($seen[$uid])) {
                throw new \moodle_exception('linkduplicateuid', 'local_coursegen', '', $uid);
            }
            $seen[$uid] = true;
            $created = $generatedcms[generated_activity_key::of($entry)] ?? $keptcms[(int) ($entry['cmid'] ?? 0)] ?? null;
            if ($created !== null) {
                $targets[$uid] = (int) $created;
            }
        }
        return $targets;
    }
}
