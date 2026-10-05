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

use local_coursegen\local\preview\lesson\lesson;
use local_coursegen\local\preview\lesson\lesson_menu;
use moodle_url;

/**
 * A lesson, drawn by mod_lesson's own code run against the payload.
 *
 * Nothing here decides what a lesson looks like. The classes under lesson/ are
 * mod_lesson's own view code, reading the rows the activity's own tree carries
 * instead of the database, and this only hands them what they need: the rows,
 * the page being read and where links go. The pages the AI wrote are already
 * in those rows, each in the row it came from, so none is looked for.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lesson_preview extends preview_base {
    /** @var lesson|null The lesson, once built. */
    protected ?lesson $lesson = null;

    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'lesson';
    }

    /**
     * The lesson, built from the activity's own tree.
     *
     * @return lesson|null Null when the tree holds no lesson row.
     */
    protected function lesson(): ?lesson {
        if ($this->lesson !== null) {
            return $this->lesson;
        }
        $store = $this->store();
        $row = $this->instance();
        if ($row === null) {
            return null;
        }

        $structure = $this->parameters['structure'] ?? [];
        $contextid = null;
        if (isset($structure['contextid'])) {
            $contextid = (int) $structure['contextid'];
        }

        $this->lesson = new lesson(
            $row,
            $store,
            $this->cm(),
            fn(int $pageid): moodle_url => $this->lesson_page_url($pageid),
            fn(): moodle_url => $this->lesson_exit_url(),
            $contextid
        );
        return $this->lesson;
    }

    /**
     * Where a lesson page is opened in the preview.
     *
     * @param int $pageid
     * @return moodle_url
     */
    protected function lesson_page_url(int $pageid): moodle_url {
        $index = $this->index_of($pageid);
        return $this->page_url($index);
    }

    /**
     * Where leaving the lesson goes.
     *
     * @return moodle_url
     */
    protected function lesson_exit_url(): moodle_url {
        $sessionid = $this->here->get_param('sessionid');
        return new moodle_url('/local/coursegen/course_preview.php', ['sessionid' => $sessionid]);
    }

    /**
     * Where a page sits in the order the lesson is walked.
     *
     * @param int $pageid
     * @return int
     */
    protected function index_of(int $pageid): int {
        $allpages = $this->lesson->load_all_pages();
        $pageids = array_keys($allpages);
        $position = array_search($pageid, $pageids, false);
        if ($position === false) {
            return 0;
        }
        return (int) $position;
    }

    /**
     * The page being read, or null when the lesson has none.
     *
     * @return lesson\lesson_page|null
     */
    protected function current_page() {
        $lesson = $this->lesson();
        if ($lesson === null) {
            return null;
        }
        $allpages = $lesson->load_all_pages();
        $pages = array_values($allpages);
        if (!$pages) {
            return null;
        }
        $lastindex = count($pages) - 1;
        $upperbound = min($this->page, $lastindex);
        $at = max(0, $upperbound);
        return $pages[$at];
    }

    /**
     * The page being read, as mod_lesson draws it.
     *
     * @return string
     */
    public function render(): string {
        global $PAGE, $OUTPUT;

        $page = $this->current_page();
        if ($page === null) {
            return $this->nothing_yet();
        }
        // mod_lesson's own renderer: its heading, box and button are what the
        // real page is drawn with.
        $renderer = $PAGE->get_renderer('mod_lesson');
        $out = '';
        if ($this->lesson->displayleft) {
            $anchortitle = get_string('anchortitle', 'lesson');
            $templatecontext = [
                'name' => 'maincontent',
                'title' => $anchortitle,
            ];
            $out .= $OUTPUT->render_from_template('local_coursegen/preview_anchor', $templatecontext);
        }
        return $out . $page->display($renderer, false);
    }

    /**
     * A lesson's page carries its own heading inside the content.
     *
     * @return string
     */
    public function header_description(): string {
        return '';
    }

    /**
     * The lesson menu, when the lesson is set to show one.
     *
     * @return \block_contents[]
     */
    public function side_blocks(): array {
        $page = $this->current_page();
        if ($page === null) {
            return [];
        }
        $block = lesson_menu::block_contents($this->lesson, (int) $page->id);
        if ($block === null) {
            return [];
        }
        return [$block];
    }

    /**
     * This module reads its own page at the narrower width (mod/lesson/view.php).
     *
     * @return bool
     */
    public function limited_width(): bool {
        return true;
    }
}
