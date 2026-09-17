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
use local_coursegen\local\preview\json_store;
use moodle_url;
use stdClass;

/**
 * mod_book's view code, ported to run against the payload.
 *
 * Copied from mod/book/locallib.php, mod/book/view.php,
 * mod/book/classes/output/main_action_menu.php and mod/book/classes/helper.php
 * (Moodle 4.5). Method names are the functions they came from. What changed:
 * the chapters are read from a json_store instead of the database and never
 * written back; the context is handed in; links to the book's own pages are
 * built by a callable handed in; the editing branch of the table of contents,
 * which a preview never shows, is left out; the page's header and footer are
 * drawn by the preview page.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view {
    /** mod/book/locallib.php. */
    const BOOK_NUM_NONE = '0';
    /** mod/book/locallib.php. */
    const BOOK_NUM_NUMBERS = '1';
    /** mod/book/locallib.php. */
    const BOOK_NUM_BULLETS = '2';
    /** mod/book/locallib.php. */
    const BOOK_NUM_INDENTED = '3';

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

        switch ($book->numbering) {
            case self::BOOK_NUM_NONE:
                $toc .= html_writer::start_tag('div', array('class' => 'book_toc book_toc_none clearfix'));
                break;
            case self::BOOK_NUM_NUMBERS:
                $toc .= html_writer::start_tag('div', array('class' => 'book_toc book_toc_numbered clearfix'));
                break;
            case self::BOOK_NUM_BULLETS:
                $toc .= html_writer::start_tag('div', array('class' => 'book_toc book_toc_bullets clearfix'));
                break;
            case self::BOOK_NUM_INDENTED:
                $toc .= html_writer::start_tag('div', array('class' => 'book_toc book_toc_indented clearfix'));
                break;
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

    /**
     * mod/book/classes/output/main_action_menu.php, exported with links handed in.
     *
     * @param array $chapters
     * @param stdClass $chapter
     * @param context $context
     * @param callable $urls chapter id => moodle_url
     * @return array
     */
    public static function main_action_menu_data($chapters, $chapter, context $context, callable $urls): array {
        $viewhidden = has_capability('mod/book:viewhiddenchapters', $context);
        $getchapter = static function (int $id) use ($chapters, $viewhidden): ?stdClass {
            foreach ($chapters as $candidate) {
                // Also make sure that the chapter is not hidden or the user can view hidden chapters before returning
                // the chapter object.
                if (($candidate->pagenum == $id) && (!$candidate->hidden || $viewhidden)) {
                    return $candidate;
                }
            }
            return null;
        };

        $next = null;
        $nextpageid = $chapter->pagenum + 1;
        // Early return if the current chapter is also the last chapter.
        if ($nextpageid <= count($chapters)) {
            while ((!$next = $getchapter($nextpageid))) {
                // Break the loop if this is the last chapter.
                if ($nextpageid === count($chapters)) {
                    break;
                }
                $nextpageid++;
            }
        }

        $previous = null;
        $prevpageid = $chapter->pagenum - 1;
        // Early return if the current chapter is also the first chapter.
        if ($prevpageid >= 1) {
            while ((!$previous = $getchapter($prevpageid))) {
                // Break the loop if this is the first chapter.
                if ($prevpageid === 1) {
                    break;
                }
                $prevpageid--;
            }
        }

        $data = [];
        if ($next) {
            $data['next'] = [
                'title' => get_string('navnext', 'mod_book'),
                'url' => $urls((int) $next->id)->out(false),
            ];
        }
        if ($previous) {
            $data['previous'] = [
                'title' => get_string('navprev', 'mod_book'),
                'url' => $urls((int) $previous->id)->out(false),
            ];
        }
        return $data;
    }

    /**
     * mod/book/view.php from the chapter navigation to the end of the chapter.
     *
     * @param stdClass $book
     * @param array $chapters
     * @param stdClass $chapter
     * @param context $context
     * @param callable $urls
     * @return string
     */
    public static function chapter_page($book, $chapters, $chapter, context $context, callable $urls): string {
        global $OUTPUT;

        $renderedmenu = $OUTPUT->render_from_template(
            'mod_book/main_action_menu',
            self::main_action_menu_data($chapters, $chapter, $context, $urls)
        );
        $out = html_writer::div($renderedmenu, '', ['id' => 'mod_book-chaptersnavigation']);

        // The chapter itself.
        $hidden = $chapter->hidden ? ' dimmed_text' : null;
        $out .= $OUTPUT->box_start('generalbox book_content' . $hidden, 'mod_book-chapter');

        if (!$book->customtitles) {
            if (!$chapter->subchapter) {
                $currtitle = self::book_get_chapter_title($chapter->id, $chapters, $book, $context);
                $out .= $OUTPUT->heading($currtitle, 3);
            } else {
                $currtitle = self::book_get_chapter_title($chapters[$chapter->id]->parent, $chapters, $book, $context);
                $currsubtitle = self::book_get_chapter_title($chapter->id, $chapters, $book, $context);
                $out .= $OUTPUT->heading($currtitle, 3);
                $out .= $OUTPUT->heading($currsubtitle, 4);
            }
        }
        $chaptertext = file_rewrite_pluginfile_urls($chapter->content, 'pluginfile.php', $context->id, 'mod_book',
            'chapter', $chapter->id);
        $out .= format_text($chaptertext, $chapter->contentformat, ['noclean' => true, 'overflowdiv' => true,
            'context' => $context]);

        $out .= $OUTPUT->box_end();

        if (\core_tag_tag::is_enabled('mod_book', 'book_chapters')) {
            // The chapter's tags travel in the structure as chaptertags; a
            // preview of a chapter that carries none shows none, which is
            // what tag_list() prints for an empty list.
            $out .= $OUTPUT->tag_list(self::chapter_tags($chapter), null, 'book-tags');
        }
        return $out;
    }

    /**
     * The tags a chapter carries in the structure, as core_tag_tag instances.
     *
     * @param stdClass $chapter
     * @return array
     */
    protected static function chapter_tags(stdClass $chapter): array {
        $tags = [];
        foreach ((array) ($chapter->tags ?? []) as $tag) {
            $tags[] = \core_tag_tag::from_record((object) [
                'id' => $tag->id ?? 0,
                'name' => $tag->rawname ?? '',
                'rawname' => $tag->rawname ?? '',
                'isstandard' => 0,
                'tagcollid' => \core_tag_area::get_collection('mod_book', 'book_chapters'),
                'taginstanceid' => 0,
                'taginstancecontextid' => 0,
                'itemid' => $chapter->id,
                'ordering' => 0,
                'flag' => 0,
            ]);
        }
        return $tags;
    }
}
