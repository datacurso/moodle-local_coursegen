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
 * Tells whether a text holds at least one valid placeholder.
 *
 * A placeholder is what a mold's author writes to say what the AI service has
 * to write in its place. Two kinds count:
 *
 * - a marker, coursegen:aiprompt: followed by an instruction;
 * - a repeat block, coursegen:repeat: followed by an instruction, then the
 *   content to repeat, then the closing /coursegen:repeat.
 *
 * Each is written between double brackets or between the mathematical angle
 * brackets. The double bracket dialect can be switched off for a text whose
 * module already uses double brackets for its own syntax.
 *
 * The AI service no longer accepts a bare double bracket text, so one without
 * the coursegen: prefix does not count, and neither does a marker or a repeat
 * block whose instruction is empty.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_placeholder_scanner {
    /** Patterns of the dialect written with the mathematical angle brackets. Group 1 is the instruction. */
    private const ANGLE_PATTERNS = [
        '/⟦coursegen:aiprompt:([^⟧]*)⟧/u',
        '/⟦coursegen:repeat:([^⟧]*)⟧.*?⟦\/coursegen:repeat⟧/su',
    ];

    /** Patterns of the dialect written with double brackets. Group 1 is the instruction. */
    private const BRACKET_PATTERNS = [
        '/\[\[coursegen:aiprompt:((?:(?!\]\]).)*)\]\]/su',
        '/\[\[coursegen:repeat:((?:(?!\]\]).)*)\]\].*?\[\[\/coursegen:repeat\]\]/su',
    ];

    /**
     * Whether a text holds at least one valid placeholder.
     *
     * @param string $text The text to scan.
     * @param bool $allowbrackets Whether the double bracket dialect is read.
     * @return bool
     */
    public static function contains_placeholder(string $text, bool $allowbrackets): bool {
        $patterns = self::ANGLE_PATTERNS;
        if ($allowbrackets) {
            $patterns = array_merge(self::ANGLE_PATTERNS, self::BRACKET_PATTERNS);
        }
        return self::matches_any_pattern($text, $patterns);
    }

    /**
     * Whether a text has a placeholder with an instruction for any of the patterns.
     *
     * @param string $text
     * @param string[] $patterns Each with the instruction as its first group.
     * @return bool
     */
    private static function matches_any_pattern(string $text, array $patterns): bool {
        foreach ($patterns as $pattern) {
            $matches = [];
            preg_match_all($pattern, $text, $matches);
            $instructions = $matches[1];
            if (self::has_instruction($instructions)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether any of the instructions says something.
     *
     * @param string[] $instructions
     * @return bool
     */
    private static function has_instruction(array $instructions): bool {
        foreach ($instructions as $instruction) {
            if (self::says_something($instruction)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether an instruction has anything but markup, entities and blank space.
     *
     * @param string $instruction
     * @return bool
     */
    private static function says_something(string $instruction): bool {
        $stripped = strip_tags($instruction);
        $decoded = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $found = preg_match('/[^\s\p{Z}]/u', $decoded);
        return $found === 1;
    }
}
