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

use block_contents;
use context;
use html_writer;

/**
 * The book's table of contents, as a fake block and as the tree
 * book_get_toc() draws. Kept apart from view.php only because together they
 * crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait book_toc {
    /**
     * mod/book/locallib.php book_add_fake_block(), returning the block.
     *
     * @param array $chapters
     * @param stdClass $chapter
     * @param stdClass $book
     * @param context $context
     * @param callable $urls chapter id => moodle_url
     * @return block_contents
     */
    public static function book_fake_block($chapters, $chapter, $book, context $context, callable $urls): block_contents {
        $toc = self::book_get_toc($chapters, $chapter, $book, $context, $urls);

        $bc = new block_contents();
        $bc->title = get_string('toc', 'mod_book');
        $bc->attributes['class'] = 'block block_book_toc';
        $bc->content = $toc;
        return $bc;
    }

    /**
     * The book_toc_* class one of book's numbering constants draws, mapped
     * rather than switched on.
     *
     * @param mixed $numbering One of self::BOOK_NUM_*.
     * @return string|null Null for a numbering the map does not describe.
     */
    protected static function numbering_class($numbering): ?string {
        $classes = [
            self::BOOK_NUM_NONE => 'book_toc_none',
            self::BOOK_NUM_NUMBERS => 'book_toc_numbered',
            self::BOOK_NUM_BULLETS => 'book_toc_bullets',
            self::BOOK_NUM_INDENTED => 'book_toc_indented',
        ];
        if (!array_key_exists($numbering, $classes)) {
            return null;
        }
        return $classes[$numbering];
    }

    /**
     * mod/book/locallib.php book_get_toc(), the branch shown when not editing.
     *
     * @param array $chapters
     * @param stdClass $chapter
     * @param stdClass $book
     * @param context $context
     * @param callable $urls chapter id => moodle_url
     * @return string
     */
    public static function book_get_toc($chapters, $chapter, $book, context $context, callable $urls) {
        $toc = '';
        $nch = 0;   // Chapter number
        $ns = 0;    // Subchapter number
        $first = 1;

        $viewhidden = has_capability('mod/book:viewhiddenchapters', $context);

        $numberingclass = self::numbering_class($book->numbering);
        if ($numberingclass !== null) {
            $toc .= html_writer::start_tag('div', array('class' => 'book_toc ' . $numberingclass . ' clearfix'));
        }

        // Editing off. Normal students, teachers view.
        $toc .= html_writer::start_tag('ul');
        foreach ($chapters as $ch) {
            $title = trim(format_string($ch->title, true, array('context' => $context)));
            $titleunescaped = trim(format_string($ch->title, true, array('context' => $context, 'escape' => false)));
            if (!$ch->hidden || ($ch->hidden && $viewhidden)) {
                if (!$ch->subchapter) {
                    $nch++;
                    $ns = 0;

                    if ($first) {
                        $toc .= html_writer::start_tag('li');
                    } else {
                        $toc .= html_writer::end_tag('ul');
                        $toc .= html_writer::end_tag('li');
                        $toc .= html_writer::start_tag('li');
                    }

                    if ($book->numbering == self::BOOK_NUM_NUMBERS) {
                          $title = "$nch. $title";
                    }
                } else {
                    $ns++;

                    if ($first) {
                        $toc .= html_writer::start_tag('li');
                        $toc .= html_writer::start_tag('ul');
                        $toc .= html_writer::start_tag('li');
                    } else {
                        $toc .= html_writer::start_tag('li');
                    }

                    if ($book->numbering == self::BOOK_NUM_NUMBERS) {
                          $title = "$nch.$ns. $title";
                    }
                }

                $cssclass = ($ch->hidden && $viewhidden) ? 'dimmed_text' : '';

                if ($ch->id == $chapter->id) {
                    $toc .= html_writer::tag('strong', $title, array('class' => $cssclass));
                } else {
                    $toc .= html_writer::link($urls((int) $ch->id), $title, array('title' => s($titleunescaped), 'class' => $cssclass));
                }

                if (!$ch->subchapter) {
                    $toc .= html_writer::start_tag('ul');
                } else {
                    $toc .= html_writer::end_tag('li');
                }

                $first = 0;
            }
        }

        $toc .= html_writer::end_tag('ul');
        $toc .= html_writer::end_tag('li');
        $toc .= html_writer::end_tag('ul');

        $toc .= html_writer::end_tag('div');

        $toc = str_replace('<ul></ul>', '', $toc); // Cleanup of invalid structures.

        return $toc;
    }
}
