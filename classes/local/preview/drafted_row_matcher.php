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
 * Finds the mould's row each drafted piece (a lesson page, a book chapter) belongs to.
 *
 * A finished answer does not name its pieces by id, it carries them in the order
 * the generator walked the mould, so a piece finds its row by title. Titles are
 * not unique inside an activity, so a title seen again takes the next row that has
 * it, in the order the rows are given, rather than the first one again. A title that
 * the mould wrote with a marker is written by the generator, so it matches no row;
 * such pieces take the rows no title reached, in order, when they are as many as
 * those rows.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class drafted_row_matcher {
    /**
     * The drafted pieces keyed by the id of the row each one belongs to.
     *
     * @param \stdClass[] $rows The mould's rows, in the order they are read.
     * @param array $drafts The drafted pieces, each with a title and optionally the row id.
     * @return array Row id => drafted piece; pieces without a row are left out.
     */
    public static function match_ordered(array $rows, array $drafts): array {
        $queues = self::ids_by_title($rows);
        $matched = [];
        $strays = [];
        foreach ($drafts as $draft) {
            $id = self::row_id_of($draft, $queues);
            if ($id === null) {
                if (trim((string) ($draft['title'] ?? '')) !== '') {
                    $strays[] = $draft;
                }
                continue;
            }
            $matched[$id] = $draft;
        }
        return self::with_strays($matched, $strays, $rows);
    }

    /**
     * The matched pieces plus the titled pieces no row matched, paired in order with the rows left over.
     *
     * Nothing is guessed unless the strays are exactly as many as the rows left.
     *
     * @param array $matched Row id => drafted piece.
     * @param array $strays Titled pieces that found no row.
     * @param \stdClass[] $rows The mould's rows, in the order they are read.
     * @return array Row id => drafted piece.
     */
    private static function with_strays(array $matched, array $strays, array $rows): array {
        $left = [];
        foreach ($rows as $row) {
            if (!isset($matched[(int) $row->id])) {
                $left[] = (int) $row->id;
            }
        }
        if (!$strays || count($strays) !== count($left)) {
            return $matched;
        }
        foreach ($strays as $position => $stray) {
            $matched[$left[$position]] = $stray;
        }
        return $matched;
    }

    /**
     * The row a page belongs to: the one it names, else the next unused one of its title.
     *
     * @param array $page
     * @param array $queues Trimmed title => ids still unused, taken from the front.
     * @return int|null
     */
    private static function row_id_of(array $page, array &$queues): ?int {
        if (isset($page['id'])) {
            return (int) $page['id'];
        }
        $title = trim((string) ($page['title'] ?? ''));
        if (empty($queues[$title])) {
            return null;
        }
        $id = array_shift($queues[$title]);
        return (int) $id;
    }

    /**
     * Row ids grouped by trimmed title, each group in the order given.
     *
     * @param \stdClass[] $rows
     * @return array
     */
    private static function ids_by_title(array $rows): array {
        $queues = [];
        foreach ($rows as $row) {
            $title = trim((string) ($row->title ?? ''));
            $queues[$title][] = $row->id;
        }
        return $queues;
    }
}
