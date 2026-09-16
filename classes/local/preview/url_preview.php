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
 * A URL, drawn the way mod_url draws it.
 *
 * mod_url shows the description and then the address as a link. The address is
 * a value the template leaves to be filled in rather than text the AI writes,
 * so it is shown as it stands, whether that is the real destination or the
 * placeholder waiting for one.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url_preview extends activity_preview {
    /**
     * The link, under the description the header already carries.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        $url = trim((string) ($this->parameters['externalurl'] ?? ''));
        if ($url === '') {
            return $this->nothing_yet();
        }

        // Not clickable: a preview opens nothing, and the address may still be
        // the placeholder the template left to be filled in.
        return $OUTPUT->box(
            \html_writer::tag('span', s($url), ['class' => 'urlworkaround']),
            'generalbox urlbox'
        );
    }
}
