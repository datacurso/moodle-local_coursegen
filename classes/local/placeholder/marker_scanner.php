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

namespace local_coursegen\local\placeholder;

/**
 * Finds the placeholders of a text the way the strict template source parser of the AI service reads them.
 *
 * A marker is a body between `[[ ]]` or between the angle brackets of the second dialect. The bodies that count are
 * `coursegen:aiprompt: <instruction>` (a slot), `coursegen:repeat: <instruction>` with its `/coursegen:repeat` closer
 * (a block) and `coursegen:reference: <instruction>`. Any other text between brackets, such as a wiki page link, is
 * not a marker. A `coursegen:` body that is not one of the three, a marker without an instruction, a repeat block that
 * does not balance and an opener that never closes are reported as problems and never counted as placeholders.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class marker_scanner {
    /** @var string Where a marker opens, in both dialects. */
    private const OPENER = '/\[\[|⟦/u';

    /** @var string Where a marker closes, in both dialects. */
    private const CLOSER = '/\]\]|⟧/u';

    /** @var string The body that closes a repeat block. */
    private const REPEAT_CLOSER = '/^\/\s*coursegen:repeat\z/';

    /** @var string The body that opens a repeat block. */
    private const REPEAT_OPENER = '/^coursegen:repeat\s*(?::(?<text>.*))?\z/s';

    /** @var string The body of a slot. */
    private const SLOT = '/^coursegen:aiprompt\s*(?::(?<text>.*))?\z/s';

    /** @var string The body of a reference. */
    private const REFERENCE = '/^coursegen:reference\s*(?::(?<text>.*))?\z/s';

    /** @var string Regions whose markers nobody sees: comments, scripts and styles. */
    private const HIDDEN_REGIONS = '/<!--.*?-->|<script\b.*?<\/script>|<style\b.*?<\/style>/is';

    /** @var string What a text must not keep outside a marker: the start of any coursegen marker. */
    private const LEFTOVER = '/coursegen:/i';

    /** @var string[] The kinds of marker that are a placeholder. */
    private const PLACEHOLDER_KINDS = ['opener', 'slot', 'reference'];

    /** @var int Characters of a text shown in a problem. */
    private const EXCERPT_LENGTH = 60;

    /**
     * Scan one text.
     *
     * @param string $text The html of one field, e.g. '<p>[[coursegen:aiprompt: intro]]</p>'.
     * @return marker_scan
     */
    public static function scan(string $text): marker_scan {
        $clean = mb_scrub($text, 'UTF-8');
        $markers = self::markers_of($clean);
        $placeholders = self::count_placeholders($markers);
        $problems = self::problems_of($clean, $markers);
        $hidden = self::count_hidden($clean, $placeholders);

        return new marker_scan($placeholders, $problems, $hidden);
    }

    /**
     * The markers of a text, in order, each with its position, its kind and the problem it has, if any.
     *
     * @param string $text A text with valid utf-8.
     * @return array[] Each {start, end, kind, problem}.
     */
    private static function markers_of(string $text): array {
        $markers = [];
        $position = 0;
        while (true) {
            $bounds = self::next_marker($text, $position);
            if ($bounds === null) {
                break;
            }
            $body = substr($text, $bounds['bodystart'], $bounds['bodyend'] - $bounds['bodystart']);
            $classified = self::classify($body);
            $markers[] = [
                'start' => $bounds['start'],
                'end' => $bounds['end'],
                'kind' => $classified['kind'],
                'problem' => $classified['problem'],
            ];
            $position = $bounds['end'];
        }
        return $markers;
    }

    /**
     * The bounds of the next complete marker at or after a position. An opener with no closer after it is not a marker.
     *
     * @param string $text The text.
     * @param int $position Where to start looking, in bytes.
     * @return int[]|null {start, bodystart, bodyend, end} or null when no complete marker is left.
     */
    private static function next_marker(string $text, int $position): ?array {
        $opened = preg_match(self::OPENER, $text, $opening, PREG_OFFSET_CAPTURE, $position);
        if (!$opened) {
            return null;
        }
        $bodystart = $opening[0][1] + strlen($opening[0][0]);
        $closed = preg_match(self::CLOSER, $text, $closing, PREG_OFFSET_CAPTURE, $bodystart);
        if (!$closed) {
            return null;
        }
        return [
            'start' => $opening[0][1],
            'bodystart' => $bodystart,
            'bodyend' => $closing[0][1],
            'end' => $closing[0][1] + strlen($closing[0][0]),
        ];
    }

    /**
     * What one marker body is.
     *
     * @param string $body The text between the brackets, e.g. 'coursegen:aiprompt: course title'.
     * @return array {kind, problem}; kind is closer, opener, slot, reference, other or bad.
     */
    private static function classify(string $body): array {
        $stripped = trim($body);
        $closer = preg_match(self::REPEAT_CLOSER, $stripped);
        if ($closer) {
            return ['kind' => 'closer', 'problem' => null];
        }
        $known = [
            'opener' => self::REPEAT_OPENER,
            'slot' => self::SLOT,
            'reference' => self::REFERENCE,
        ];
        foreach ($known as $kind => $pattern) {
            $matched = preg_match($pattern, $stripped, $parts);
            if ($matched) {
                $instruction = $parts['text'] ?? '';
                return self::with_instruction($kind, $instruction, $stripped);
            }
        }
        return self::unknown_body($stripped);
    }

    /**
     * A marker of a known kind is only good when it carries an instruction.
     *
     * @param string $kind The kind, e.g. 'slot'.
     * @param string $instruction The text after the colon.
     * @param string $stripped The whole body, for the message.
     * @return array {kind, problem}
     */
    private static function with_instruction(string $kind, string $instruction, string $stripped): array {
        $text = trim($instruction);
        if ($text === '') {
            $problem = 'a marker without an instruction: ' . self::excerpt($stripped);
            return ['kind' => 'bad', 'problem' => $problem];
        }
        return ['kind' => $kind, 'problem' => null];
    }

    /**
     * A body that is not one of the three: other text between brackets is ignored, a coursegen one is an error.
     *
     * @param string $stripped The trimmed body.
     * @return array {kind, problem}
     */
    private static function unknown_body(string $stripped): array {
        $leftover = preg_match(self::LEFTOVER, $stripped);
        if ($leftover) {
            $problem = 'unknown coursegen marker: ' . self::excerpt($stripped);
            return ['kind' => 'bad', 'problem' => $problem];
        }
        return ['kind' => 'other', 'problem' => null];
    }

    /**
     * How many well formed placeholders the markers hold.
     *
     * @param array[] $markers The markers of a text.
     * @return int
     */
    private static function count_placeholders(array $markers): int {
        $count = 0;
        foreach ($markers as $marker) {
            $counts = in_array($marker['kind'], self::PLACEHOLDER_KINDS, true);
            if ($counts) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Every problem of a text: the markers that are wrong, repeat blocks that do not balance and markers left unclosed.
     *
     * @param string $text The text.
     * @param array[] $markers Its markers.
     * @return string[]
     */
    private static function problems_of(string $text, array $markers): array {
        $problems = self::marker_problems($markers);
        $balanced = self::repeats_balance($markers);
        if (!$balanced) {
            $problems[] = 'unbalanced repeat blocks: an opener without its closer or a closer without its opener';
        }
        $literal = self::literal_text($text, $markers);
        $leftover = preg_match(self::LEFTOVER, $literal, $found, PREG_OFFSET_CAPTURE);
        if ($leftover) {
            $rest = substr($literal, $found[0][1]);
            $problems[] = 'a coursegen marker is malformed or unclosed: ' . self::excerpt($rest);
        }
        return $problems;
    }

    /**
     * The problems the markers carry by themselves.
     *
     * @param array[] $markers The markers of a text.
     * @return string[]
     */
    private static function marker_problems(array $markers): array {
        $problems = [];
        foreach ($markers as $marker) {
            if ($marker['problem'] !== null) {
                $problems[] = $marker['problem'];
            }
        }
        return $problems;
    }

    /**
     * Whether every repeat opener has its closer, in order.
     *
     * @param array[] $markers The markers of a text.
     * @return bool
     */
    private static function repeats_balance(array $markers): bool {
        $depth = 0;
        foreach ($markers as $marker) {
            if ($marker['kind'] === 'opener') {
                $depth++;
            }
            if ($marker['kind'] === 'closer') {
                $depth--;
            }
            if ($depth < 0) {
                return false;
            }
        }
        return $depth === 0;
    }

    /**
     * The text that is left when every marker is taken out.
     *
     * @param string $text The text.
     * @param array[] $markers Its markers.
     * @return string
     */
    private static function literal_text(string $text, array $markers): string {
        $literal = '';
        $position = 0;
        foreach ($markers as $marker) {
            $literal .= substr($text, $position, $marker['start'] - $position);
            $position = $marker['end'];
        }
        return $literal . substr($text, $position);
    }

    /**
     * How many of the placeholders sit where nobody sees them: in a comment, a script or a style.
     *
     * @param string $text The text.
     * @param int $placeholders How many placeholders the whole text holds.
     * @return int
     */
    private static function count_hidden(string $text, int $placeholders): int {
        $visible = preg_replace(self::HIDDEN_REGIONS, '', $text);
        if ($visible === null) {
            return 0;
        }
        $markers = self::markers_of($visible);
        $shown = self::count_placeholders($markers);
        return $placeholders - $shown;
    }

    /**
     * A short excerpt of a text for a message.
     *
     * @param string $text Any text.
     * @return string
     */
    private static function excerpt(string $text): string {
        $single = preg_replace('/\s+/', ' ', $text);
        $trimmed = trim((string) $single);
        return mb_substr($trimmed, 0, self::EXCERPT_LENGTH);
    }
}
