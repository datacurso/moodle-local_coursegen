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
class book_preview extends preview_base {
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
        $rows = $store->get_records('book_chapters');
        $idbytitle = self::chapter_ids_by_title($rows);
        $drafts = $this->parameters['mod_settings']['chapters'] ?? [];
        foreach ($drafts as $chapter) {
            $this->overlay_chapter($store, $chapter, $idbytitle);
        }
    }

    /**
     * A mould's own chapter id, keyed by its title, for a draft that arrives
     * without ids.
     *
     * @param \stdClass[] $rows
     * @return array
     */
    private static function chapter_ids_by_title(array $rows): array {
        $idbytitle = [];
        foreach ($rows as $row) {
            $title = $row->title ?? '';
            $title = (string) $title;
            $title = trim($title);
            if (!isset($idbytitle[$title])) {
                $idbytitle[$title] = $row->id;
            }
        }
        return $idbytitle;
    }

    /**
     * Lay one drafted chapter over the mould's row it fills.
     *
     * @param json_store $store
     * @param array $chapter
     * @param array $idbytitle
     */
    private function overlay_chapter(json_store $store, array $chapter, array $idbytitle): void {
        $id = $chapter['id'] ?? null;
        if ($id === null) {
            $title = $chapter['title'] ?? '';
            $title = (string) $title;
            $title = trim($title);
            $id = $idbytitle[$title] ?? null;
        }
        if ($id === null) {
            return;
        }
        if (isset($chapter['title'])) {
            $store->set('book_chapters', $id, 'title', (string) $chapter['title']);
        }
        $content = $chapter['content_editor'] ?? null;
        if ($content === null) {
            $content = $chapter['content'] ?? null;
        }
        if (is_array($content)) {
            $content = $content['text'] ?? null;
        }
        if (is_string($content)) {
            $store->set('book_chapters', $id, 'content', $content);
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
        $this->chapters = [];
        if ($book !== null && $store !== null) {
            $this->chapters = view::book_preload_chapters($book, $store);
        }
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
        $chapterlist = $this->chapters();
        $chapters = array_values($chapterlist);
        if (!$chapters) {
            return null;
        }
        if ($this->here->get_param('page') !== null) {
            $upper = count($chapters) - 1;
            $clamped = min($this->page, $upper);
            $at = max(0, $clamped);
            return $chapters[$at];
        }
        $context = $this->context();
        $viewhidden = has_capability('mod/book:viewhiddenchapters', $context);
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
        $chapters = $this->chapters();
        $chapterids = array_keys($chapters);
        $position = array_search($chapterid, $chapterids, false);
        if ($position === false) {
            return $this->page_url(0);
        }
        return $this->page_url((int) $position);
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
            $message = get_string('nocontent', 'mod_book');
            return $OUTPUT->notification($message, 'info', false);
        }
        $chapters = $this->chapters();
        $context = $this->context();
        $urls = fn(int $id) => $this->chapter_url($id);
        return view::chapter_page($book, $chapters, $chapter, $context, $urls);
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
        $chapters = $this->chapters();
        $context = $this->context();
        $urls = fn(int $id) => $this->chapter_url($id);
        $block = view::book_fake_block($chapters, $chapter, $book, $context, $urls);
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
