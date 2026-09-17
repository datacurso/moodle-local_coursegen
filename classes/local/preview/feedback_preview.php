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
 * A feedback activity's questions, drawn the way mod_feedback draws its form.
 *
 * The activity is the questionnaire, so the questionnaire is what this lays
 * out: every question in position order with the control its type uses,
 * disabled, because there is nothing to answer yet.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_preview extends activity_preview {
    /**
     * Every question, in the order it is asked.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        $questions = $this->items('questions');
        if (!$questions) {
            return $this->nothing_yet();
        }

        $out = '';
        foreach ($questions as $question) {
            $out .= \html_writer::div(
                \html_writer::tag('label', format_string((string) ($question['name'] ?? '')), ['class' => 'fw-bold'])
                    . $this->control((array) $question),
                'feedback_item_box mb-3'
            );
        }
        return $OUTPUT->box($out, 'generalbox');
    }

    /**
     * The control one question is answered with.
     *
     * @param array $question
     * @return string
     */
    private function control(array $question): string {
        $type = (string) ($question['typ'] ?? $question['type'] ?? 'textfield');
        $presentation = (string) ($question['presentation'] ?? '');

        if ($type === 'label') {
            return $this->content($presentation);
        }

        if (in_array($type, ['multichoice', 'multichoicerated'], true)) {
            // A multiple-choice question carries its options in its
            // presentation, one per line, after the leading display-mode flag
            // mod_feedback stores there.
            $lines = preg_split('/\r\n|\r|\n/', $presentation) ?: [];
            $items = '';
            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line === '' || preg_match('/^[a-z]>+$/i', $line)) {
                    continue;
                }
                $items .= \html_writer::div(
                    \html_writer::empty_tag('input', ['type' => 'radio', 'disabled' => 'disabled', 'class' => 'me-2'])
                        . format_string($line),
                    'd-flex align-items-center'
                );
            }
            return $items;
        }

        if ($type === 'textarea') {
            return \html_writer::tag('textarea', '', [
                'class' => 'form-control',
                'rows' => 3,
                'disabled' => 'disabled',
            ]);
        }

        return \html_writer::empty_tag('input', [
            'type' => 'text',
            'class' => 'form-control',
            'disabled' => 'disabled',
        ]);
    }

    /**
     * This module reads its own page at the narrower width (mod/feedback/view.php).
     *
     * @return bool
     */
    public function limited_width(): bool {
        return true;
    }
}
