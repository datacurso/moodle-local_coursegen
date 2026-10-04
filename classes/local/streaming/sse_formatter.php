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

namespace local_coursegen\local\streaming;

/**
 * Writes server-sent events blocks.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sse_formatter {
    /**
     * Build the block of one event.
     *
     * Line breaks in the payload become one data field per line, and line breaks in the name are dropped,
     * so neither can add fields of their own.
     *
     * @param string $event Event name.
     * @param string $data Event payload.
     * @return string Block that ends in a blank line.
     */
    public static function event(string $event, string $data): string {
        $name = self::without_line_breaks($event);
        $lines = preg_split('/\r\n|\r|\n/', $data);

        $block = 'event: ' . $name . "\n";
        foreach ($lines as $line) {
            $block .= 'data: ' . $line . "\n";
        }

        return $block . "\n";
    }

    /**
     * Build a comment block, which clients ignore and proxies see as traffic.
     *
     * @param string $text Comment text.
     * @return string Block that ends in a blank line.
     */
    public static function comment(string $text): string {
        $clean = self::without_line_breaks($text);
        return ': ' . $clean . "\n\n";
    }

    /**
     * Remove CR and LF from a single-line value.
     *
     * @param string $value Value to clean.
     * @return string
     */
    private static function without_line_breaks(string $value): string {
        return str_replace(["\r", "\n"], '', $value);
    }
}
