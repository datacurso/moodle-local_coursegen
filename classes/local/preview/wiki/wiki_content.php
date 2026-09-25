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

/**
 * mod_wiki's page content rendering: parsing a page's markup with the
 * module's own parser, resolving links against the payload so they lead to
 * the preview of the page they name, and caching the result the way the
 * module does. Kept apart from view.php only because together they crossed
 * the 250-line cap - both are the same "draw one page" concern, split for
 * size alone.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait wiki_content {

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
            $paragraphs = [];
            foreach ($content['sections'] as $s) {
                $sectiontext = get_string('repeatedsection', 'wiki', $s);
                $paragraphs[] = ['text' => $sectiontext];
            }

            if (!empty($paragraphs)) {
                $box = $OUTPUT->render_from_template('local_coursegen/preview_paragraphs', [
                    'classes' => '',
                    'paragraphs' => $paragraphs,
                ]);
                $out .= $OUTPUT->box($box);
            }
        }
        $cachedcontent = $page->cachedcontent ?? '';
        $cachedcontent = (string) $cachedcontent;
        $html = file_rewrite_pluginfile_urls($cachedcontent, 'pluginfile.php', $context->id, 'mod_wiki', 'attachments', $subwikiid);
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
            return self::wiki_parser_link_unresolved($link);
        }
        if (is_object($link)) {
            return self::wiki_parser_link_for_page_object($view, $link);
        }
        return self::wiki_parser_link_for_title($view, $link);
    }

    /**
     * A link drawn with no view to resolve it against: the new link it would be.
     *
     * @param string|stdClass $link
     * @return array
     */
    private static function wiki_parser_link_unresolved($link): array {
        $title = $link;
        if (is_object($link)) {
            $title = $link->title;
        }
        return array('content' => $title, 'url' => '#', 'new' => true,
            'link_info' => array('link' => $title, 'new' => true, 'pageid' => 0));
    }

    /**
     * A link that names one of the subwiki's pages directly.
     *
     * @param view $view
     * @param stdClass $link
     * @return array
     */
    private static function wiki_parser_link_for_page_object($view, $link): array {
        $urls = $view->urls;
        $index = $view->index_of($link);
        $url = $urls($index, [])->out(false);
        $parsedlink = array('content' => $link->title, 'url' => $url,
            'new' => false, 'link_info' => array('link' => $link->title, 'pageid' => $link->id, 'new' => false));

        $version = $view->wiki_get_current_version($link->id);
        if (!$version || $version->version == 0) {
            $parsedlink['new'] = true;
        }
        return $parsedlink;
    }

    /**
     * A link that names a page by its title, resolved against the subwiki's pages.
     *
     * @param view $view
     * @param string $link
     * @return array
     */
    private static function wiki_parser_link_for_title($view, $link): array {
        $page = self::find_page_by_title($view, $link);
        if ($page === null) {
            return self::wiki_parser_link_for_new_title($view, $link);
        }

        $urls = $view->urls;
        $index = $view->index_of($page);
        $url = $urls($index, [])->out(false);
        $parsedlink = array('content' => $link, 'url' => $url, 'new' => false,
            'link_info' => array('link' => $link, 'pageid' => $page->id, 'new' => false));

        $version = $view->wiki_get_current_version($page->id);
        if (!$version || $version->version == 0) {
            $parsedlink['new'] = true;
        }
        return $parsedlink;
    }

    /**
     * The subwiki's page with the given title, if it has one.
     *
     * @param view $view
     * @param string $title
     * @return stdClass|null
     */
    private static function find_page_by_title($view, $title) {
        foreach ($view->pages() as $page) {
            if ((string) $page->title === (string) $title) {
                return $page;
            }
        }
        return null;
    }

    /**
     * A link to a title the subwiki has no page for: leads to where a new
     * page would go, next to the wiki's first page.
     *
     * @param view $view
     * @param string $link
     * @return array
     */
    private static function wiki_parser_link_for_new_title($view, $link): array {
        $missingpage = (object) ['id' => 0];
        $firstpage = $view->wiki_get_first_page() ?? $missingpage;
        $index = $view->index_of($firstpage);
        $urls = $view->urls;
        $here = $urls($index, []);
        $url = $here->out(false);
        return array('content' => $link, 'url' => $url, 'new' => true,
            'link_info' => array('link' => $link, 'new' => true, 'pageid' => 0));
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
}
