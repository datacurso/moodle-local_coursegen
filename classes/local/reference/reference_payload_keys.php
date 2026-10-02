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

/**
 * Names a place the way the payload does, and the other way round.
 *
 * Before the generation a place is named after its template activity, which
 * does not change between exports. The payload names the same activity by the
 * uid it travels under, which is new at every export, and the service names
 * the places after that uid. The payload says which places have a file, and
 * this is the only place that translates between the two names.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reference_payload_keys {
    /** @var string The payload entry that lists the places that have a file. */
    public const PAYLOAD_KEY = 'reference_files';

    /** @var string A place named after an activity: its identifier, a dot and its order. */
    private const KEY = '/^([A-Za-z0-9_-]+)\.(\d{1,3})$/';

    /**
     * The places that have a file, named as the payload names them.
     *
     * A staged file whose activity is not in the payload is left out: nothing
     * reads that activity, so the file has nothing to replace.
     *
     * @param array $payload The init payload.
     * @param string[] $stagedkeys Places named after their template activity.
     * @return string[]
     */
    public static function for_payload(array $payload, array $stagedkeys): array {
        $uidbycmid = self::uid_by_cmid($payload);
        $keys = [];
        foreach ($stagedkeys as $stagedkey) {
            $parts = self::split($stagedkey);
            if ($parts === null) {
                continue;
            }
            if (!isset($uidbycmid[$parts['id']])) {
                continue;
            }
            $keys[] = $uidbycmid[$parts['id']] . '.' . $parts['ordinal'];
        }
        return $keys;
    }

    /**
     * For each place the payload lists, the name of its template activity.
     *
     * @param array $payload The init payload as the session stored it.
     * @return array<string,string> Place named by the payload => place named after its template activity.
     */
    public static function stored_keys(array $payload): array {
        $listed = $payload[self::PAYLOAD_KEY] ?? [];
        $cmidbyuid = array_flip(self::uid_by_cmid($payload));
        $stored = [];
        foreach ($listed as $payloadkey) {
            $payloadkey = (string) $payloadkey;
            $parts = self::split($payloadkey);
            if ($parts === null) {
                continue;
            }
            if (!isset($cmidbyuid[$parts['id']])) {
                continue;
            }
            $stored[$payloadkey] = $cmidbyuid[$parts['id']] . '.' . $parts['ordinal'];
        }
        return $stored;
    }

    /**
     * The uid every real activity of the payload travels under.
     *
     * @param array $payload
     * @return array<int,string> cmid => uid.
     */
    private static function uid_by_cmid(array $payload): array {
        $activities = $payload['activities'] ?? [];
        $uids = [];
        foreach ($activities as $activity) {
            $rawcmid = $activity['cmid'] ?? 0;
            $rawuid = $activity['uid'] ?? '';
            $cmid = (int) $rawcmid;
            $uid = (string) $rawuid;
            if ($cmid > 0 && $uid !== '') {
                $uids[$cmid] = $uid;
            }
        }
        return $uids;
    }

    /**
     * The identifier and the order of a place name.
     *
     * @param string $key
     * @return array{id: string, ordinal: string}|null Null when it is not a place name.
     */
    private static function split(string $key): ?array {
        if (!preg_match(self::KEY, $key, $match)) {
            return null;
        }
        return ['id' => $match[1], 'ordinal' => $match[2]];
    }
}
