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

namespace local_coursegen\local\preview\wiki;

use context;
use local_coursegen\local\preview\json_store;
use moodle_url;
use stdClass;
use url_select;
use wiki_parser_proxy;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/wiki/locallib.php');
require_once($CFG->dirroot . '/mod/wiki/parser/parser.php');

/**
 * mod_wiki's view code, run here against the payload instead of the database.
 *
 * Copied from mod/wiki/view.php, pagelib.php (page_wiki_view), locallib.php
 * and classes/output/action_bar.php (Moodle 4.5), for a collaborative wiki
 * without groups, which is the wiki a template holds. Method names are the
 * functions they came from. What changed: the subwiki, its pages and their
 * versions are read from a json_store; a page is rendered from its latest
 * version by mod_wiki's own parser, with the links between pages resolved
 * against the payload so that they lead to the preview of the page they name
 * instead of to the template's real one; the cached rendering, page views
 * and link table are never written; and the action bar's pages keep their
 * place but lead back to the preview.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view {
    use wiki_content;

    /** @var self|null The view whose links the parser is resolving. */
    protected static ?self $parsing = null;

    /** @var json_store */
    protected json_store $store;
    /** @var stdClass */
    protected stdClass $wiki;
    /** @var stdClass */
    protected stdClass $cm;
    /** @var context */
    protected context $context;
    /** @var callable int page index, array extra params => moodle_url */
    protected $urls;
    /** @var stdClass[]|null The subwiki's pages in id order, once read. */
    protected ?array $pages = null;

    /**
     * Constructor.
     *
     * @param stdClass $wiki
     * @param stdClass $cm
     * @param context $context
     * @param json_store $store
     * @param callable $urls
     */
    public function __construct(stdClass $wiki, stdClass $cm, context $context, json_store $store, callable $urls) {
        $this->wiki = $wiki;
        $this->cm = $cm;
        $this->context = $context;
        $this->store = $store;
        $this->urls = $urls;
    }

    /**
     * mod/wiki/view.php: the subwiki of the whole class, for a collaborative wiki without groups.
     *
     * @return stdClass|null
     */
    public function subwiki(): ?stdClass {
        $subwiki = $this->store->get_record('wiki_subwikis', ['wikiid' => $this->wiki->id, 'groupid' => 0, 'userid' => 0]);
        if (!$subwiki) {
            $all = $this->store->get_records('wiki_subwikis', ['wikiid' => $this->wiki->id], 'id');
            $subwiki = $all ? reset($all) : false;
        }
        return $subwiki ?: null;
    }

    /**
     * The subwiki's pages, in the order they were made.
     *
     * @return stdClass[]
     */
    public function pages(): array {
        if ($this->pages !== null) {
            return $this->pages;
        }
        $subwiki = $this->subwiki();
        $this->pages = $subwiki ? array_values($this->store->get_records('wiki_pages', ['subwikiid' => $subwiki->id], 'id')) : [];
        return $this->pages;
    }

    /**
     * mod/wiki/locallib.php wiki_get_first_page(): the page named by firstpagetitle.
     *
     * @return stdClass|null
     */
    public function wiki_get_first_page(): ?stdClass {
        foreach ($this->pages() as $page) {
            if ((string) $page->title === (string) $this->wiki->firstpagetitle) {
                return $page;
            }
        }
        return null;
    }

    /**
     * Where a page sits among the subwiki's pages.
     *
     * @param stdClass $page
     * @return int
     */
    public function index_of(stdClass $page): int {
        foreach ($this->pages() as $index => $candidate) {
            if ((string) $candidate->id === (string) $page->id) {
                return $index;
            }
        }
        return 0;
    }

    /**
     * mod/wiki/locallib.php wiki_get_current_version().
     *
     * @param mixed $pageid
     * @return stdClass|null
     */
    protected function wiki_get_current_version($pageid): ?stdClass {
        $versions = $this->store->get_records('wiki_versions', ['pageid' => $pageid]);
        $current = null;
        foreach ($versions as $version) {
            if ($current === null || (int) $version->version > (int) $current->version) {
                $current = $version;
            }
        }
        return $current;
    }

    /**
     * page_wiki_view::print_header() and print_content(), for one page.
     *
     * @param stdClass $page
     * @return string
     */
    public function page(stdClass $page): string {
        global $OUTPUT;

        $subwiki = $this->subwiki();
        $out = '';
        $out .= $this->action_bar($page);
        // wiki_print_subwiki_selector(): a collaborative wiki without groups
        // prints nothing here.
        $out .= $this->print_pagetitle($page);

        if (has_capability('mod/wiki:viewpage', $this->context)) {
            $out .= $this->wiki_print_page_content($page, $this->context, $subwiki->id);
        } else {
            $out .= get_string('cannotviewpage', 'wiki');
        }
        return $out;
    }

    /**
     * page_wiki::print_pagetitle().
     *
     * @param stdClass $page
     * @return string
     */
    protected function print_pagetitle(stdClass $page): string {
        global $OUTPUT;
        $html = '';

        $html .= $OUTPUT->container_start('wiki_headingtitle');
        $html .= $OUTPUT->heading(format_string($page->title), 3);
        $html .= $OUTPUT->container_end();
        return $html;
    }
}
