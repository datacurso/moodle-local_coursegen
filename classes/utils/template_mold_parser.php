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
 * Reads a text of a template (a mold) into the program the matcher runs.
 *
 * A mold is the author's HTML with markers the AI service fills:
 * `[[coursegen:aiprompt: ...]]` (or `⟦...⟧`) is a slot, and
 * `[[coursegen:repeat: ...]] ... [[/coursegen:repeat]]` is a block that repeats zero or more times, and
 * `[[coursegen:optional: ...]] ... [[/coursegen:optional]]` is a block that appears zero times or once. Any other
 * `[[...]]` reads as a slot too, as the service reads it.
 *
 * The program is a list of instructions:
 * - `['lit', text]`: the author's own text, which must appear byte for byte;
 * - `['gap', context]`: what the AI wrote for a slot (see template_mold_context);
 * - `['split', target]`: a block may be skipped here, jumping to `target`, or run (once more);
 * - `['start', id]` and `['end', id, next]`: the bounds of one run of a block. After a run of a repeat, `next` is its
 *   split, so it may run again; after the run of an optional block, `next` is the instruction after its end, so it
 *   runs at most once.
 *
 * A mold whose markers do not pair, or with a slot where nothing the AI writes can be kept safely (inside a
 * script, a style block, a comment, an unquoted attribute), gives no program.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_mold_parser {
    /** @var string A marker in either dialect; its label is the first group. */
    private const MARKER = '/(?:\[\[|⟦)(.*?)(?:\]\]|⟧)/su';

    /** @var string The label of the marker that opens a repeat, stray brackets around it allowed. */
    private const REPEAT_OPEN = '~^[\s\[(]*coursegen:repeat:~i';

    /** @var string The label of the marker that closes a repeat, stray brackets around it allowed. */
    private const REPEAT_CLOSE = '~^[\s\[(]*/\s*coursegen:repeat~i';

    /** @var string The label of the marker that opens an optional block, stray brackets around it allowed. */
    private const OPTIONAL_OPEN = '~^[\s\[(]*coursegen:optional:~i';

    /** @var string The label of the marker that closes an optional block, stray brackets around it allowed. */
    private const OPTIONAL_CLOSE = '~^[\s\[(]*/\s*coursegen:optional~i';

    /**
     * The program of a mold, or null when the mold cannot be matched safely.
     *
     * @param string $mold The author's text, markers included.
     * @return array|null
     */
    public static function parse(string $mold): ?array {
        $found = preg_match_all(self::MARKER, $mold, $markers, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        if (!$found) {
            return [['lit', $mold]];
        }
        $state = ['program' => [], 'open' => [], 'position' => 0, 'valid' => true];
        foreach ($markers as $marker) {
            $state = self::read_marker($mold, $marker, $state);
        }
        if (!$state['valid'] || $state['open']) {
            return null;
        }
        $rest = substr($mold, $state['position']);
        return self::with_literal($state['program'], $rest);
    }

    /**
     * Add one marker, and the author's text before it, to the program being built.
     *
     * @param string $mold
     * @param array $marker The match: the whole marker with its offset, and its label.
     * @param array $state The program so far, the blocks still open, where the text goes on, whether it is valid.
     * @return array The new state.
     */
    private static function read_marker(string $mold, array $marker, array $state): array {
        if (!$state['valid']) {
            return $state;
        }
        [$whole, $offset] = $marker[0];
        $label = $marker[1][0];
        $before = substr($mold, $state['position'], $offset - $state['position']);
        $state['program'] = self::with_literal($state['program'], $before);
        $state['position'] = $offset + strlen($whole);
        if (preg_match(self::REPEAT_CLOSE, $label)) {
            return self::close_block($state, 'repeat');
        }
        if (preg_match(self::OPTIONAL_CLOSE, $label)) {
            return self::close_block($state, 'optional');
        }
        if (preg_match(self::REPEAT_OPEN, $label)) {
            return self::open_block($state, 'repeat');
        }
        if (preg_match(self::OPTIONAL_OPEN, $label)) {
            return self::open_block($state, 'optional');
        }
        $context = template_mold_context::at($mold, $offset);
        if ($context === template_mold_context::UNSAFE) {
            $state['valid'] = false;
            return $state;
        }
        $state['program'][] = ['gap', $context];
        return $state;
    }

    /**
     * Start a block: a split that may skip it, then the start of one run.
     *
     * @param array $state
     * @param string $block 'repeat' or 'optional'.
     * @return array
     */
    private static function open_block(array $state, string $block): array {
        $split = count($state['program']);
        $depth = count($state['open']);
        $id = $depth . ':' . $split;
        $state['program'][] = ['split', -1];
        $state['program'][] = ['start', $id];
        $state['open'][] = ['split' => $split, 'id' => $id, 'block' => $block];
        return $state;
    }

    /**
     * End the innermost block, which must be of the same kind: its split now skips to here, and its run goes back to
     * the split (a repeat) or on past its end (an optional block, which so runs at most once).
     *
     * @param array $state
     * @param string $block 'repeat' or 'optional'.
     * @return array
     */
    private static function close_block(array $state, string $block): array {
        $open = array_pop($state['open']);
        if ($open === null || $open['block'] !== $block) {
            $state['valid'] = false;
            return $state;
        }
        $after = count($state['program']) + 1;
        $next = $block === 'repeat' ? $open['split'] : $after;
        $state['program'][] = ['end', $open['id'], $next];
        $state['program'][$open['split']][1] = $after;
        return $state;
    }

    /**
     * The program with the author's text added, when there is any.
     *
     * @param array $program
     * @param string $text
     * @return array
     */
    private static function with_literal(array $program, string $text): array {
        if ($text !== '') {
            $program[] = ['lit', $text];
        }
        return $program;
    }
}
