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

namespace local_coursegen\mod_settings;

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/filelib.php");

/**
 * Class book_settings
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class book_settings extends base_settings {
    /**
     * Add specific settings for book module.
     */
    public function add_settings() {
        foreach ($this->modsettings['chapters'] as $chapter) {
            $this->add_chapter($chapter);
        }
    }

    /**
     * Add chapter to book.
     *
     * @param array $chapter Chapter data.
     */
    protected function add_chapter($chapter) {
        global $DB;

        $cm = $this->cm;
        $context = \context_module::instance($cm->coursemodule);

        $book = $DB->get_record('book', ['id' => $cm->instance], '*', MUST_EXIST);

        $last = $DB->get_record_sql(
            "SELECT * FROM {book_chapters}
             WHERE bookid = ?
             ORDER BY pagenum DESC",
            [$book->id],
            IGNORE_MULTIPLE
        );

        $contenteditor = $chapter['content_editor'] ?? [];
        if (!is_array($contenteditor)) {
            $contenteditor = ['text' => (string)$contenteditor];
        }

        $contenteditor['text'] = (string)($contenteditor['text'] ?? '');
        $contenteditor['format'] = (int)($contenteditor['format'] ?? FORMAT_HTML);

        $contentitemid = (int)($contenteditor['itemid'] ?? 0);
        if ($contentitemid <= 0) {
            $contentitemid = file_get_unused_draft_itemid();
        }
        $contenteditor['itemid'] = $contentitemid;

        $pagenum = $last ? ($last->pagenum + 1) : 1;

        $data = new \stdClass();
        $data->bookid = $book->id;
        $data->pagenum = $pagenum;
        $data->subchapter = $chapter['subchapter'] ?? 0;
        $data->title = $chapter['title'];
        $data->hidden = 0;
        $data->timecreated = time();
        $data->timemodified = time();
        $data->importsrc = '';
        $data->content_editor = $contenteditor;
        $data->content = '';
        $data->contentformat = FORMAT_HTML;

        $data->id = $DB->insert_record('book_chapters', $data);
        $options = [
            'subdirs' => true,
            'maxfiles' => -1,
            'maxbytes' => 0,
            'context' => $context,
        ];

        $data = file_postupdate_standard_editor(
            $data,
            'content',
            $options,
            $context,
            'mod_book',
            'chapter',
            $data->id
        );

        $DB->update_record('book_chapters', $data);

        $book->revision++;
        $DB->update_record('book', $book);

        $chapterrec = $DB->get_record('book_chapters', ['id' => $data->id], '*', MUST_EXIST);
        \mod_book\event\chapter_created::create_from_chapter($book, $context, $chapterrec)->trigger();
    }
}
