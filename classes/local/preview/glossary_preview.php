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
 * A glossary's entries, drawn the way mod_glossary draws them.
 *
 * Each entry is its concept over its definition, which is the shape the
 * glossary's own listing uses whichever display format it is set to.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class glossary_preview extends activity_preview {
    /**
     * Every entry, in order.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        $entries = $this->items('entries');
        if (!$entries) {
            return $this->nothing_yet();
        }

        $out = '';
        foreach ($entries as $entry) {
            $concept = format_string((string) ($entry['concept'] ?? ''));
            $out .= $OUTPUT->box(
                \html_writer::tag('h4', $concept, ['class' => 'concept'])
                    . \html_writer::div($this->content($this->field($entry, 'definition')), 'entry'),
                'glossarypost generalbox'
            );
        }
        return $out;
    }
}
