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
 * mod/book/classes/output/main_action_menu.php, with its next/previous
 * chapter links handed in. Kept apart from view.php only because together
 * they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait book_navigation {
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
        $next = self::next_chapter($chapters, $chapter, $viewhidden);
        $previous = self::previous_chapter($chapters, $chapter, $viewhidden);

        $data = [];
        if ($next) {
            $nexturl = $urls((int) $next->id);
            $data['next'] = [
                'title' => get_string('navnext', 'mod_book'),
                'url' => $nexturl->out(false),
            ];
        }
        if ($previous) {
            $prevurl = $urls((int) $previous->id);
            $data['previous'] = [
                'title' => get_string('navprev', 'mod_book'),
                'url' => $prevurl->out(false),
            ];
        }
        return $data;
    }

    /**
     * The next chapter after the current one that the reader may see.
     *
     * @param array $chapters
     * @param stdClass $chapter
     * @param bool $viewhidden
     * @return stdClass|null
     */
    private static function next_chapter($chapters, stdClass $chapter, bool $viewhidden): ?stdClass {
        $nextpageid = $chapter->pagenum + 1;
        if ($nextpageid > count($chapters)) {
            return null;
        }
        $found = self::visible_chapter_at($chapters, $nextpageid, $viewhidden);
        while ($found === null) {
            // Stop once the last chapter has been tried.
            if ($nextpageid === count($chapters)) {
                return null;
            }
            $nextpageid++;
            $found = self::visible_chapter_at($chapters, $nextpageid, $viewhidden);
        }
        return $found;
    }

    /**
     * The chapter before the current one that the reader may see.
     *
     * @param array $chapters
     * @param stdClass $chapter
     * @param bool $viewhidden
     * @return stdClass|null
     */
    private static function previous_chapter($chapters, stdClass $chapter, bool $viewhidden): ?stdClass {
        $prevpageid = $chapter->pagenum - 1;
        if ($prevpageid < 1) {
            return null;
        }
        $found = self::visible_chapter_at($chapters, $prevpageid, $viewhidden);
        while ($found === null) {
            // Stop once the first chapter has been tried.
            if ($prevpageid === 1) {
                return null;
            }
            $prevpageid--;
            $found = self::visible_chapter_at($chapters, $prevpageid, $viewhidden);
        }
        return $found;
    }

    /**
     * The chapter at one page number, if the reader is allowed to see it.
     *
     * @param array $chapters
     * @param int $pagenum
     * @param bool $viewhidden
     * @return stdClass|null
     */
    private static function visible_chapter_at($chapters, int $pagenum, bool $viewhidden): ?stdClass {
        foreach ($chapters as $candidate) {
            // Also make sure that the chapter is not hidden or the user can view hidden chapters before returning
            // the chapter object.
            if (($candidate->pagenum == $pagenum) && (!$candidate->hidden || $viewhidden)) {
                return $candidate;
            }
        }
        return null;
    }
}
