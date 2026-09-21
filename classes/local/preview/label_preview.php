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
 * A label's own markup, drawn as the course page draws it.
 *
 * A label has no view page of its own: what it is, is the HTML that sits
 * directly in the section. It is usually the section's banner, so it carries
 * its own layout and styles and must be shown exactly as written, with no box
 * around it adding a frame the real thing does not have.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class label_preview extends activity_preview {
    /**
     * The label's markup.
     *
     * @return string
     */
    public function render(): string {
        return $this->content($this->text('introeditor'));
    }
}
