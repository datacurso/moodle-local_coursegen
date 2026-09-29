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

namespace local_coursegen\local\preview\workshop;

/**
 * The action buttons mod_workshop_renderer::view_page() opens with, kept
 * apart from view.php only because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait workshop_action_buttons {
    /**
     * Copied from mod_workshop_renderer::render_action_buttons().
     *
     * The one button any phase has is the reader's own submission; whether
     * they have one is theirs, not the template's, so it is looked for in
     * rows the payload never carries and offered where the workshop allows.
     *
     * @return string
     */
    protected function render_action_buttons(): string {
        global $OUTPUT;
        $output = '';
        $workshop = $this->workshop;

        // The one button any phase offers starts the reader's own submission.
        // Nobody may act on an activity that does not exist: it is not offered.
        return $output;
    }
}
