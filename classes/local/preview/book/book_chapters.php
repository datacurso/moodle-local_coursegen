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

namespace local_coursegen\local\preview\book;

use context;
use local_coursegen\local\preview\json_store;
use stdClass;

/**
 * Reads a book's chapters from the store and numbers them the way
 * book_preload_chapters() does. Kept apart from view.php only because
 * together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait book_chapters {
    /**
     * mod/book/locallib.php book_preload_chapters(), reading from the store.
     *
     * The original repairs a chapter's order in the database when it finds it
     * out of step; a preview reads and never writes, so the repaired values
     * are used and not saved.
     *
     * @param stdClass $book
     * @param json_store $store
     * @return array
     */
    public static function book_preload_chapters($book, json_store $store) {
        $chapters = $store->get_records('book_chapters', array('bookid' => $book->id), 'pagenum');
        if (!$chapters) {
            return array();
        }

        $prev = null;
        $prevsub = null;

        $first = true;
        $hidesub = true;
        $parent = null;
        $pagenum = 0; // chapter sort
        $i = 0;       // main chapter num
        $j = 0;       // subchapter num
        foreach ($chapters as $id => $ch) {
            $pagenum++;
            $ch->pagenum = $pagenum;
            if ($first) {
                // book can not start with a subchapter
                $ch->subchapter = 0;
                $first = false;
            }
            if (!$ch->subchapter) {
                if ($ch->hidden) {
                    if ($book->numbering == self::BOOK_NUM_NUMBERS) {
                        $ch->number = 'x';
                    } else {
                        $ch->number = null;
                    }
                } else {
                    $i++;
                    $ch->number = $i;
                }
                $j = 0;
                $prevsub = null;
                $hidesub = $ch->hidden;
                $parent = $ch->id;
                $ch->parent = null;
                $ch->subchapters = array();
            } else {
                $ch->parent = $parent;
                $ch->subchapters = null;
                $chapters[$parent]->subchapters[$ch->id] = $ch->id;
                if ($hidesub) {
                    // all subchapters in hidden chapter must be hidden too
                    $ch->hidden = 1;
                }
                if ($ch->hidden) {
                    if ($book->numbering == self::BOOK_NUM_NUMBERS) {
                        $ch->number = 'x';
                    } else {
                        $ch->number = null;
                    }
                } else {
                    $j++;
                    $ch->number = $j;
                }
            }

            $chapters[$id] = $ch;
        }

        return $chapters;
    }

    /**
     * mod/book/locallib.php book_get_chapter_title().
     *
     * @param int $chid
     * @param array $chapters
     * @param stdClass $book
     * @param context $context
     * @return string
     */
    public static function book_get_chapter_title($chid, $chapters, $book, $context) {
        $ch = $chapters[$chid];
        $title = trim(format_string($ch->title, true, array('context' => $context)));
        $numbers = array();
        if ($book->numbering == self::BOOK_NUM_NUMBERS) {
            if ($ch->parent and $chapters[$ch->parent]->number) {
                $numbers[] = $chapters[$ch->parent]->number;
            }
            if ($ch->number) {
                $numbers[] = $ch->number;
            }
        }

        if ($numbers) {
            $title = implode('.', $numbers) . '. ' . $title;
        }

        return $title;
    }
}
