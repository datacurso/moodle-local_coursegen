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
 * A wiki's first page, drawn the way mod_wiki draws it.
 *
 * A wiki opens on the page its settings name, so that is the one shown; the
 * rest are listed beside it the way the wiki's own page index lists them.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wiki_preview extends activity_preview {
    /**
     * Every page the wiki starts with, in order.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        $pages = $this->items('pages');
        if (!$pages) {
            return $this->nothing_yet();
        }

        $out = '';
        foreach ($pages as $page) {
            $out .= $OUTPUT->heading(format_string((string) ($page['title'] ?? '')), 3);
            $out .= $OUTPUT->box($this->content($this->field($page, 'newcontent')), 'generalbox wiki_content');
        }
        return $out;
    }

    /**
     * This module reads its own page at the narrower width (mod/wiki/view.php).
     *
     * @return bool
     */
    public function limited_width(): bool {
        return true;
    }
}
