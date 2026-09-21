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
 * An assignment, drawn the way mod_assign draws its submission page.
 *
 * What a participant reads before submitting is the description and, when the
 * assignment has them, the submission instructions. A rubric is part of that
 * too: it is what their work will be graded against, and it exists before any
 * submission does.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_preview extends activity_preview {
    /**
     * The submission instructions, then the rubric.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        $out = '';
        $instructions = trim($this->text('activityeditor'));
        if ($instructions !== '') {
            $out .= $OUTPUT->heading(get_string('activityeditor', 'assign'), 4);
            $out .= $OUTPUT->box($this->content($instructions), 'generalbox activity');
        }

        $rubric = $this->items('rubric');
        if ($rubric) {
            $out .= $OUTPUT->heading(get_string('gradingmethod', 'grading'), 4);
            $rows = '';
            foreach ($rubric as $criterion) {
                $levels = '';
                foreach ((array) ($criterion['levels'] ?? []) as $level) {
                    $definition = is_array($level)
                        ? (string) ($level['definition'] ?? '')
                        : (string) $level;
                    $score = is_array($level) ? (string) ($level['score'] ?? '') : '';
                    $levels .= \html_writer::tag(
                        'td',
                        \html_writer::div(s($definition), 'definition')
                            . ($score === '' ? '' : \html_writer::div(s($score), 'score')),
                        ['class' => 'level']
                    );
                }
                $rows .= \html_writer::tag(
                    'tr',
                    \html_writer::tag(
                        'td',
                        $this->content($this->field((array) $criterion, 'description')),
                        ['class' => 'description']
                    ) . $levels,
                    ['class' => 'criterion']
                );
            }
            $out .= \html_writer::tag('table', $rows, ['class' => 'gradingform_rubric table table-bordered']);
        }

        return $out === '' ? $this->nothing_yet() : $out;
    }
}
