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
 * Points the links and frames a template writes to the syllabus at the file the professor uploaded.
 *
 * A template can offer the syllabus of the course with a link marked data-coursegen-syllabus="1" (for example, the
 * weekly plan offering the didactic guide as a PDF). Once the course exists, the link points at the copy of the
 * uploaded file kept with the text, so the student opens the same document the course was made from instead of a
 * version written again. A frame marked the same way shows the file in the page (or, when the file is not one a
 * browser shows, becomes a link to it). Without an uploaded file both are taken out: a link to nothing is worse than none.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class syllabus_link {
    /** @var string An anchor that asks to point at the syllabus. */
    private const ANCHOR_PATTERN = '/<a\b[^>]*\bdata-coursegen-syllabus=(["\'])1\1[^>]*>.*?<\/a>/is';

    /** @var string A frame that asks to show the syllabus, with its closing tag. */
    private const FRAME_PATTERN = '/<iframe\b[^>]*\bdata-coursegen-syllabus=(["\'])1\1[^>]*>\s*<\/iframe\s*>/is';

    /** @var string[] The kinds of file a browser shows in a frame; any other is offered as a link instead. */
    private const FRAMEABLE = ['pdf'];

    /**
     * Whether a text has a link to the syllabus.
     *
     * @param string $text
     * @return bool
     */
    public static function present(string $text): bool {
        return strpos($text, 'data-coursegen-syllabus') !== false
            && (preg_match(self::ANCHOR_PATTERN, $text) === 1 || preg_match(self::FRAME_PATTERN, $text) === 1);
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
        $address = ($filename === null || $filename === '') ? null : '@@PLUGINFILE@@/' . rawurlencode($filename);
        $text = preg_replace_callback(self::FRAME_PATTERN, static function (array $match) use ($address, $filename): string {
            return $address === null ? '' : self::frame($match[0], $address, (string) $filename);
        }, $text);
        return preg_replace_callback(self::ANCHOR_PATTERN, static function (array $match) use ($address): string {
            return $address === null ? '' : self::with_href($match[0], $address);
        }, $text);
    }

    /**
     * A frame that shows the syllabus, or a link to download it when a browser cannot show its kind of file.
     *
     * @param string $frame The marked frame.
     * @param string $address Where the copy of the syllabus is, as the text stores it.
     * @param string $filename The name of that copy.
     * @return string
     */
    private static function frame(string $frame, string $address, string $filename): string {
        $value = htmlspecialchars($address, ENT_QUOTES | ENT_HTML5);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, self::FRAMEABLE, true)) {
            return '<p><a href="' . $value . '">' . s($filename) . '</a></p>';
        }
        $frame = preg_replace('/\s+data-coursegen-syllabus=(["\'])1\1/i', '', $frame, 1);
        return preg_replace('/\bsrc=(["\']).*?\1/is', 'src="' . $value . '"', $frame, 1);
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
