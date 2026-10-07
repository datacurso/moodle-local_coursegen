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
 * The token the AI writes where an activity link belongs.
 *
 * The token only ever stands as the whole value of an href or src attribute,
 * and names its target by the uid the activity travelled under. It is
 * replaced there and nowhere else: any other occurrence is left in place for
 * the caller to report.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class link_token {
    /** @var string What every link token starts with. */
    private const PREFIX = '$@COURSEGENLINK*';

    /** @var string A token that is the whole value of an href or src attribute. */
    private const ATTRIBUTE_PATTERN = '/(?<![\w-])(href|src)=(["\'])\$@COURSEGENLINK\*([A-Za-z0-9_-]+)@\$\2/i';

    /** @var string What follows the prefix of any token still present, well formed or not. */
    private const REMAINING_PATTERN = '/\$@COURSEGENLINK\*([A-Za-z0-9_-]*)/';

    /**
     * Replace the tokens whose uid has a URL.
     *
     * The URL is escaped for the attribute it lands in. A token with a uid
     * that has no URL stays where it is. A src takes the embed URL of its uid
     * when it has one, because it shows what it points at instead of opening it.
     *
     * @param string $text
     * @param array $urlbyuid uid => URL.
     * @param array $embedurlbyuid uid => URL a src shows, for the targets that have a file to show.
     * @return string
     */
    public static function replace(string $text, array $urlbyuid, array $embedurlbyuid = []): string {
        return preg_replace_callback(
            self::ATTRIBUTE_PATTERN,
            static function (array $match) use ($urlbyuid, $embedurlbyuid): string {
                $uid = $match[3];
                $url = $urlbyuid[$uid] ?? null;
                if (strtolower($match[1]) === 'src' && isset($embedurlbyuid[$uid])) {
                    $url = $embedurlbyuid[$uid];
                }
                if ($url === null) {
                    return $match[0];
                }
                $escaped = s($url);
                return $match[1] . '=' . $match[2] . $escaped . $match[2];
            },
            $text
        );
    }

    /**
     * The uid of the first token still present in a text.
     *
     * @param string $text
     * @return string|null The uid, an empty string for a token with no readable uid, or null when no token remains.
     */
    public static function first_remaining_uid(string $text): ?string {
        if (!preg_match(self::REMAINING_PATTERN, $text, $match)) {
            return null;
        }
        return $match[1];
    }
}
