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
 * mod_lesson's own view code, ported to read the rows the payload carries
 * instead of the database, and this only hands them what they need: the rows,
 * the page being read, where links go, and what the plan intends to write
 * laid over the mould page by page.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lesson_preview extends activity_preview {
    /** @var lesson|null The lesson, once built. */
    protected ?lesson $lesson = null;

    /** @var array The activity as the payload describes it. */
    protected array $source;

    /**
     * Constructor.
     *
     * @param array $parameters The draft: pages with id, title and content_html.
     * @param array $source The mould (or the kept activity) with its structure.
     */
    public function __construct(array $parameters, array $source = []) {
        parent::__construct($parameters, $source);
        $this->source = $source;
    }

    /**
     * Map each stored page's trimmed title to its database id.
     *
     * @param json_store $store
     * @return array
     */
    private function page_ids_by_title(json_store $store): array {
        $idbytitle = [];
        foreach ($store->get_records('lesson_pages') as $row) {
            $idbytitle[trim((string) ($row->title ?? ''))] ??= $row->id;
        }
        return $idbytitle;
    }

    /**
     * The lesson, built from the payload with the draft laid over it.
     *
     * @return lesson|null Null when the payload holds no lesson row.
     */
    protected function lesson(): ?lesson {
        if ($this->lesson !== null) {
            return $this->lesson;
        }
        if (!$this->source) {
            return null;
        }

        $store = json_store::from_activity($this->source);
        // A draft names the page it fills by id. A finished answer does not,
        // because the generator that wrote it matched pages by title, so the
        // same match is made here for the pages that arrive without one.
        $idbytitle = $this->page_ids_by_title($store);
        foreach (($this->parameters['mod_settings']['pages'] ?? []) as $page) {
            $id = $page['id'] ?? ($idbytitle[trim((string) ($page['title'] ?? ''))] ?? null);
            if ($id === null) {
                continue;
            }
            if (isset($page['title'])) {
                $store->set('lesson_pages', $id, 'title', (string) $page['title']);
            }
            if (isset($page['content_html'])) {
                $store->set('lesson_pages', $id, 'contents', (string) $page['content_html']);
            }
        }

        $rows = $store->get_records('lesson');
        if (!$rows) {
            return null;
        }
        $row = reset($rows);

        $cm = (object) [
            'id' => (int) ($this->source['cmid'] ?? 0),
            'course' => (int) ($row->course ?? 0),
        ];
        $structure = ($this->source['parameters'] ?? [])['structure'] ?? [];
        $contextid = null;
        if (isset($structure['contextid'])) {
            $contextid = (int) $structure['contextid'];
        }

        $this->lesson = new lesson(
            $row,
            $store,
            $cm,
            fn(int $pageid): moodle_url => $this->page_url($this->index_of($pageid)),
            fn(): moodle_url => new moodle_url('/local/coursegen/course_preview.php', [
                'sessionid' => $this->here->get_param('sessionid'),
            ]),
            $contextid
        );
        return $this->lesson;
    }

    /**
     * Where a page sits in the order the lesson is walked.
     *
     * @param int $pageid
     * @return int
     */
    protected function index_of(int $pageid): int {
        $position = array_search($pageid, array_keys($this->lesson->load_all_pages()), false);
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
        $pages = array_values($lesson->load_all_pages());
        if (!$pages) {
            return null;
        }
        $at = max(0, min($this->page, count($pages) - 1));
        return $pages[$at];
    }

    /**
     * The page being read, as mod_lesson draws it.
     *
     * @return string
     */
    public function render(): string {
        global $PAGE;

        $page = $this->current_page();
        if ($page === null) {
            return $this->nothing_yet();
        }
        // mod_lesson's own renderer: its heading, box and button are what the
        // real page is drawn with.
        $renderer = $PAGE->get_renderer('mod_lesson');
        $out = '';
        if ($this->lesson->displayleft) {
            $out .= '<a name="maincontent" id="maincontent" title="' . get_string('anchortitle', 'lesson') . '"></a>';
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
