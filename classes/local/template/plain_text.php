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

namespace local_coursegen\local\template;

use moodle_exception;

/**
 * Cleans the free text an admin types: the description of a template and the instruction of an activity.
 *
 * The text is stored as the admin wrote it, so it can contain "<" or quotes, and it is escaped when it is drawn.
 * It only loses invalid UTF-8 and control characters, and a text made of blanks becomes null.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class plain_text {
    /**
     * Longest text in characters. It is the size of the TEXT column: 4 bytes per character still fit in 65535 bytes.
     */
    public const MAX_LENGTH = 16000;

    /**
     * Clean a text for storage.
     *
     * @param string|null $text Text typed by the admin, for example "Write it for beginners".
     * @return string|null The clean text, or null when there is nothing but blanks.
     * @throws moodle_exception When the text is longer than the limit.
     */
    public static function normalize(?string $text): ?string {
        if ($text === null) {
            return null;
        }

        $valid = fix_utf8($text);
        $unified = str_replace(["\r\n", "\r"], "\n", $valid);
        $printable = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $unified);
        $trimmed = trim($printable);
        if ($trimmed === '') {
            return null;
        }

        if (\core_text::strlen($trimmed) > self::MAX_LENGTH) {
            throw new moodle_exception('error_text_too_long', 'local_coursegen', '', self::MAX_LENGTH);
        }

        return $trimmed;
    }
}
