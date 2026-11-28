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
 * Reads a text the AI service returned as one mold of the template filled in.
 *
 * The text matches when it is the mold's own text, byte for byte and in the mold's order, with something in place
 * of each slot, each repeat run any number of times and each optional block run once or not at all. The match splits
 * the text into the author's parts and what the AI wrote for each slot. It runs the program of template_mold_parser
 * with an explicit stack of choices, so a long page never deepens the PHP call stack, and gives up after a fixed
 * number of steps.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_mold_matcher {
    /** @var int The steps a match may take before it gives up. */
    public const MAX_STEPS = 200000;

    /** @var array The program being run. */
    private array $program;

    /** @var string The text being read. */
    private string $text;

    /** @var int The length of the text. */
    private int $length;

    /** @var int The instruction being run. */
    private int $pc = 0;

    /** @var int Where the text is being read. */
    private int $position = 0;

    /** @var array The parts read so far: `['lit', text]` or `['gap', text, context]`. */
    private array $parts = [];

    /** @var array Where the current run of each block (repeat or optional) started. */
    private array $runs = [];

    /** @var array The choices to come back to: a block that could be skipped or end, a slot that could be longer. */
    private array $choices = [];

    /**
     * Prepare a match.
     *
     * @param array $program A program of template_mold_parser.
     * @param string $text The text the AI service returned.
     */
    private function __construct(array $program, string $text) {
        $this->program = $program;
        $this->text = $text;
        $this->length = strlen($text);
    }

    /**
     * The parts of a text read as the mold of a program, or null when it does not match.
     *
     * @param array $program
     * @param string $text
     * @param int $maxsteps
     * @return array|null The parts: `['lit', text]` or `['gap', text, context]`.
     */
    public static function match(array $program, string $text, int $maxsteps = self::MAX_STEPS): ?array {
        $matcher = new self($program, $text);
        return $matcher->run($maxsteps);
    }

    /**
     * Run the program until the text is read whole, every choice failed, or the steps ran out.
     *
     * @param int $maxsteps
     * @return array|null
     */
    private function run(int $maxsteps): ?array {
        $steps = 0;
        $moving = true;
        while ($steps < $maxsteps) {
            $steps++;
            if (!$moving) {
                $moving = $this->back_to_last_choice();
                if (!$moving && !$this->choices_left()) {
                    return null;
                }
                continue;
            }
            if ($this->pc >= count($this->program)) {
                if ($this->position === $this->length) {
                    return $this->parts;
                }
                $moving = false;
                continue;
            }
            $moving = $this->step();
        }
        return null;
    }

    /**
     * Run the current instruction.
     *
     * @return bool Whether the match goes on; false means coming back to the last choice.
     */
    private function step(): bool {
        $instruction = $this->program[$this->pc];
        $kind = $instruction[0];
        if ($kind === 'lit') {
            return $this->read_literal($instruction[1]);
        }
        if ($kind === 'gap') {
            return $this->read_gap($instruction[1], $this->position);
        }
        if ($kind === 'split') {
            $this->remember('split', $this->pc + 1);
            $this->pc = $instruction[1];
            return true;
        }
        if ($kind === 'start') {
            $this->runs[$instruction[1]] = $this->position;
            $this->pc++;
            return true;
        }
        return $this->end_run($instruction[1], $instruction[2]);
    }

    /**
     * Read the author's text where the text is being read.
     *
     * @param string $literal
     * @return bool
     */
    private function read_literal(string $literal): bool {
        $size = strlen($literal);
        if ($size > $this->length - $this->position) {
            return false;
        }
        if (substr_compare($this->text, $literal, $this->position, $size) !== 0) {
            return false;
        }
        $this->parts[] = ['lit', $literal];
        $this->position += $size;
        $this->pc++;
        return true;
    }

    /**
     * Read what the AI wrote for a slot, as short as it can be from a given end on.
     *
     * @param string $context
     * @param int $from The least end the slot may have.
     * @return bool
     */
    private function read_gap(string $context, int $from): bool {
        $start = $this->position;
        $end = $this->next_end($context, $start, $from);
        if ($end === null) {
            return false;
        }
        $this->remember('gap', $end + 1);
        $value = substr($this->text, $start, $end - $start);
        $this->parts[] = ['gap', $value, $context];
        $this->position = $end;
        $this->pc++;
        return true;
    }

    /**
     * The next end a slot may have: where the author's text after it starts, with an acceptable value before it.
     *
     * @param string $context
     * @param int $start Where the slot starts.
     * @param int $from The least end.
     * @return int|null
     */
    private function next_end(string $context, int $start, int $from): ?int {
        $next = $this->program[$this->pc + 1] ?? null;
        $end = $this->candidate_end($next, $from);
        while ($end !== null) {
            $value = substr($this->text, $start, $end - $start);
            if (template_mold_context::accepts($context, $value)) {
                return $end;
            }
            $end = $this->candidate_end($next, $end + 1);
        }
        return null;
    }

    /**
     * The first place, from an offset on, where a slot followed by an instruction may end.
     *
     * Before the author's text, a slot can only end where that text appears; before anything else it can end anywhere.
     *
     * @param array|null $next The instruction after the slot.
     * @param int $from
     * @return int|null
     */
    private function candidate_end(?array $next, int $from): ?int {
        if ($from > $this->length) {
            return null;
        }
        if ($next === null || $next[0] !== 'lit') {
            return $from;
        }
        $found = strpos($this->text, $next[1], $from);
        if ($found === false) {
            return null;
        }
        return $found;
    }

    /**
     * End one run of a block: an empty run is refused, any other goes on where the program says: back to the split of
     * a repeat, or past the end of an optional block.
     *
     * @param string $id
     * @param int $next
     * @return bool
     */
    private function end_run(string $id, int $next): bool {
        if ($this->position === $this->runs[$id]) {
            return false;
        }
        $this->pc = $next;
        return true;
    }

    /**
     * Remember a choice to come back to, with the state it starts from.
     *
     * @param string $kind 'split' (run the block, or a repeat once more) or 'gap' (make the slot longer).
     * @param int $resume The instruction a split resumes at, or the least end of a longer slot.
     */
    private function remember(string $kind, int $resume): void {
        $this->choices[] = [
            'kind' => $kind,
            'resume' => $resume,
            'pc' => $this->pc,
            'position' => $this->position,
            'parts' => count($this->parts),
            'runs' => $this->runs,
        ];
    }

    /**
     * Come back to the last choice and take its other way.
     *
     * @return bool Whether the match goes on from there.
     */
    private function back_to_last_choice(): bool {
        $choice = array_pop($this->choices);
        if ($choice === null) {
            return false;
        }
        $this->pc = $choice['pc'];
        $this->position = $choice['position'];
        $this->parts = array_slice($this->parts, 0, $choice['parts']);
        $this->runs = $choice['runs'];
        if ($choice['kind'] === 'split') {
            $this->pc = $choice['resume'];
            return true;
        }
        $instruction = $this->program[$this->pc];
        return $this->read_gap($instruction[1], $choice['resume']);
    }

    /**
     * Whether any choice is left to come back to.
     *
     * @return bool
     */
    private function choices_left(): bool {
        return $this->choices !== [];
    }
}
