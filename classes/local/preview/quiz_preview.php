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
 * A quiz's questions, drawn the way mod_quiz draws an attempt.
 *
 * A real attempt is one question per page with the navigation beside it, and
 * that navigation is the only thing a preview cannot honestly show, because
 * there is no attempt to navigate. Everything else is: each question in order,
 * its text, its options and what it is worth, laid out as the attempt lays
 * them out.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_preview extends activity_preview {
    /**
     * Every question, in order.
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
        foreach ($questions as $index => $question) {
            $number = $index + 1;
            $mark = (float) ($question['defaultmark'] ?? 1);

            $info = \html_writer::div(
                \html_writer::tag('h4', get_string('questionx', 'question', $number), ['class' => 'no'])
                    . \html_writer::div(
                        get_string('marks', 'quiz') . ': ' . format_float($mark, -1),
                        'grade'
                    ),
                'info'
            );

            $body = \html_writer::div(
                $this->content($this->field($question, 'questiontext')),
                'qtext'
            ) . $this->answers($question);

            $out .= \html_writer::div(
                $info . \html_writer::div(\html_writer::div($body, 'formulation'), 'content'),
                'que ' . s((string) ($question['qtype'] ?? 'multichoice'))
            );
        }
        return $out;
    }

    /**
     * One question's options, shown but never answerable.
     *
     * @param array $question
     * @return string
     */
    private function answers(array $question): string {
        $answers = $question['answers'] ?? $question['options'] ?? [];
        if (!is_array($answers) || !$answers) {
            return '';
        }

        $items = '';
        foreach ($answers as $answer) {
            $text = is_array($answer)
                ? $this->field($answer, 'answer') ?: (string) ($answer['text'] ?? '')
                : (string) $answer;
            if (trim($text) === '') {
                continue;
            }
            $items .= \html_writer::tag(
                'div',
                \html_writer::empty_tag('input', ['type' => 'radio', 'disabled' => 'disabled', 'class' => 'me-2'])
                    . \html_writer::tag('span', $this->content($text), ['class' => 'flex-fill']),
                ['class' => 'r0 d-flex']
            );
        }
        return $items === '' ? '' : \html_writer::div($items, 'ablock');
    }
}
