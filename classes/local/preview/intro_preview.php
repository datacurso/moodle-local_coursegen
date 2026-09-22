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
 * An activity whose whole visible content is its description.
 *
 * Several module types put everything they have to say in the description and
 * then offer an action that needs the real thing to exist: a forum lists its
 * discussions, a file is downloaded, a URL is followed. None of that can happen
 * before the activity is created, and none of it is what a preview is for. What
 * IS worth seeing is the text, so that is what this draws, in the introduction
 * box those modules use for it.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class intro_preview extends activity_preview {
    /**
     * The activity's description.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        $intro = trim($this->text('introeditor'));
        if ($intro === '') {
            $intro = trim($this->text('intro'));
        }
        if ($intro === '') {
            return $OUTPUT->notification(
                get_string('courseai_preview_empty', 'local_coursegen'),
                \core\output\notification::NOTIFY_INFO
            );
        }
        return $OUTPUT->box($this->content($intro), 'mod_introbox');
    }
}
