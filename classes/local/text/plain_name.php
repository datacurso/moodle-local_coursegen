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

namespace local_coursegen\local\text;

/**
 * A name typed by the admin, kept as Moodle keeps the names of activities: plain text, no tags, within its column.
 *
 * The editor receives these names as raw text and cleans them here: a name with a tag or longer than its column must
 * never make the whole template fail to save.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plain_name {
    /** @var int The length of the char columns that store a name. */
    public const MAX_LENGTH = 255;

    /**
     * The name as plain text, trimmed and cut to the column; the fallback when nothing is left.
     *
     * @param string $raw What the editor sent.
     * @param string $fallback What to keep when the name has nothing but tags or spaces.
     * @return string
     */
    public static function of(string $raw, string $fallback = ''): string {
        $clean = trim(preg_replace('/\s+/u', ' ', clean_param($raw, PARAM_TEXT)));
        if ($clean === '') {
            $clean = trim(clean_param($fallback, PARAM_TEXT));
        }
        return \core_text::substr($clean, 0, self::MAX_LENGTH);
    }
}
