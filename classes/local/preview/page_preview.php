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
 * A page's content, drawn the way mod_page draws it.
 *
 * mod_page shows its description first when the activity is set to show it,
 * then the page content in a box, which is the order reproduced here.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_preview extends activity_preview {
    /**
     * The page's description and content.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        $out = '';
        $intro = trim($this->text('introeditor'));
        if ($intro !== '' && !empty($this->parameters['printintro'])) {
            $out .= $OUTPUT->box($this->content($intro), 'mod_introbox');
        }
        $out .= $OUTPUT->box($this->content($this->text('page')), 'generalbox center clearfix');
        return $out;
    }
}
