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
 * Takes out of a text the markers nobody filled, so a student never reads `[[coursegen:…]]`.
 *
 * A safety net for texts the plugin writes as they come back from a template run (the summaries of the sections):
 * the service fills their markers, and anything it left (a failure, an unknown marker) is taken out here instead of
 * being shown. A paragraph or block that held only a marker goes with it; the content of a repeat block stays once.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class leftover_markers {
    /** @var string One marker, in either dialect: `[[coursegen:…]]`, `[[/coursegen:…]]` or `⟦coursegen:…⟧`. */
    private const MARKER = '(?:\[\[\s*\/?\s*coursegen:[^\]]*\]\]|⟦\s*\/?\s*coursegen:[^⟧]*⟧)';

    /**
     * The text without its unfilled markers.
     *
     * @param string $text
     * @return string
     */
    public static function strip(string $text): string {
        if (strpos($text, 'coursegen:') === false) {
            return $text;
        }
        $alone = '/<(p|div|span|h[1-6]|li)\b[^>]*>(?:\s|&nbsp;)*' . self::MARKER . '(?:\s|&nbsp;)*<\/\1>\s*/su';
        $text = (string) preg_replace($alone, '', $text);
        return (string) preg_replace('/' . self::MARKER . '/su', '', $text);
    }
}
