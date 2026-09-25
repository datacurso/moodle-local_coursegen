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

namespace local_coursegen\local\preview;

use local_coursegen\local\preview\choice\view;

/**
 * A choice, drawn by mod_choice's own view code run against the payload.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class choice_preview extends ported_preview {
    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'choice';
    }

    /**
     * A draft's options replace the mould's.
     *
     * @param json_store $store
     */
    protected function overlay(json_store $store): void {
        $rows = $store->get_records('choice');
        $options = $this->drafted_options();
        if (!$rows || !$options) {
            return;
        }
        $choice = reset($rows);
        $store->delete_records('choice_options', ['choiceid' => $choice->id]);
        $now = time();
        $id = 1;
        foreach ($options as $text) {
            $store->add('choice_options', [
                'id' => $id++,
                'choiceid' => $choice->id,
                'text' => $text,
                'maxanswers' => 0,
                'timemodified' => $now,
            ]);
        }
    }

    /**
     * Option texts read from flat option[N] parameter keys, the shape a plan
     * without a structured `options` array arrives in.
     *
     * @return string[]
     */
    private function options_from_indexed_parameters(): array {
        $options = [];
        foreach ($this->parameters as $key => $value) {
            if (preg_match('/^option\[?\d+\]?$/', (string) $key) && trim((string) $value) !== '') {
                $options[] = (string) $value;
            }
        }
        return $options;
    }

    /**
     * One option's text, whichever shape it arrived in.
     *
     * @param mixed $option
     * @return string
     */
    private static function option_text($option): string {
        if (is_array($option)) {
            return (string) ($option['text'] ?? $option['option'] ?? '');
        }
        return (string) $option;
    }

    /**
     * Option texts read from a structured `options` array.
     *
     * @param array $options
     * @return string[]
     */
    private function options_from_structured_array(array $options): array {
        $texts = [];
        foreach ($options as $option) {
            $text = self::option_text($option);
            if (trim($text) !== '') {
                $texts[] = $text;
            }
        }
        return $texts;
    }

    /**
     * The option texts the plan intends, whichever shape the answer put them in.
     *
     * @return string[]
     */
    private function drafted_options(): array {
        $options = $this->parameters['options'] ?? null;
        if (!is_array($options)) {
            return $this->options_from_indexed_parameters();
        }
        return $this->options_from_structured_array($options);
    }

    /**
     * The choice page, as mod/choice/view.php draws it.
     *
     * @return string
     */
    public function render(): string {
        $choice = $this->instance();
        if ($choice === null) {
            return $this->nothing_yet();
        }
        $view = new view($choice, $this->cm(), $this->course(), $this->context(), $this->store(), $this->url_to());
        return $view->page();
    }

    /**
     * This module reads its own page at the narrower width (mod/choice/view.php).
     *
     * @return bool
     */
    public function limited_width(): bool {
        return true;
    }
}
