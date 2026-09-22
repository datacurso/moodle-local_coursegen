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
    use book_chapters;
    use book_toc;
    use book_navigation;

    /** mod/book/locallib.php. */
    const BOOK_NUM_NONE = '0';
    /** mod/book/locallib.php. */
    const BOOK_NUM_NUMBERS = '1';
    /** mod/book/locallib.php. */
    const BOOK_NUM_BULLETS = '2';
    /** mod/book/locallib.php. */
    const BOOK_NUM_INDENTED = '3';

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
        $out = $OUTPUT->render_from_template('local_coursegen/preview_container', [
            'id' => 'mod_book-chaptersnavigation',
            'content' => $renderedmenu,
        ]);

        $boxclasses = 'generalbox book_content';
        if ($chapter->hidden) {
            $boxclasses .= ' dimmed_text';
        }
        $out .= $OUTPUT->box(self::chapter_content($book, $chapters, $chapter, $context), $boxclasses, 'mod_book-chapter');

        if (\core_tag_tag::is_enabled('mod_book', 'book_chapters')) {
            // The chapter's tags travel in the structure as chaptertags; a
            // preview of a chapter that carries none shows none, which is
            // what tag_list() prints for an empty list.
            $out .= $OUTPUT->tag_list(self::chapter_tags($chapter), null, 'book-tags');
        }
        return $out;
    }

    /**
     * The chapter's own titles and text, inside chapter_page()'s box.
     *
     * @param stdClass $book
     * @param array $chapters
     * @param stdClass $chapter
     * @param context $context
     * @return string
     */
    protected static function chapter_content($book, $chapters, $chapter, context $context): string {
        global $OUTPUT;
        $out = '';

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
