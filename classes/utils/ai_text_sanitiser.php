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

/**
 * The single point where the text the AI writes is made safe before any activity stores it.
 *
 * The text returned by the model is untrusted input: a document, a syllabus or a prompt the AI reads
 * can carry hidden instructions, and some activities show what they store without cleaning it (the
 * lesson pages, for one). Every string of an AI result is therefore cleaned here, recursively and
 * whatever the activity type, before create_mod_service hands the result to Moodle and to the
 * activity settings classes:
 *
 * - a string with markup is cleaned the way Moodle cleans HTML from a person it does not trust
 *   (clean_text() with FORMAT_HTML): scripts, event handler attributes and javascript: links go,
 *   the legitimate layout stays;
 * - a string that starts with a script scheme (javascript:, vbscript:) stops being a URL, since a
 *   field holding a URL (the URL activity, a URL field of a database) would turn it into a link:
 *   a space goes before the colon, which keeps a title such as "JavaScript: the basics" readable,
 *   and a scheme written with entities or control characters, which no title needs, is dropped;
 * - any other string, a plain title, a number, a choice key or a file path, is kept as it is.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ai_text_sanitiser {
    /** @var string Pattern of a string that holds markup: a tag, a comment or a declaration. */
    private const MARKUP = '/<[a-z!\/?]/i';

    /** @var string Pattern of a script scheme, once entities, whitespace and control characters are removed. */
    private const SCRIPT_URL = '/^(?:java|vb)script:/i';

    /**
     * Clean every string of a value, recursively through arrays and objects.
     *
     * @param mixed $value An AI result, or any part of it.
     * @return mixed The same structure with every string cleaned.
     */
    public static function sanitise(mixed $value): mixed {
        if (is_string($value)) {
            return self::sanitise_string($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::sanitise($item);
            }
            return $value;
        }
        if (is_object($value)) {
            foreach (get_object_vars($value) as $key => $item) {
                $value->$key = self::sanitise($item);
            }
        }
        return $value;
    }

    /**
     * Clean one string the AI wrote.
     *
     * @param string $text
     * @return string
     */
    public static function sanitise_string(string $text): string {
        $text = self::neutralise_script_url($text);
        if (!preg_match(self::MARKUP, $text)) {
            return $text;
        }
        // The service may send JSON-escaped quotes inside the markup; they are restored first, so
        // that the cleaning sees the attributes as a browser would.
        $text = str_replace(['\\"', "\\'"], ['"', "'"], $text);
        return clean_text($text, FORMAT_HTML);
    }

    /**
     * Make sure a string does not start with a script scheme a browser would run as a URL.
     *
     * Browsers ignore entities, control characters and whitespace around and inside the scheme,
     * so the check is made on the string without them. A scheme written plainly gets a space before
     * its colon, which a browser no longer reads as a scheme; one written any other way is dropped.
     *
     * @param string $text
     * @return string
     */
    private static function neutralise_script_url(string $text): string {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $compact = preg_replace('/[\x00-\x20]+/', '', $decoded);
        if (!preg_match(self::SCRIPT_URL, $compact)) {
            return $text;
        }
        if (preg_match('/^\s*(?:java|vb)script:/i', $text)) {
            return preg_replace('/^(\s*(?:java|vb)script):/i', '$1 :', $text);
        }
        return '';
    }
}
