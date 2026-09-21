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
 * A packaged activity: H5P, SCORM or an IMS content package.
 *
 * All three are a player around a package, and the package is a file that does
 * not exist until the activity does. What the AI writes for them is the text
 * inside it, which is what this shows, in the order the package is authored.
 *
 * The player itself is the one thing no preview can honestly show: it is a
 * runtime, and a runtime needs the file it runs.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package_preview extends activity_preview {
    /**
     * The texts the package will carry.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        $out = '';
        foreach (['pages', 'slides', 'items', 'contents'] as $key) {
            foreach ($this->items($key) as $item) {
                $item = (array) $item;
                $title = trim((string) ($item['title'] ?? ''));
                if ($title !== '') {
                    $out .= $OUTPUT->heading(format_string($title), 4);
                }
                $text = $this->field($item, 'content') ?: $this->field($item, 'text');
                if (trim($text) !== '') {
                    $out .= $OUTPUT->box($this->content($text), 'generalbox');
                }
            }
        }

        return $out === '' ? $this->nothing_yet() : $out;
    }
}
