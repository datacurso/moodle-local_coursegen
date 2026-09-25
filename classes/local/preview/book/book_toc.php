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
use stdClass;

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
        global $OUTPUT;

        $tree = self::book_toc_tree($chapters, $chapter, $book, $context, $urls);
        if (empty($tree)) {
            return '';
        }

        $numberingclass = self::numbering_class($book->numbering);
        if ($numberingclass === null) {
            $numberingclass = '';
        }
        return $OUTPUT->render_from_template('local_coursegen/preview_book_toc', [
            'numberingclass' => $numberingclass,
            'chapters' => $tree,
        ]);
    }

    /**
     * The chapters nested one level into their subchapters, the way
     * book_get_toc() draws them, with hidden chapters (and the subchapters
     * that inherit their hidden state) left out entirely.
     *
     * A book's first chapter can never be a subchapter
     * (book_preload_chapters() forces it), and a subchapter's hidden state
     * always matches its parent's, so a subchapter is never orphaned by this
     * filter.
     *
     * @param array $chapters
     * @param stdClass $chapter
     * @param stdClass $book
     * @param context $context
     * @param callable $urls chapter id => moodle_url
     * @return array
     */
    protected static function book_toc_tree($chapters, $chapter, $book, context $context, callable $urls): array {
        $viewhidden = has_capability('mod/book:viewhiddenchapters', $context);
        $nch = 0;
        $ns = 0;
        $tree = [];
        $current = -1;

        foreach ($chapters as $ch) {
            if ($ch->hidden && !$viewhidden) {
                continue;
            }
            $node = self::book_toc_node($ch, $chapter, $book, $context, $urls, $viewhidden, $nch, $ns);
            if (!$ch->subchapter) {
                $tree[] = $node;
                $current++;
            } else {
                $tree[$current]['subchapters'][] = $node;
            }
        }

        return self::book_toc_mark_has_subchapters($tree);
    }

    /**
     * Mark every chapter node with whether it carries subchapters.
     *
     * @param array $tree
     * @return array
     */
    private static function book_toc_mark_has_subchapters(array $tree): array {
        foreach ($tree as $index => $node) {
            $tree[$index]['hassubchapters'] = !empty($node['subchapters']);
        }
        return $tree;
    }

    /**
     * One chapter's own row in the tree, numbered as book_get_toc() numbers it.
     *
     * @param stdClass $ch
     * @param stdClass $chapter
     * @param stdClass $book
     * @param context $context
     * @param callable $urls
     * @param bool $viewhidden
     * @param int $nch Chapter number, incremented in place for a chapter.
     * @param int $ns Subchapter number, incremented in place for a subchapter.
     * @return array
     */
    protected static function book_toc_node(
        stdClass $ch,
        stdClass $chapter,
        stdClass $book,
        context $context,
        callable $urls,
        bool $viewhidden,
        int &$nch,
        int &$ns
    ): array {
        $formatted = format_string($ch->title, true, array('context' => $context));
        $title = trim($formatted);
        $formattedunescaped = format_string($ch->title, true, array('context' => $context, 'escape' => false));
        $titleunescaped = trim($formattedunescaped);

        if (!$ch->subchapter) {
            $nch++;
            $ns = 0;
            if ($book->numbering == self::BOOK_NUM_NUMBERS) {
                $title = "$nch. $title";
            }
        } else {
            $ns++;
            if ($book->numbering == self::BOOK_NUM_NUMBERS) {
                $title = "$nch.$ns. $title";
            }
        }

        $cssclass = '';
        if ($ch->hidden && $viewhidden) {
            $cssclass = 'dimmed_text';
        }

        $url = $urls((int) $ch->id);
        $urlstring = $url->out(false);

        return [
            'id' => (int) $ch->id,
            'title' => $title,
            'titleunescaped' => $titleunescaped,
            'cssclass' => $cssclass,
            'iscurrent' => ($ch->id == $chapter->id),
            'url' => $urlstring,
            'subchapters' => [],
        ];
    }
}
