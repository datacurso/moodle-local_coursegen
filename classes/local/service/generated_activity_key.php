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
 * The key under which a generated activity's created course module is kept.
 *
 * An activity the AI writes is known by its uid: the template course has no
 * course module for it, so no id of one can name it. An entry without a uid
 * (an older payload) is still known by the id it travelled with.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class generated_activity_key {
    /** @var string Prefix of the key of an entry that has no uid. */
    private const CMID_PREFIX = 'cmid:';

    /**
     * The key of a generated activity, '' when it has neither a uid nor an id.
     *
     * @param array $activity A payload or result activity entry.
     * @return string
     */
    public static function of(array $activity): string {
        $uid = trim((string) ($activity['uid'] ?? ''));
        if ($uid !== '') {
            return $uid;
        }
        $cmid = (int) ($activity['cmid'] ?? 0);
        return $cmid !== 0 ? self::CMID_PREFIX . $cmid : '';
    }
}
