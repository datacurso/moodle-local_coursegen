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
 * mod_wiki's view code, ported to run against the payload.
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
     * mod/wiki/classes/output/action_bar.php, rendered through mod_wiki/action_bar.
     *
     * @param stdClass $page
     * @return string
     */
    protected function action_bar(stdClass $page): string {
        // The real bar is a menu to edit, comment on, map and administer the
        // page, and a button to print it. Nobody may act on an activity that does not exist: the
        // bar is not drawn. The page's own links stay, and move within the
        // preview.
        return '';
    }

    /**
     * action_bar::get_action_selector(), with every entry leading back to the preview.
     *
     * @param int $index
     * @return url_select
     */
    protected function get_action_selector(int $index): url_select {
        $menu = [];
        $context = $this->context;
        $current = ($this->urls)($index, ['tab' => 'view'])->out(false);
        if (has_capability('mod/wiki:viewpage', $context)) {
            $menu[$current] = get_string('view', 'mod_wiki');
        }
        if (has_capability('mod/wiki:editpage', $context)) {
            $menu[($this->urls)($index, ['tab' => 'edit'])->out(false)] = get_string('edit', 'mod_wiki');
        }
        if (has_capability('mod/wiki:viewcomment', $context)) {
            $menu[($this->urls)($index, ['tab' => 'comments'])->out(false)] = get_string('comments', 'mod_wiki');
        }
        if (has_capability('mod/wiki:viewpage', $context)) {
            $menu[($this->urls)($index, ['tab' => 'history'])->out(false)] = get_string('history', 'mod_wiki');
        }
        if (has_capability('mod/wiki:viewpage', $context)) {
            $menu[($this->urls)($index, ['tab' => 'map'])->out(false)] = get_string('map', 'mod_wiki');
        }
        if (has_capability('mod/wiki:viewpage', $context)) {
            $menu[($this->urls)($index, ['tab' => 'files'])->out(false)] = get_string('files', 'mod_wiki');
        }
        if (has_capability('mod/wiki:managewiki', $context)) {
            $menu[($this->urls)($index, ['tab' => 'admin'])->out(false)] = get_string('admin', 'mod_wiki');
        }
        // The page is opened by its module's address, which is none of the
        // entries, so none is marked as where the reader is.
        return new url_select($menu, ($this->urls)($index, [])->out(false), null, 'wikiactionselect');
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

    /**
     * mod/wiki/locallib.php wiki_print_page_content(), rendering from the latest version.
     *
     * @param stdClass $page
     * @param context $context
     * @param mixed $subwikiid
     * @return string
     */
    protected function wiki_print_page_content($page, $context, $subwikiid): string {
        global $OUTPUT;
        $out = '';

        // wiki_refresh_cachedcontent(): the page rendered from its latest
        // version, kept here rather than written back.
        $content = $this->wiki_refresh_cachedcontent($page);
        if (isset($content)) {
            $page = $content['page'];
            $box = '';
            foreach ($content['sections'] as $s) {
                $box .= '<p>' . get_string('repeatedsection', 'wiki', $s) . '</p>';
            }

            if (!empty($box)) {
                $out .= $OUTPUT->box($box);
            }
        }
        $html = file_rewrite_pluginfile_urls((string) ($page->cachedcontent ?? ''), 'pluginfile.php', $context->id, 'mod_wiki', 'attachments', $subwikiid);
        $html = format_text($html, FORMAT_HTML, array('overflowdiv' => true, 'allowid' => true));
        $out .= $OUTPUT->box($html);

        // The page's tags are read by id from the site's tag tables; a page
        // that does not exist has none there.
        $out .= $OUTPUT->tag_list([], null, 'wiki-tags');

        return $out;
    }

    /**
     * mod/wiki/locallib.php wiki_refresh_cachedcontent(), without the write.
     *
     * @param stdClass $page
     * @return array|null
     */
    protected function wiki_refresh_cachedcontent($page) {
        $version = $this->wiki_get_current_version($page->id);
        if (empty($version)) {
            return null;
        }
        $newcontent = $version->content;

        $options = array('swid' => $page->subwikiid, 'pageid' => $page->id);
        $parseroutput = $this->wiki_parse_content($version->contentformat, $newcontent, $options);
        $page = clone $page;
        $page->cachedcontent = $parseroutput['toc'] . $parseroutput['parsed_text'];
        $page->timerendered = time();

        return array('page' => $page, 'sections' => $parseroutput['repeated_sections'], 'version' => $version->version);
    }

    /**
     * mod/wiki/locallib.php wiki_parse_content(), with the links resolved here.
     *
     * @param string $markup
     * @param string $pagecontent
     * @param array $options
     * @return array
     */
    protected function wiki_parse_content($markup, $pagecontent, $options = array()) {
        $subwiki = $this->subwiki();
        $context = $this->context;

        $parser_options = array(
            'link_callback' => '/local/coursegen/classes/local/preview/wiki/links.php:local_coursegen_wiki_preview_link',
            'link_callback_args' => array('swid' => $options['swid']),
            'table_callback' => '/mod/wiki/locallib.php:wiki_parser_table',
            'real_path_callback' => '/mod/wiki/locallib.php:wiki_parser_real_path',
            'real_path_callback_args' => array(
                'context' => $context,
                'component' => 'mod_wiki',
                'filearea' => 'attachments',
                'subwikiid'=> $subwiki->id,
                'pageid' => $options['pageid']
            ),
            'pageid' => $options['pageid'],
            // The [edit] link beside each section opens the real page's
            // editor; the page is drawn the way mod_wiki's pretty view draws
            // it, without them.
            'pretty_print' => true,
            'printable' => (isset($options['printable']) && $options['printable'])
        );

        self::$parsing = $this;
        try {
            return wiki_parser_proxy::parse($pagecontent, $markup, $parser_options);
        } finally {
            self::$parsing = null;
        }
    }

    /**
     * mod/wiki/locallib.php wiki_parser_link(), against the payload's pages.
     *
     * A link to a page the subwiki has leads to that page's preview; a link to
     * one it does not is drawn as the new link it would be, leading nowhere
     * new: a preview creates nothing.
     *
     * @param string|stdClass $link
     * @param array|null $options
     * @return array
     */
    public static function wiki_parser_link($link, $options = null) {
        $view = self::$parsing;
        if ($view === null) {
            return array('content' => is_object($link) ? $link->title : $link, 'url' => '#', 'new' => true,
                'link_info' => array('link' => is_object($link) ? $link->title : $link, 'new' => true, 'pageid' => 0));
        }

        if (is_object($link)) {
            $parsedlink = array('content' => $link->title, 'url' => ($view->urls)($view->index_of($link), [])->out(false),
                'new' => false, 'link_info' => array('link' => $link->title, 'pageid' => $link->id, 'new' => false));

            $version = $view->wiki_get_current_version($link->id);
            if (!$version || $version->version == 0) {
                $parsedlink['new'] = true;
            }
            return $parsedlink;
        } else {
            foreach ($view->pages() as $index => $page) {
                if ((string) $page->title === (string) $link) {
                    $parsedlink = array('content' => $link, 'url' => ($view->urls)($index, [])->out(false), 'new' => false,
                        'link_info' => array('link' => $link, 'pageid' => $page->id, 'new' => false));

                    $version = $view->wiki_get_current_version($page->id);
                    if (!$version || $version->version == 0) {
                        $parsedlink['new'] = true;
                    }

                    return $parsedlink;
                }
            }
            $here = ($view->urls)($view->index_of($view->wiki_get_first_page() ?? (object) ['id' => 0]), []);
            return array('content' => $link, 'url' => $here->out(false), 'new' => true,
                'link_info' => array('link' => $link, 'new' => true, 'pageid' => 0));
        }
    }
}
