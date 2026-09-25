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

namespace local_coursegen\local\preview\lesson;

use block_contents;

/**
 * The lesson menu, built the way mod_lesson builds it.
 *
 * Copied from mod/lesson/locallib.php (function lesson_menu_block_contents(),
 * Moodle 4.5). The skip link, the wrapper and the two classes are the
 * original's; the links go to the preview instead of to view.php, and the
 * page being read is given rather than read off the request.
 *
 * @package    local_coursegen
 * @copyright  2009 Sam Hemelryk, 2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lesson_menu {
    /**
     * The block, or null when the lesson does not show one.
     *
     * @param lesson $lesson
     * @param int $currentpageid The page being read.
     * @return block_contents|null
     */
    public static function block_contents(lesson $lesson, int $currentpageid): ?block_contents {
        if (!$lesson->displayleft) {
            return null;
        }

        $pages = $lesson->load_all_pages();
        $pageid = self::first_page_id($pages);

        if (!$pageid || !$pages) {
            return null;
        }

        global $OUTPUT;
        $items = [];
        while ($pageid != 0) {
            $page = $pages[$pageid];

            // Only process branch tables with display turned on.
            if ($page->displayinmenublock && $page->display) {
                $url = '';
                $selected = $page->id == $currentpageid;
                if (!$selected) {
                    $url = $lesson->page_url((int) $page->id)->out();
                }
                $items[] = [
                    'selected' => $selected,
                    'title' => format_string($page->title, true),
                    'url' => $url,
                ];
            }
            $pageid = $page->nextpageid;
        }
        $content = $OUTPUT->render_from_template('local_coursegen/preview_lesson_menu', [
            'skiplabel' => get_string('skip', 'lesson'),
            'pages' => $items,
        ]);

        $bc = new block_contents();
        $bc->title = get_string('lessonmenu', 'lesson');
        $bc->attributes['class'] = 'menu block';
        $bc->content = $content;

        return $bc;
    }

    /**
     * The id of the lesson's first page: the one no other page's nextpageid
     * points to.
     *
     * @param lesson_page[] $pages
     * @return int
     */
    private static function first_page_id(array $pages): int {
        foreach ($pages as $page) {
            if ((int) $page->prevpageid === 0) {
                return $page->id;
            }
        }
        return 0;
    }
}
