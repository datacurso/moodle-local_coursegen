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
 * A database activity, drawn the way mod_data draws it.
 *
 * What the activity is, before anyone adds anything to it, is the fields it
 * asks for and the entries it ships with. Both are shown: the fields as the
 * form a participant fills in, and the example entries as the list they will
 * see.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class data_preview extends activity_preview {
    /**
     * The fields, then the entries that already exist.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        $fields = $this->items('fields');
        $entries = $this->items('example_entries');
        if (!$fields && !$entries) {
            return $this->nothing_yet();
        }

        $out = '';
        if ($fields) {
            $rows = '';
            foreach ($fields as $field) {
                $label = format_string((string) ($field['name'] ?? $field['field_name'] ?? ''));
                if (!empty($field['required'])) {
                    $label .= ' ' . \html_writer::tag('span', '*', ['class' => 'text-danger']);
                }
                $rows .= \html_writer::div(
                    \html_writer::tag('label', $label, ['class' => 'fw-bold d-block'])
                        . \html_writer::empty_tag('input', [
                            'type' => 'text',
                            'class' => 'form-control',
                            'disabled' => 'disabled',
                        ])
                        . \html_writer::tag('small', s((string) ($field['description'] ?? '')), ['class' => 'text-muted']),
                    'mb-3'
                );
            }
            $out .= $OUTPUT->box($rows, 'generalbox');
        }

        foreach ($entries as $entry) {
            $cells = '';
            foreach ((array) ($entry['values'] ?? []) as $key => $value) {
                $cells .= \html_writer::tag('dt', format_string((string) $key))
                    . \html_writer::tag('dd', $this->content((string) $value));
            }
            if ($cells !== '') {
                $out .= $OUTPUT->box(\html_writer::tag('dl', $cells), 'generalbox defaulttemplate');
            }
        }
        return $out;
    }
}
