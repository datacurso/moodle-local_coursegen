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

use local_coursegen\local\preview\book\view;
use moodle_url;
use stdClass;

/**
 * A book, drawn by mod_book's own view code run against the payload.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class book_preview extends ported_preview {
    /** @var array|null The chapters as book_preload_chapters() prepares them. */
    protected ?array $chapters = null;

    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'book';
    }

    /**
     * A draft's chapters replace the mould's, matched by id.
     *
     * A plan names each piece by the id its module gave it; an answer that
     * arrives without ids is matched by title, the way the generator that
     * wrote it matched the mould's chapters.
     *
     * @param json_store $store
     */
    protected function overlay(json_store $store): void {
        $idbytitle = [];
        foreach ($store->get_records('book_chapters') as $row) {
            $idbytitle[trim((string) ($row->title ?? ''))] ??= $row->id;
        }
        foreach (($this->parameters['mod_settings']['chapters'] ?? []) as $chapter) {
            $id = $chapter['id'] ?? ($idbytitle[trim((string) ($chapter['title'] ?? ''))] ?? null);
            if ($id === null) {
                continue;
            }
            if (isset($chapter['title'])) {
                $store->set('book_chapters', $id, 'title', (string) $chapter['title']);
            }
            $content = $chapter['content_editor'] ?? ($chapter['content'] ?? null);
            if (is_array($content)) {
                $content = $content['text'] ?? null;
            }
            if (is_string($content)) {
                $store->set('book_chapters', $id, 'content', $content);
            }
        }
    }

    /**
     * The chapters, prepared the way mod_book prepares them.
     *
     * @return array
     */
    protected function chapters(): array {
        if ($this->chapters !== null) {
            return $this->chapters;
        }
        $book = $this->instance();
        $store = $this->store();
        $this->chapters = ($book === null || $store === null) ? [] : view::book_preload_chapters($book, $store);
        return $this->chapters;
    }

    /**
     * The chapter being read: the one asked for, else the first a reader sees.
     *
     * mod/book/view.php goes to the first chapter when none is given, skipping
     * hidden ones unless the reader may see them.
     *
     * @return stdClass|null
     */
    protected function current_chapter(): ?stdClass {
        $chapters = array_values($this->chapters());
        if (!$chapters) {
            return null;
        }
        if ($this->here->get_param('page') !== null) {
            $at = max(0, min($this->page, count($chapters) - 1));
            return $chapters[$at];
        }
        $viewhidden = has_capability('mod/book:viewhiddenchapters', $this->context());
        foreach ($chapters as $ch) {
            if ($ch->hidden && $viewhidden) {
                return $ch;
            }
            if (!$ch->hidden) {
                return $ch;
            }
        }
        return $chapters[0];
    }

    /**
     * Where a chapter sits in the book's order.
     *
     * @param int $chapterid
     * @return moodle_url
     */
    protected function chapter_url(int $chapterid): moodle_url {
        $position = array_search($chapterid, array_keys($this->chapters()), false);
        return $this->page_url($position === false ? 0 : (int) $position);
    }

    /**
     * The chapter, as mod/book/view.php draws it.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        $book = $this->instance();
        $chapter = $this->current_chapter();
        if ($book === null) {
            return $this->nothing_yet();
        }
        if ($chapter === null) {
            // mod/book/view.php, when the book has no chapters.
            return $OUTPUT->notification(get_string('nocontent', 'mod_book'), 'info', false);
        }
        return view::chapter_page($book, $this->chapters(), $chapter, $this->context(), fn(int $id) => $this->chapter_url($id));
    }

    /**
     * The table of contents, as the block mod_book adds beside every chapter.
     *
     * @return \block_contents[]
     */
    public function side_blocks(): array {
        $book = $this->instance();
        $chapter = $this->current_chapter();
        if ($book === null || $chapter === null) {
            return [];
        }
        return [view::book_fake_block($this->chapters(), $chapter, $book, $this->context(), fn(int $id) => $this->chapter_url($id))];
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
