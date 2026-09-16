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
 * A workshop, drawn the way mod_workshop draws its first phase.
 *
 * A workshop's page is whatever phase it is in, and it is delivered in its
 * first one: the submission instructions the participant reads, and the
 * criteria their work will be judged by, which is what the assessment form
 * will be built from.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class workshop_preview extends activity_preview {
    /**
     * The instructions, then the assessment criteria.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        $out = '';
        foreach (['instructauthors' => 'submissioninstructions',
                  'instructreviewers' => 'assessmentinstructions'] as $key => $stringid) {
            $text = trim($this->text($key));
            if ($text !== '') {
                $out .= $OUTPUT->heading(get_string($stringid, 'workshop'), 4);
                $out .= $OUTPUT->box($this->content($text), 'generalbox instructions');
            }
        }

        $criteria = $this->items('criteria');
        if ($criteria) {
            $out .= $OUTPUT->heading(get_string('assessmentform', 'workshop'), 4);
            $rows = '';
            foreach ($criteria as $index => $criterion) {
                $rows .= \html_writer::div(
                    \html_writer::tag(
                        'label',
                        get_string('dimensionnumber', 'workshopform_rubric', $index + 1),
                        ['class' => 'fw-bold d-block']
                    )
                        . $this->content($this->field((array) $criterion, 'description'))
                        . \html_writer::tag(
                            'small',
                            get_string('maxgrade', 'workshop') . ': ' . (int) ($criterion['max_points'] ?? 0),
                            ['class' => 'text-muted d-block']
                        ),
                    'mb-3'
                );
            }
            $out .= $OUTPUT->box($rows, 'generalbox assessment-form');
        }

        return $out === '' ? $this->nothing_yet() : $out;
    }

    /**
     * A workshop shows its description on its own page, not in the header.
     *
     * @return string
     */
    public function header_description(): string {
        return '';
    }
}
