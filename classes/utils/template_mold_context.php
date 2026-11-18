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
 * Where a slot of a mold sits, and what the AI may write there.
 *
 * A slot between tags is text: whatever the AI writes there is cleaned like any untrusted HTML. A slot inside the
 * quoted value of an attribute keeps what the AI wrote only when it cannot leave that value (no quote, no angle
 * bracket, no backtick), never in an attribute that runs code or styles, and in an address only when it is the
 * internal token of an activity link or, for a source, the privacy-enhanced YouTube player. Anywhere else (inside a
 * script, a style block, a comment, an unquoted attribute) a slot is unsafe and its mold is not used.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_mold_context {
    /** @var string A slot between tags. */
    public const TEXT = 'text';

    /** @var string A slot where nothing the AI writes can be kept safely. */
    public const UNSAFE = 'unsafe';

    /** @var string The prefix of a slot inside an attribute value, followed by the attribute's name. */
    private const ATTRIBUTE = 'attr:';

    /** @var string An attribute name and an opening quote whose value is still open at the end of the text. */
    private const OPEN_VALUE = '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(["\'])[^"\'<>]*$/';

    /**
     * @var string Attributes whose value is never left to the AI: events, styles, embedded documents, and every address
     * other than a link or a source, which have their own rules.
     */
    private const CODE_ATTRIBUTE = '/^(?:on|xmlns|style$|srcset$|srcdoc$|action$|formaction$|xlink:href$|data$|poster$|'
        . 'background$|cite$|longdesc$|lowsrc$|dynsrc$|ping$|manifest$|codebase$|archive$|classid$|content$|http-equiv$)/';

    /** @var string What a kept attribute value may not hold. */
    private const VALUE_BREAK = '/["\'<>\x60]/';

    /** @var string The internal token of a link to an activity of the course, resolved once it exists. */
    private const LINK_TOKEN = '~^\$@COURSEGENLINK\*[A-Za-z0-9_.\-]+@\$$~';

    /** @var string A web address. */
    private const WEB_ADDRESS = '~^https?://[^\s]+$~i';

    /** @var string The privacy-enhanced YouTube player, the only source the AI service gives a video slot. */
    private const VIDEO_PLAYER = '~^https://www\.youtube-nocookie\.com/embed/[A-Za-z0-9_-]+(?:\?[^\s]*)?$~';

    /** @var string[] Elements whose content is not HTML, each with the pattern of its opening and closing tags. */
    private const RAW_BLOCKS = [
        ['/<script\b/i', '/<\/script\b/i'],
        ['/<style\b/i', '/<\/style\b/i'],
        ['/<!--/', '/-->/'],
    ];

    /**
     * The context of the slot at an offset of a mold.
     *
     * @param string $mold
     * @param int $offset Where the slot's marker starts.
     * @return string TEXT, UNSAFE, or the prefix of an attribute followed by its lowercase name.
     */
    public static function at(string $mold, int $offset): string {
        $before = substr($mold, 0, $offset);
        if (self::inside_raw_block($before)) {
            return self::UNSAFE;
        }
        $lastopen = strrpos($before, '<');
        $lastclose = strrpos($before, '>');
        if ($lastopen === false || ($lastclose !== false && $lastclose > $lastopen)) {
            return self::TEXT;
        }
        $tag = substr($before, $lastopen);
        if (!preg_match(self::OPEN_VALUE, $tag, $attribute)) {
            return self::UNSAFE;
        }
        $name = strtolower($attribute[1]);
        return self::ATTRIBUTE . $name;
    }

    /**
     * Whether what the AI wrote may stay in a slot of this context as it is.
     *
     * Text is always accepted here because it is cleaned afterwards; an attribute value is kept as it is, so it is
     * accepted only when it is safe.
     *
     * @param string $context
     * @param string $value
     * @return bool
     */
    public static function accepts(string $context, string $value): bool {
        if ($context === self::TEXT) {
            return true;
        }
        if (strpos($context, self::ATTRIBUTE) !== 0) {
            return false;
        }
        $name = substr($context, strlen(self::ATTRIBUTE));
        if (preg_match(self::VALUE_BREAK, $value) || preg_match(self::CODE_ATTRIBUTE, $name)) {
            return false;
        }
        if ($name === 'href') {
            return self::is_link_token($value) || (bool) preg_match(self::WEB_ADDRESS, $value);
        }
        if ($name === 'src') {
            return self::is_link_token($value) || (bool) preg_match(self::VIDEO_PLAYER, $value);
        }
        return true;
    }

    /**
     * Whether a slot of this context is text, which is cleaned rather than kept as it is.
     *
     * @param string $context
     * @return bool
     */
    public static function is_text(string $context): bool {
        return $context === self::TEXT;
    }

    /**
     * Whether a value is the internal token of a link to an activity.
     *
     * @param string $value
     * @return bool
     */
    private static function is_link_token(string $value): bool {
        return (bool) preg_match(self::LINK_TOKEN, $value);
    }

    /**
     * Whether the end of a text is inside a script, a style block or a comment.
     *
     * @param string $before
     * @return bool
     */
    private static function inside_raw_block(string $before): bool {
        foreach (self::RAW_BLOCKS as [$opening, $closing]) {
            $opened = self::last_offset($opening, $before);
            $closed = self::last_offset($closing, $before);
            if ($opened > $closed) {
                return true;
            }
        }
        return false;
    }

    /**
     * The offset of the last match of a pattern in a text, or -1.
     *
     * @param string $pattern
     * @param string $text
     * @return int
     */
    private static function last_offset(string $pattern, string $text): int {
        $count = preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
        if (!$count) {
            return -1;
        }
        $last = end($matches[0]);
        return (int) $last[1];
    }
}
