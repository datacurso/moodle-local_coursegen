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

/**
 * A choice's options, drawn the way mod_choice draws them.
 *
 * The activity is its question and the options under it, so both are shown,
 * with the options disabled: there is nothing to answer yet.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class choice_preview extends activity_preview {
    /**
     * The options, as the choice offers them.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        $options = $this->options();
        if (!$options) {
            return $this->nothing_yet();
        }

        $items = '';
        foreach ($options as $option) {
            $items .= \html_writer::div(
                \html_writer::empty_tag('input', ['type' => 'radio', 'disabled' => 'disabled', 'class' => 'me-2'])
                    . \html_writer::tag('label', format_string($option)),
                'option d-flex align-items-center'
            );
        }

        return $OUTPUT->box(
            \html_writer::div($items, 'choices')
                . \html_writer::tag('button', get_string('savemychoice', 'choice'), [
                    'type' => 'button',
                    'class' => 'btn btn-primary mt-3',
                    'disabled' => 'disabled',
                ]),
            'generalbox choicecontainer'
        );
    }

    /**
     * The option texts, whichever shape the answer put them in.
     *
     * @return string[]
     */
    private function options(): array {
        $options = $this->items('options');
        if (!$options) {
            $options = [];
            foreach ($this->parameters as $key => $value) {
                if (preg_match('/^option\[?\d+\]?$/', (string) $key) && trim((string) $value) !== '') {
                    $options[] = (string) $value;
                }
            }
            return $options;
        }

        $texts = [];
        foreach ($options as $option) {
            $text = is_array($option) ? (string) ($option['text'] ?? $option['option'] ?? '') : (string) $option;
            if (trim($text) !== '') {
                $texts[] = $text;
            }
        }
        return $texts;
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
