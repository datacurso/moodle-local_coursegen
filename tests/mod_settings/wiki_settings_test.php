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

global $CFG;
require_once($CFG->dirroot . '/mod/wiki/locallib.php');

/**
 * Unit tests for wiki_settings - how the generated pages become a real wiki.
 *
 * The mold path now ships a generated first page of its own, so its content
 * must survive; the legacy path ships none, and still gets the synthesized
 * index of wiki links.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\mod_settings\wiki_settings
 */
final class wiki_settings_test extends \advanced_testcase {
    /**
     * Create a wiki and return a cm-like object shaped as create_mod_service passes it.
     *
     * @param string $firstpagetitle The wiki's first page title.
     * @return object Object with ->coursemodule (cmid) and ->instance (wiki id).
     */
    private function make_wiki_cm(string $firstpagetitle): object {
        // Creating a page needs a logged in user holding mod/wiki:editpage.
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $wiki = $this->getDataGenerator()->create_module('wiki', [
            'course' => $course->id,
            'wikimode' => 'collaborative',
            'defaultformat' => 'html',
            'firstpagetitle' => $firstpagetitle,
        ]);
        return (object) ['coursemodule' => $wiki->cmid, 'instance' => $wiki->id];
    }

    /**
     * The current content of one page of a wiki, straight from its versions.
     *
     * @param int $wikiid The wiki instance id.
     * @param string $title The page title.
     * @return string The authored text of the page's current version.
     */
    private function page_content(int $wikiid, string $title): string {
        global $DB;

        $subwikiid = (int) $DB->get_field('wiki_subwikis', 'id', ['wikiid' => $wikiid], MUST_EXIST);
        $page = wiki_get_page_by_title($subwikiid, $title);
        $this->assertNotEmpty($page, "The wiki has no page titled '{$title}'.");

        return (string) wiki_get_current_version($page->id)->content;
    }

    /**
     * Count the pages of one wiki.
     *
     * @param int $wikiid The wiki instance id.
     * @return int Number of pages across the wiki's subwikis.
     */
    private function count_pages(int $wikiid): int {
        global $DB;

        return (int) $DB->count_records_sql(
            'SELECT COUNT(p.id)
               FROM {wiki_pages} p
               JOIN {wiki_subwikis} s ON s.id = p.subwikiid
              WHERE s.wikiid = ?',
            [$wikiid]
        );
    }

    /**
     * A generated page whose title is the wiki's first page title becomes that
     * first page WITH ITS OWN CONTENT - which is exactly where the mold's
     * markers were resolved - and is not created a second time.
     */
    public function test_generated_first_page_keeps_its_own_content(): void {
        $this->resetAfterTest();

        $cm = $this->make_wiki_cm('Índice del curso');
        $modsettings = ['pages' => [
            ['title' => 'Unidad 1', 'newcontent_editor' => ['text' => '<p>Contenido de la unidad 1</p>']],
            ['title' => 'Índice del curso', 'newcontent_editor' => ['text' => '<p>[[Unidad 1]] y algo más</p>']],
        ]];

        (new wiki_settings($cm, $modsettings))->add_settings();

        $this->assertSame('<p>[[Unidad 1]] y algo más</p>', $this->page_content($cm->instance, 'Índice del curso'));
        $this->assertSame('<p>Contenido de la unidad 1</p>', $this->page_content($cm->instance, 'Unidad 1'));
        // Created once: mod_wiki_external::new_page throws pageexists otherwise.
        $this->assertSame(2, $this->count_pages($cm->instance));
    }

    /**
     * The legacy path ships no first page, so the index of [[Title]] links is
     * still synthesized for it.
     */
    public function test_without_a_matching_page_the_link_index_is_synthesized(): void {
        $this->resetAfterTest();

        $cm = $this->make_wiki_cm('Front page');
        $modsettings = ['pages' => [
            ['title' => 'Unidad 1', 'newcontent_editor' => ['text' => '<p>Uno</p>']],
            ['title' => 'Unidad 2', 'newcontent_editor' => ['text' => '<p>Dos</p>']],
        ]];

        (new wiki_settings($cm, $modsettings))->add_settings();

        $this->assertSame("<p>[[Unidad 1]]</p>\n<p>[[Unidad 2]]</p>\n", $this->page_content($cm->instance, 'Front page'));
        $this->assertSame(3, $this->count_pages($cm->instance));
    }

    /**
     * Two generated pages sharing a title would make new_page throw pageexists,
     * so the list is still deduplicated by title.
     */
    public function test_duplicate_titles_are_still_dropped(): void {
        $this->resetAfterTest();

        $cm = $this->make_wiki_cm('Front page');
        $modsettings = ['pages' => [
            ['title' => 'Unidad 1', 'newcontent_editor' => ['text' => '<p>Uno</p>']],
            ['title' => 'Unidad 1', 'newcontent_editor' => ['text' => '<p>Uno otra vez</p>']],
        ]];

        (new wiki_settings($cm, $modsettings))->add_settings();

        $this->assertSame(2, $this->count_pages($cm->instance));
    }
}
