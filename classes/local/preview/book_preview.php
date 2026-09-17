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
 * A book's chapters, drawn the way mod_book draws them.
 *
 * mod_book opens on its first chapter with the whole table of contents beside
 * it, so the contents is a side block here and every chapter is laid out in
 * order: a preview that showed one chapter of twelve would be answering a
 * different question than the one being asked.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class book_preview extends activity_preview {
    /**
     * Every chapter, in order, subchapters indented as the book indents them.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        $chapters = $this->items('chapters');
        if (!$chapters) {
            return $this->nothing_yet();
        }

        $out = '';
        foreach ($chapters as $chapter) {
            $level = !empty($chapter['subchapter']) ? 4 : 3;
            $out .= \html_writer::start_div('book_content' . (!empty($chapter['subchapter']) ? ' ms-4' : ''));
            $out .= $OUTPUT->heading(format_string((string) ($chapter['title'] ?? '')), $level);
            $out .= $this->content($this->field($chapter, 'content'));
            $out .= \html_writer::end_div();
        }
        return $out;
    }

    /**
     * The table of contents, which is how a book is navigated.
     *
     * @return \block_contents[]
     */
    public function side_blocks(): array {
        $items = '';
        foreach ($this->items('chapters') as $chapter) {
            $title = trim((string) ($chapter['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $items .= \html_writer::tag(
                'li',
                format_string($title),
                !empty($chapter['subchapter']) ? ['class' => 'ms-3'] : []
            );
        }
        if ($items === '') {
            return [];
        }

        $block = new \block_contents();
        $block->title = get_string('toc', 'mod_book');
        $block->attributes['class'] = 'block_book_toc block';
        $block->content = \html_writer::tag('ul', $items, ['class' => 'book_toc_none']);
        return [$block];
    }

    /**
     * This module reads its own page at the narrower width (mod/book/view.php).
     *
     * @return bool
     */
    public function limited_width(): bool {
        return true;
    }
}
