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

use mod_wiki_external;

/**
 * Class wiki_settings
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wiki_settings extends base_settings {
    /** @var string Effective wiki page format ('html'|'creole'|'nwiki'). */
    protected string $wikiformat = 'html';

    /**
     * Add settings to wiki module.
     */
    public function add_settings() {
        $wiki = wiki_get_wiki($this->cm->instance);

        // Moodle forces every page to the wiki's defaultformat; seed content/links
        // must use that same markup or they render as literal text.
        $this->wikiformat = $wiki->defaultformat ?: 'html';

        // Remove duplicate pages by title, excluding the first page title on wiki.
        $pages = $this->unique_pages_by_title($this->modsettings['pages'], $wiki->firstpagetitle);

        // Build first page data for the wiki.
        $firstpage = $this->get_first_page($pages, $wiki);

        // Add first page to wiki.
        $this->add_page($firstpage);

        // Add pages to wiki.
        foreach ($pages as $page) {
            $this->add_page($page);
        }
    }

    /**
     * Build first page data for the wiki.
     *
     * Creates the first page content with links to each additional page using Moodle
     * Wiki syntax `[[Title]]`. Clicking a link to a non-existent page lets users create it.
     *
     * @param array $pages The pages to add to the wiki to generate the links.
     * @param object $wiki The wiki object.
     *
     * @return array {title, newcontent_editor{text (HTML), format=FORMAT_HTML}} ready for self::add_page().
     */
    protected function get_first_page($pages, $wiki) {
        $firstpagetitle = $wiki->firstpagetitle;
        $firstpagecontent = '';
        foreach ($pages as $page) {
            $title = $page['title'];
            // The [[Title]] wiki-link syntax works in all formats; only wrap in <p> for HTML.
            if ($this->wikiformat === 'html') {
                $firstpagecontent .= "<p>[[{$title}]]</p>\n";
            } else {
                $firstpagecontent .= "[[{$title}]]\n\n";
            }
        }
        return [
            'title' => $firstpagetitle,
            'newcontent_editor' => [
                'text' => $firstpagecontent,
                'format' => $this->wikiformat,
            ],
        ];
    }

    /**
     * Add page to wiki.
     *
     * @param array $page Page data.
     */
    protected function add_page($page) {
        global $DB;

        $content = $page['newcontent_editor']['text'];
        $result = mod_wiki_external::new_page($page['title'], $content, $this->wikiformat, null, $this->cm->instance);

        $draftitemid = (int) ($page['newcontent_editor']['itemid'] ?? 0);
        if ($draftitemid > 0) {
            $subwikiid = (int) $DB->get_field('wiki_pages', 'subwikiid', ['id' => $result['pageid']], MUST_EXIST);
            $this->copy_draft_images($draftitemid, $subwikiid);
        }
    }

    /**
     * Copy the generated images of a page from its draft area to the attachments of the subwiki.
     *
     * The parameter cleaner downloaded them into the draft area and pointed the content at the
     * plugin file URLs, but mod_wiki_external::new_page() saves no files. The attachments area is
     * shared by every page of the subwiki, so the files are added one by one: saving the draft
     * area over it would delete the images of the pages created before.
     *
     * @param int $draftitemid Draft itemid holding the images of the page.
     * @param int $subwikiid Subwiki the page belongs to.
     */
    protected function copy_draft_images(int $draftitemid, int $subwikiid): void {
        global $USER;

        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        $modcontext = \context_module::instance($this->cm->coursemodule);
        $draftfiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);

        foreach ($draftfiles as $draftfile) {
            $exists = $fs->file_exists(
                $modcontext->id,
                'mod_wiki',
                'attachments',
                $subwikiid,
                $draftfile->get_filepath(),
                $draftfile->get_filename()
            );
            if ($exists) {
                continue;
            }
            $fs->create_file_from_storedfile([
                'contextid' => $modcontext->id,
                'component' => 'mod_wiki',
                'filearea' => 'attachments',
                'itemid' => $subwikiid,
            ], $draftfile);
        }
    }

    /**
     * Remove duplicate pages by title, excluding the first page title on wiki.
     *
     * @param array $pages
     * @param string $firstpagetitle The title of the first page
     * @return array The pages without duplicates by title
     */
    protected function unique_pages_by_title(array $pages, string $firstpagetitle) {
        $result = [];
        foreach ($pages as $page) {
            $title = $page['title'];
            if ($title == $firstpagetitle) {
                continue;
            }
            $result[$title] = $page;
        }
        return array_values($result);
    }
}
