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

namespace local_coursegen\utils;

use local_coursegen\local\service\template_activity_export;

/**
 * Rebuilds a text of a template run from the template's own HTML, cleaning only what the AI wrote.
 *
 * A course made from a template receives each text of the template with its marked parts filled by the AI service.
 * Cleaning that whole text as untrusted HTML takes out the interactive parts the author built (answer boxes, buttons,
 * hidden messages, the script that runs them). While an activity of a template run is created, a text that matches
 * one of the template's texts (its mold) is rebuilt instead: the author's parts are copied from the mold this site
 * holds, never taken from the service's answer, and each part the AI wrote is cleaned like any untrusted HTML (or,
 * inside an attribute, kept only when it cannot leave its value). A text that matches no mold is cleaned whole, as
 * before. The molds are the texts of the template's activities exactly as the template export sends them.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_molds {
    /** @var array<int,array> The programs of the molds of each template course read so far. */
    private static array $bycourse = [];

    /** @var array The programs of the molds of the activity being created; empty outside a template run. */
    private static array $current = [];

    /**
     * Use the molds of a template's course for the activity about to be created, or none.
     *
     * Called before every activity is created, so each one uses the molds of its own template, if any.
     *
     * @param int|null $courseid The template's course, or null when the activity comes from no template.
     */
    public static function use_course(?int $courseid): void {
        if (!$courseid) {
            self::$current = [];
            return;
        }
        self::$current = self::programs_of($courseid);
    }

    /**
     * Use these programs directly, for a test.
     *
     * @param array $programs Programs of template_mold_parser.
     */
    public static function use_programs(array $programs): void {
        self::$current = $programs;
    }

    /**
     * Forget every mold read, so a later run reads its template again.
     */
    public static function reset(): void {
        self::$bycourse = [];
        self::$current = [];
    }

    /**
     * The text rebuilt from the mold it matches, or null when no template is in use or it matches none.
     *
     * @param string $text A text the AI service returned.
     * @param callable $clean Cleans what the AI wrote between tags: string in, string out.
     * @return string|null
     */
    public static function rebuild(string $text, callable $clean): ?string {
        foreach (self::$current as $program) {
            $parts = self::parts_of($program, $text);
            if ($parts !== null) {
                return self::joined($parts, $clean);
            }
        }
        return null;
    }

    /**
     * The parts of a text read as one mold, after a quick check of the mold's first and last text.
     *
     * @param array $program
     * @param string $text
     * @return array|null
     */
    private static function parts_of(array $program, string $text): ?array {
        $first = reset($program);
        $last = end($program);
        if ($first[0] === 'lit' && strncmp($text, $first[1], strlen($first[1])) !== 0) {
            return null;
        }
        if ($last[0] === 'lit' && !str_ends_with($text, $last[1])) {
            return null;
        }
        return template_mold_matcher::match($program, $text);
    }

    /**
     * The text of the parts: the author's parts as they are, the AI's text cleaned, its attribute values as matched.
     *
     * @param array $parts
     * @param callable $clean
     * @return string
     */
    private static function joined(array $parts, callable $clean): string {
        $text = '';
        foreach ($parts as $part) {
            $piece = $part[1];
            if ($part[0] === 'gap' && template_mold_context::is_text($part[2])) {
                $cleaned = $clean($piece);
                $piece = self::without_open_brackets($cleaned);
            }
            $text .= $piece;
        }
        return $text;
    }

    /**
     * Escape every angle bracket of a cleaned text that does not start a tag.
     *
     * What the AI wrote sits next to the author's text, so a bracket left at its end could start a tag with the
     * author's text that follows it.
     *
     * @param string $text
     * @return string
     */
    private static function without_open_brackets(string $text): string {
        return preg_replace('/<(?![a-z!\/?])/i', '&lt;', $text);
    }

    /**
     * The programs of the molds of a template's course, read once per course.
     *
     * @param int $courseid
     * @return array
     */
    private static function programs_of(int $courseid): array {
        if (!isset(self::$bycourse[$courseid])) {
            $molds = self::molds_of($courseid);
            self::$bycourse[$courseid] = self::programs_for($molds);
        }
        return self::$bycourse[$courseid];
    }

    /**
     * The texts with markup of every activity of a course, as the template export sends them.
     *
     * @param int $courseid
     * @return string[]
     */
    private static function molds_of(int $courseid): array {
        $modinfo = get_fast_modinfo($courseid);
        $cms = $modinfo->get_cms();
        $molds = [];
        foreach ($cms as $cm) {
            $parameters = template_activity_export::parameters_for($cm);
            $molds = self::with_markup_strings($parameters['structure'], $molds);
        }
        return array_keys($molds);
    }

    /**
     * Add the strings with markup of a value, recursively, as keys of a set.
     *
     * @param mixed $value
     * @param array $set
     * @return array
     */
    private static function with_markup_strings(mixed $value, array $set): array {
        if (is_string($value) && strpos($value, '<') !== false) {
            $set[$value] = true;
            return $set;
        }
        if (!is_array($value) && !is_object($value)) {
            return $set;
        }
        foreach ((array) $value as $item) {
            $set = self::with_markup_strings($item, $set);
        }
        return $set;
    }

    /**
     * The programs of some molds, leaving out the ones that cannot be matched safely.
     *
     * @param string[] $molds
     * @return array
     */
    private static function programs_for(array $molds): array {
        $programs = [];
        foreach ($molds as $mold) {
            $program = template_mold_parser::parse($mold);
            if ($program !== null) {
                $programs[] = $program;
            }
        }
        return $programs;
    }
}
