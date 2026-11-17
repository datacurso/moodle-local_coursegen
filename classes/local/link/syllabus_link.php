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
 * Points the links a template writes to the syllabus at the file the professor uploaded.
 *
 * A template can offer the syllabus of the course with a link marked data-coursegen-syllabus="1" (for example, the
 * weekly plan offering the didactic guide as a PDF). Once the course exists, the link points at the copy of the
 * uploaded file kept with the text, so the student opens the same document the course was made from instead of a
 * version written again. Without an uploaded file the link is taken out: a link to nothing is worse than none.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class syllabus_link {
    /** @var string An anchor that asks to point at the syllabus. */
    private const ANCHOR_PATTERN = '/<a\b[^>]*\bdata-coursegen-syllabus=(["\'])1\1[^>]*>.*?<\/a>/is';

    /**
     * Whether a text has a link to the syllabus.
     *
     * @param string $text
     * @return bool
     */
    public static function present(string $text): bool {
        return strpos($text, 'data-coursegen-syllabus') !== false && preg_match(self::ANCHOR_PATTERN, $text) === 1;
    }

    /**
     * Point each syllabus link at a file kept with the text, or take the links out when there is no file.
     *
     * @param string $text
     * @param string|null $filename The name of the file in the file area of the text; null when none was uploaded.
     * @return string
     */
    public static function apply(string $text, ?string $filename): string {
        if (!self::present($text)) {
            return $text;
        }
        return preg_replace_callback(self::ANCHOR_PATTERN, static function (array $match) use ($filename): string {
            if ($filename === null || $filename === '') {
                return '';
            }
            return self::with_href($match[0], '@@PLUGINFILE@@/' . rawurlencode($filename));
        }, $text);
    }

    /**
     * An anchor with its href set, keeping every other attribute and its content.
     *
     * @param string $anchor
     * @param string $href
     * @return string
     */
    private static function with_href(string $anchor, string $href): string {
        $value = htmlspecialchars($href, ENT_QUOTES | ENT_HTML5);
        if (preg_match('/\bhref=(["\']).*?\1/is', $anchor)) {
            return preg_replace('/\bhref=(["\']).*?\1/is', 'href="' . $value . '"', $anchor, 1);
        }
        return preg_replace('/^<a\b/i', '<a href="' . $value . '"', $anchor, 1);
    }
}
