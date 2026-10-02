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

/**
 * Finds the mould's page row each drafted lesson page belongs to.
 *
 * The rows are put in the order a student walks the lesson, so a title that
 * repeats takes its rows in that order (see drafted_row_matcher).
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lesson_page_matcher {
    /**
     * The drafted pages keyed by the id of the row each one belongs to.
     *
     * @param \stdClass[] $rows The mould's lesson page rows.
     * @param array $pages The drafted pages, each with a title and optionally the row id.
     * @return array Row id => drafted page; pages without a row are left out.
     */
    public static function match(array $rows, array $pages): array {
        $walked = self::in_walk_order($rows);
        return drafted_row_matcher::match_ordered($walked, $pages);
    }

    /**
     * The rows in the order a student walks them; a row whose chain is broken follows the rest.
     *
     * @param \stdClass[] $rows
     * @return \stdClass[]
     */
    private static function in_walk_order(array $rows): array {
        $ordered = [];
        $lastid = 0;
        $next = self::following($rows, $lastid, []);
        while ($next !== null) {
            $ordered[(int) $next->id] = $next;
            $lastid = (int) $next->id;
            $next = self::following($rows, $lastid, $ordered);
        }
        $ordered = self::with_orphans($ordered, $rows);
        return array_values($ordered);
    }

    /**
     * The walked rows followed by the rows the chain never reached.
     *
     * @param \stdClass[] $ordered Walked rows keyed by id.
     * @param \stdClass[] $rows Every row.
     * @return \stdClass[]
     */
    private static function with_orphans(array $ordered, array $rows): array {
        foreach ($rows as $row) {
            $ordered[(int) $row->id] ??= $row;
        }
        return $ordered;
    }

    /**
     * The row whose previous page is the given one and that is not walked yet.
     *
     * @param \stdClass[] $rows
     * @param int $previousid
     * @param array $walked Rows already walked, keyed by id.
     * @return \stdClass|null
     */
    private static function following(array $rows, int $previousid, array $walked): ?\stdClass {
        foreach ($rows as $row) {
            $isnext = (int) ($row->prevpageid ?? 0) === $previousid;
            if ($isnext && !isset($walked[(int) $row->id])) {
                return $row;
            }
        }
        return null;
    }
}
