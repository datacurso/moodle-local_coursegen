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

use local_coursegen\local\preview\wiki\view;
use moodle_url;
use stdClass;

/**
 * A wiki, drawn by mod_wiki's own view code run against the payload.
 *
 * The pages of the wiki, and the first version of each, are the rows of its
 * own tree: the pages the AI wrote are already in them, so none is built here.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wiki_preview extends preview_base {
    /** @var view|null */
    protected ?view $view = null;

    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'wiki';
    }

    /**
     * The module's view, built once.
     *
     * @return view|null
     */
    protected function view(): ?view {
        if ($this->view !== null) {
            return $this->view;
        }
        $wiki = $this->instance();
        if ($wiki === null) {
            return null;
        }
        $cm = $this->cm();
        $context = $this->context();
        $store = $this->store();
        $urls = fn(int $index, array $extra): moodle_url => $this->url_to(['page' => $index] + $extra);
        $this->view = new view($wiki, $cm, $context, $store, $urls);
        return $this->view;
    }

    /**
     * The page being read: the one asked for, else the wiki's first page.
     *
     * @return stdClass|null
     */
    protected function current_page(): ?stdClass {
        $view = $this->view();
        $pages = $view->pages();
        if (!$pages) {
            return null;
        }
        if ($this->here->get_param('page') !== null) {
            $lastindex = count($pages) - 1;
            $boundedindex = min($this->page, $lastindex);
            $boundedindex = max(0, $boundedindex);
            return $pages[$boundedindex];
        }
        return $view->wiki_get_first_page() ?? $pages[0];
    }

    /**
     * The wiki page, as mod/wiki/view.php draws it.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        $view = $this->view();
        if ($view === null) {
            return $this->nothing_yet();
        }
        $page = $this->current_page();
        if ($page === null) {
            // view.php sends the reader to create the first page; a preview
            // has nothing to create, so it says what the wiki still lacks.
            $nocontent = get_string('nocontent', 'wiki');
            return $OUTPUT->notification($nocontent, 'info', false);
        }
        return $view->page($page);
    }

    /**
     * mod/wiki/lib.php wiki_search_form(): the search box page_wiki puts in the header.
     *
     * The search would run over the template's real wiki, so the form leads
     * back to the preview.
     *
     * @return string
     */
    public function header_button(): string {
        global $OUTPUT;
        $view = $this->view();
        if ($view === null) {
            return '';
        }
        $cm = $this->cm();
        $subwiki = $view->subwiki();
        $hiddenfields = [
            (object) ['type' => 'hidden', 'name' => 'courseid', 'value' => $cm->course],
            (object) ['type' => 'hidden', 'name' => 'cmid', 'value' => $cm->id],
            (object) ['type' => 'hidden', 'name' => 'searchwikicontent', 'value' => 1],
        ];
        if (!empty($subwiki->id)) {
            $hiddenfields[] = (object) ['type' => 'hidden', 'name' => 'subwikiid', 'value' => $subwiki->id];
        }
        $action = $this->url_to();
        $searchstring = get_string('searchwikis', 'wiki');
        $data = [
            'action' => $action,
            'hiddenfields' => $hiddenfields,
            'inputname' => 'searchstring',
            'query' => '',
            'searchstring' => $searchstring,
            'extraclasses' => 'mt-2'
        ];
        return $OUTPUT->render_from_template('core/search_input', $data);
    }

    /**
     * This module reads its own page at the narrower width (mod/wiki/pagelib.php).
     *
     * @return bool
     */
    public function limited_width(): bool {
        return true;
    }
}
