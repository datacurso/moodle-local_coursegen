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
 * A planned wiki is laid out the way the plugin will create it: a first page
 * that links to every page the plan names, and the pages themselves, each as
 * the first version of itself.
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
     * The plan's pages replace the mould's, the way wiki_settings writes them.
     *
     * @param json_store $store
     */
    protected function overlay(json_store $store): void {
        $rows = $store->get_records('wiki');
        if (!$rows) {
            return;
        }
        $wiki = reset($rows);
        if (!empty($this->parameters['firstpagetitle'])) {
            $firstpagetitle = (string) $this->parameters['firstpagetitle'];
            $store->set('wiki', $wiki->id, 'firstpagetitle', $firstpagetitle);
            $wiki->firstpagetitle = $firstpagetitle;
        }
        $intro = $this->parameters['introeditor'] ?? null;
        if (is_array($intro)) {
            $intro = $intro['text'] ?? null;
        }
        if (is_string($intro) && trim($intro) !== '') {
            $store->set('wiki', $wiki->id, 'intro', $intro);
        }

        $planned = self::planned_pages($this->parameters, $wiki);
        if (!$planned) {
            return;
        }

        $subwiki = $store->get_record('wiki_subwikis', ['wikiid' => $wiki->id, 'groupid' => 0, 'userid' => 0]);
        if (!$subwiki) {
            $subwiki = (object) ['id' => 1, 'wikiid' => $wiki->id, 'groupid' => 0, 'userid' => 0];
            $store->add('wiki_subwikis', $subwiki);
        }
        self::clear_existing_pages($store, $subwiki->id);

        // wiki_settings::get_first_page(): the first page links to every other.
        $format = $wiki->defaultformat;
        if (!$format) {
            $format = 'html';
        }
        $first = self::first_page_content($planned, $format);
        $pages = [(string) $wiki->firstpagetitle => $first] + $planned;
        self::write_pages($store, $subwiki->id, $pages, $format);
    }

    /**
     * The plan's pages, keyed by title, with the wiki's own first page left
     * out: the way wiki_settings writes the pages a run intends.
     *
     * @param array $parameters
     * @param stdClass $wiki
     * @return array Title => content.
     */
    private static function planned_pages(array $parameters, $wiki): array {
        $planned = [];
        $pages = $parameters['mod_settings']['pages'] ?? [];
        foreach ($pages as $page) {
            $rawtitle = $page['title'] ?? '';
            $rawtitle = (string) $rawtitle;
            $title = trim($rawtitle);
            if ($title === '' || $title === (string) $wiki->firstpagetitle) {
                continue;
            }
            $content = $page['newcontent_editor'] ?? $page['content'] ?? $page['description'] ?? '';
            if (is_array($content)) {
                $content = $content['text'] ?? '';
            }
            $planned[$title] = (string) $content;
        }
        return $planned;
    }

    /**
     * Empties a subwiki of its pages and their versions, before the plan's
     * own pages are written.
     *
     * @param json_store $store
     * @param int $subwikiid
     */
    private static function clear_existing_pages(json_store $store, int $subwikiid): void {
        $oldpages = $store->get_records('wiki_pages', ['subwikiid' => $subwikiid]);
        foreach ($oldpages as $old) {
            $store->delete_records('wiki_versions', ['pageid' => $old->id]);
        }
        $store->delete_records('wiki_pages', ['subwikiid' => $subwikiid]);
    }

    /**
     * wiki_settings::get_first_page(): the first page's own content, a link
     * to every planned page.
     *
     * @param array $planned Title => content.
     * @param string $format
     * @return string
     */
    private static function first_page_content(array $planned, string $format): string {
        $first = '';
        $titles = array_keys($planned);
        foreach ($titles as $title) {
            if ($format === 'html') {
                $first .= "<p>[[{$title}]]</p>\n";
            } else {
                $first .= "[[{$title}]]\n\n";
            }
        }
        return $first;
    }

    /**
     * Writes every page and its first version.
     *
     * @param json_store $store
     * @param int $subwikiid
     * @param array $pages Title => content.
     * @param string $format
     */
    private static function write_pages(json_store $store, int $subwikiid, array $pages, string $format): void {
        $now = time();
        $id = 1;
        foreach ($pages as $title => $content) {
            $store->add('wiki_pages', [
                'id' => $id, 'subwikiid' => $subwikiid, 'title' => $title, 'cachedcontent' => '',
                'timecreated' => $now, 'timemodified' => $now, 'timerendered' => 0, 'userid' => 0,
                'pageviews' => 0, 'readonly' => 0,
            ]);
            $store->add('wiki_versions', [
                'id' => $id, 'pageid' => $id, 'content' => $content, 'contentformat' => $format,
                'version' => 1, 'timecreated' => $now, 'userid' => 0,
            ]);
            $id++;
        }
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
        $this->view = new view(
            $wiki,
            $this->cm(),
            $this->context(),
            $this->store(),
            fn(int $index, array $extra): moodle_url => $this->url_to(['page' => $index] + $extra)
        );
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
            return $pages[max(0, min($this->page, count($pages) - 1))];
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
            return $OUTPUT->notification(get_string('nocontent', 'wiki'), 'info', false);
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
        $data = [
            'action' => $this->url_to(),
            'hiddenfields' => $hiddenfields,
            'inputname' => 'searchstring',
            'query' => '',
            'searchstring' => get_string('searchwikis', 'wiki'),
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
