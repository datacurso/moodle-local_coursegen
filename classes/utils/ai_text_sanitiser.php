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
     * @var string[] Keys whose string is a JSON document of an H5P package, not text to show.
     *
     * Cleaning such a string as markup would turn the escaped quotes of its HTML texts into raw quotes and
     * break the document, so it is left as it is: H5P validates and filters its own content when it saves
     * and when it shows it.
     */
    private const JSON_KEYS = ['content_json', 'h5p_json'];

    /** @var string[] The attributes that flag a link for later work, kept through the cleaning (value "1", links only). */
    private const LINK_MARKERS = ['data-coursegen-embed', 'data-coursegen-syllabus'];

    /**
     * Clean every string of a value, recursively through arrays and objects.
     *
     * The JSON documents of an H5P package (see JSON_KEYS) are not cleaned.
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
                if (is_string($item) && in_array($key, self::JSON_KEYS, true)) {
                    continue;
                }
                $value[$key] = self::sanitise($item);
            }
            return $value;
        }
        if (is_object($value)) {
            foreach (get_object_vars($value) as $key => $item) {
                if (is_string($item) && in_array($key, self::JSON_KEYS, true)) {
                    continue;
                }
                $value->$key = self::sanitise($item);
            }
        }
        return $value;
    }

    /**
     * Clean one string the AI wrote.
     *
     * A text of a template run that matches one of the template's molds is rebuilt from it (see template_molds);
     * any other is cleaned whole.
     *
     * @param string $text
     * @return string
     */
    public static function sanitise_string(string $text): string {
        $rebuilt = template_molds::rebuild($text, [self::class, 'clean_untrusted']);
        if ($rebuilt !== null) {
            return $rebuilt;
        }
        return self::clean_untrusted($text);
    }

    /**
     * Clean HTML the AI wrote the way Moodle cleans HTML from a person it does not trust.
     *
     * The second cleaning some activity settings apply before storing a text: a text of a template run that
     * matches one of the template's molds is rebuilt from it (see template_molds), any other is passed to
     * clean_text() as it is.
     *
     * @param string $text
     * @return string
     */
    public static function clean_html(string $text): string {
        $rebuilt = template_molds::rebuild($text, [self::class, 'clean_html_text']);
        if ($rebuilt !== null) {
            return $rebuilt;
        }
        return self::clean_html_text($text);
    }

    /**
     * Clean HTML with clean_text() and the HTML format.
     *
     * @param string $text
     * @return string
     */
    public static function clean_html_text(string $text): string {
        return clean_text($text, FORMAT_HTML);
    }

    /**
     * Clean one string the AI wrote, with no template mold.
     *
     * @param string $text
     * @return string
     */
    public static function clean_untrusted(string $text): string {
        $text = self::neutralise_script_url($text);
        if (!preg_match(self::MARKUP, $text)) {
            return $text;
        }
        // The service may send JSON-escaped quotes inside the markup; they are restored first, so
        // that the cleaning sees the attributes as a browser would.
        $text = str_replace(['\\"', "\\'"], ['"', "'"], $text);
        $frames = [];
        $text = self::set_aside_site_file_frames($text, $frames);
        $classes = self::marker_classes();
        $text = self::carry_link_markers($text, $classes);
        return self::restore_link_markers(strtr(clean_text($text, FORMAT_HTML), $frames), $classes);
    }

    /**
     * A unique class for each link marker, standing for it while the text is cleaned.
     *
     * @return array<string,string> Marker attribute => class.
     */
    private static function marker_classes(): array {
        $classes = [];
        foreach (self::LINK_MARKERS as $marker) {
            $classes[$marker] = 'cgmark' . bin2hex(random_bytes(4));
        }
        return $classes;
    }

    /**
     * Move the markers of each link into classes, which the cleaning keeps.
     *
     * A link that asks to be shown as the player of its H5P activity carries `data-coursegen-embed="1"`, and a link
     * that offers the syllabus the professor uploaded carries `data-coursegen-syllabus="1"`; the cleaning removes
     * every attribute it does not know. A marker only flags the link: `embed_link` and `syllabus_link` act on it
     * once the course exists. Only the exact marker on a link is carried, so the same attribute on another element
     * or with another value is still removed, and everything else a link carries is cleaned as before.
     *
     * @param string $text The text before the cleaning.
     * @param array<string,string> $classes Marker attribute => the unique class that stands for it.
     * @return string The text with the markers of each link written as those classes.
     */
    private static function carry_link_markers(string $text, array $classes): string {
        foreach ($classes as $marker => $class) {
            if (stripos($text, $marker) === false) {
                continue;
            }
            $callback = static function (array $matches) use ($marker, $class): string {
                $attributes = $matches[1];
                $pattern = '~\s+' . preg_quote($marker, '~') . '\s*=\s*(["\'])1\1~i';
                if (!preg_match($pattern, $attributes)) {
                    return $matches[0];
                }
                $attributes = preg_replace($pattern, '', $attributes, 1);
                if (preg_match('~\sclass\s*=\s*(["\'])(.*?)\1~is', $attributes)) {
                    $attributes = preg_replace('~(\sclass\s*=\s*)(["\'])(.*?)\2~is', '$1$2$3 ' . $class . '$2', $attributes, 1);
                } else {
                    $attributes .= ' class="' . $class . '"';
                }
                return '<a' . $attributes . '>';
            };
            $text = preg_replace_callback('~<a\b([^>]*)>~i', $callback, $text) ?? $text;
        }
        return $text;
    }

    /**
     * Write the markers back on each link that carried them through the cleaning as classes.
     *
     * @param string $text The cleaned text.
     * @param array<string,string> $classes Marker attribute => the unique class that stood for it.
     * @return string The text with each marker (value "1") back on its links and the classes removed again.
     */
    private static function restore_link_markers(string $text, array $classes): string {
        foreach ($classes as $marker => $class) {
            if (strpos($text, $class) === false) {
                continue;
            }
            $callback = static function (array $matches) use ($marker, $class): string {
                $anchor = $matches[0];
                if (strpos($anchor, $class) === false) {
                    return $anchor;
                }
                $anchor = preg_replace('~\sclass\s*=\s*"\s*' . preg_quote($class, '~') . '\s*"~', '', $anchor, 1, $removed);
                if (!$removed) {
                    $anchor = preg_replace('~(\sclass\s*=\s*"[^"]*?)\s*' . preg_quote($class, '~') . '~', '$1', $anchor, 1);
                }
                return preg_replace('~<a\b~i', '<a ' . $marker . '="1"', $anchor, 1);
            };
            $text = preg_replace_callback('~<a\b[^>]*>~i', $callback, $text) ?? $text;
        }
        return $text;
    }

    /**
     * Take out the frames that show a file of this site, which the cleaning would remove, and give each a token.
     *
     * Moodle's cleaning never lets an iframe through, but a template may show the file the teacher brings in a
     * frame that points at the file's address on this site. Such a frame is rebuilt from the few attributes a
     * frame needs, so nothing else it carried (events, styles, other sources) survives, and put back after the
     * cleaning. So is a frame that asks to show the syllabus the professor uploads (see syllabus_link). A frame that
     * points anywhere else is left to the cleaning, which removes it.
     *
     * @param string $text
     * @param string[] $frames Filled with the rebuilt frame of each token.
     * @return string The text, with a token where each of those frames was.
     */
    private static function set_aside_site_file_frames(string $text, array &$frames): string {
        global $CFG;

        if (stripos($text, '<iframe') === false) {
            return $text;
        }
        $prefix = [$CFG->wwwroot . '/pluginfile.php/', '@@PLUGINFILE@@/'];
        $salt = bin2hex(random_bytes(4));
        $callback = static function (array $matches) use (&$frames, $prefix, $salt): string {
            $frame = self::syllabus_frame($matches[1]) ?? self::site_file_frame($matches[1], $prefix);
            if ($frame === null) {
                return $matches[0];
            }
            $token = 'coursegenframe' . $salt . count($frames) . 'x';
            $frames[$token] = $frame;
            return $token;
        };
        return preg_replace_callback('~<iframe\b([^>]*)>\s*</iframe\s*>~iu', $callback, $text) ?? $text;
    }

    /**
     * The frame that asks to show the syllabus (see syllabus_link), written again with only its mark and its size.
     *
     * @param string $attributes What the opening tag holds.
     * @return string|null Null when the frame does not carry the syllabus mark.
     */
    private static function syllabus_frame(string $attributes): ?string {
        if (!preg_match('~\sdata-coursegen-syllabus\s*=\s*(["\'])1\1~i', ' ' . $attributes)) {
            return null;
        }
        $kept = ['src="about:blank"', 'data-coursegen-syllabus="1"'];
        foreach (['width', 'height'] as $name) {
            if (preg_match('~\b' . $name . '\s*=\s*(["\'])(\d+(?:\.\d+)?(?:%|px)?)\1~i', $attributes, $size)) {
                $kept[] = $name . '="' . $size[2] . '"';
            }
        }
        return '<iframe ' . implode(' ', $kept) . '></iframe>';
    }

    /**
     * The frame of a file of this site, written again with only the attributes a frame needs.
     *
     * @param string $attributes What the opening tag holds.
     * @param string[] $prefixes The starts a file address of this site may have: its pluginfile address, or the
     *     placeholder that Moodle resolves to the files of the activity itself.
     * @return string|null Null when the frame does not point at a file of this site.
     */
    private static function site_file_frame(string $attributes, array $prefixes): ?string {
        preg_match_all('~\b([a-z-]+)\s*=\s*(["\'])(.*?)\2~isu', $attributes, $found, PREG_SET_ORDER);
        $kept = [];
        $source = '';
        foreach ($found as $attribute) {
            $name = strtolower($attribute[1]);
            $value = $attribute[3];
            if ($name === 'src') {
                $source = html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $kept[] = 'src="' . s($source) . '"';
            } else if (($name === 'width' || $name === 'height') && preg_match('~^\d+(?:\.\d+)?(?:%|px)?$~', $value)) {
                $kept[] = $name . '="' . $value . '"';
            }
        }
        $known = false;
        foreach ($prefixes as $prefix) {
            $known = $known || str_starts_with($source, $prefix);
        }
        if (!$known || preg_match('~[\x00-\x20]~', $source) || str_contains($source, '/../')) {
            return null;
        }
        return '<iframe ' . implode(' ', $kept) . '></iframe>';
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
