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

/**
 * Cleans the HTML the AI writes before it is stored.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\utils;

defined('MOODLE_INTERNAL') || die();

/**
 * The text of an activity comes from a model that can be told what to write by the documents it reads, and several
 * modules show their text without cleaning it, trusting that it was cleaned when it was saved. A person's text is
 * cleaned by the editor on save; the text written here never goes through it, so it is cleaned here, the way Moodle
 * cleans any text that is not trusted: scripts, event attributes and script URLs are removed, and layout and
 * inline styles stay.
 */
final class html_cleaner {
    /**
     * The text as Moodle would keep it for an untrusted author.
     *
     * @param string $html
     * @return string
     */
    public static function clean(string $html): string {
        if ($html === '' || strpos($html, '<') === false) {
            return $html;
        }
        return clean_text($html, FORMAT_HTML);
    }
}
