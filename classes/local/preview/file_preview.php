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
 * A file or folder resource, drawn the way its module draws it.
 *
 * Neither activity has content of its own beyond its description and the files
 * it carries, and those files do not exist until the activity does. So the
 * description is shown, and what will be there is named rather than linked:
 * there is nothing yet to download.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_preview extends activity_preview {
    /**
     * The documents the activity will hold.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        $names = [];
        foreach ($this->items('documents') as $document) {
            $name = is_array($document)
                ? (string) ($document['name'] ?? $document['filename'] ?? '')
                : (string) $document;
            if (trim($name) !== '') {
                $names[] = $name;
            }
        }

        $settings = $this->parameters['mod_settings'] ?? [];
        foreach (['file_name', 'filename'] as $key) {
            if (!empty($settings[$key])) {
                $names[] = (string) $settings[$key];
            }
        }

        if (!$names) {
            return $this->nothing_yet();
        }

        $items = '';
        foreach ($names as $name) {
            $items .= \html_writer::tag(
                'li',
                \html_writer::tag('span', format_string($name), ['class' => 'fp-filename']),
                ['class' => 'fp-file']
            );
        }
        return $OUTPUT->box(\html_writer::tag('ul', $items, ['class' => 'fp-content']), 'generalbox foldertree');
    }

    /**
     * This module reads its own page at the narrower width (mod/folder/view.php).
     *
     * @return bool
     */
    public function limited_width(): bool {
        return true;
    }
}
