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
}
