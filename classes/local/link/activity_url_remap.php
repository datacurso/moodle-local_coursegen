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

namespace local_coursegen\local\link;

/**
 * Points the activity addresses of a text at other course modules.
 *
 * Used for the texts of kept activities, which were written in the template's
 * course and still address the activities of that course. An address is the
 * path of an activity page followed by the id of its course module; only
 * ids found in the map are changed, so every other address is left as it is.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class activity_url_remap {
    /** @var string An activity page address: its path up to the course module id, and the id. */
    private const ADDRESS_PATTERN = '~(/mod/[a-z0-9_]+/view\.php\?id=)(\d+)(?!\d)~';

    /**
     * Replace the course module id of every mapped activity address.
     *
     * @param string $text
     * @param array<int,int> $newcmidbyoldcmid Old course module id => new course module id.
     * @return string
     */
    public static function apply(string $text, array $newcmidbyoldcmid): string {
        return preg_replace_callback(
            self::ADDRESS_PATTERN,
            static function (array $match) use ($newcmidbyoldcmid): string {
                $oldcmid = (int) $match[2];
                if (!isset($newcmidbyoldcmid[$oldcmid])) {
                    return $match[0];
                }
                return $match[1] . $newcmidbyoldcmid[$oldcmid];
            },
            $text
        );
    }
}
