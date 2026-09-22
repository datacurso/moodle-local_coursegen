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
                $paragraphs[] = ['text' => get_string('repeatedsection', 'wiki', $s)];
            }

            if (!empty($paragraphs)) {
                $box = $OUTPUT->render_from_template('local_coursegen/preview_paragraphs', [
                    'classes' => '',
                    'paragraphs' => $paragraphs,
                ]);
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
