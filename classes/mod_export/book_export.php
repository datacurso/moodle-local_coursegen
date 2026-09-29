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

namespace local_coursegen\mod_export;

/**
 * Class book_export
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class book_export extends base_export {
    /**
     * A Book's raw introduction, its three settings and its chapters.
     *
     * mod_book has no parent column: a subchapter belongs to the nearest
     * preceding chapter, so the reading order IS the hierarchy and must be
     * preserved exactly.
     *
     * @return array
     */
    public function parameters(): array {
        global $DB;

        $book = $DB->get_record('book', ['id' => $this->cm->instance]);
        if (!$book) {
            return $this->minimal_parameters();
        }

        $parameters = [
            'name' => $this->cm->name,
            'section' => (int) $this->cm->sectionnum,
            'intro' => $book->intro ?? '',
            'numbering' => (int) ($book->numbering ?? 0),
            // Moodle's own form has no control for this one, but the generated
            // book must still read the way the mold does.
            'navstyle' => (int) ($book->navstyle ?? 1),
            'customtitles' => (int) ($book->customtitles ?? 0),
        ];

        $chapters = $this->chapters((int) $book->id);
        if ($chapters) {
            $parameters['mod_settings'] = ['chapters' => $chapters];
        }

        return $parameters;
    }

    /**
     * Every chapter of one book, in reading order.
     *
     * @param int $bookid
     * @return array
     */
    private function chapters(int $bookid): array {
        global $DB;

        $records = $DB->get_records(
            'book_chapters',
            ['bookid' => $bookid],
            'pagenum ASC',
            'id, title, content, subchapter'
        );

        $chapters = [];
        foreach ($records as $record) {
            $chapters[] = [
                'title' => $record->title,
                'content' => $record->content ?? '',
                'subchapter' => (int) $record->subchapter,
            ];
        }
        return $chapters;
    }
}
