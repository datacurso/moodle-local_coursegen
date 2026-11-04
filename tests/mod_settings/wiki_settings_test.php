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
require_once(__DIR__ . '/../fixtures/generated_images_test_trait.php');
require_once($CFG->dirroot . '/mod/wiki/locallib.php');

/**
 * Tests for wiki_settings: the generated images of the pages.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\mod_settings\wiki_settings
 */
final class wiki_settings_test extends \advanced_testcase {
    use \local_coursegen\generated_images_test_trait;

    /**
     * Create an HTML wiki and return a cm-like object shaped as create_mod_service passes it.
     *
     * @return object Object with ->coursemodule (cmid) and ->instance (wiki id).
     */
    private function make_wiki_cm(): object {
        $course = $this->getDataGenerator()->create_course();
        $wiki = $this->getDataGenerator()->create_module('wiki', [
            'course' => $course->id,
            'wikimode' => 'collaborative',
            'defaultformat' => 'html',
            'firstpagetitle' => 'Home',
        ]);
        return (object) ['coursemodule' => $wiki->cmid, 'instance' => $wiki->id];
    }

    /**
     * A page as the parameter cleaner leaves it: content pointing at a draft area with its image.
     *
     * @param string $title
     * @param string $filename
     * @return array
     */
    private function page_with_image(string $title, string $filename): array {
        return [
            'title' => $title,
            'newcontent_editor' => [
                'text' => '<p>' . $title . '</p><img src="@@PLUGINFILE@@/' . $filename . '" alt="">',
                'format' => FORMAT_HTML,
                'itemid' => $this->draft_with_image($filename),
            ],
        ];
    }

    /**
     * The images of every page end in the attachments of the subwiki; a later page does not remove
     * the images of an earlier one, since the area is shared by the pages of the subwiki.
     */
    public function test_images_of_every_page_are_stored_in_the_subwiki(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $cm = $this->make_wiki_cm();

        (new wiki_settings($cm, ['pages' => [
            $this->page_with_image('Drying', 'img_drying.png'),
            $this->page_with_image('Storage', 'img_storage.png'),
        ]]))->add_settings();

        $subwikiid = (int) $DB->get_field('wiki_subwikis', 'id', ['wikiid' => $cm->instance], MUST_EXIST);
        $context = \context_module::instance($cm->coursemodule);
        $fs = get_file_storage();
        $this->assertTrue($fs->file_exists($context->id, 'mod_wiki', 'attachments', $subwikiid, '/', 'img_drying.png'));
        $this->assertTrue($fs->file_exists($context->id, 'mod_wiki', 'attachments', $subwikiid, '/', 'img_storage.png'));

        $page = wiki_get_page_by_title($subwikiid, 'Drying');
        $this->assertStringContainsString('@@PLUGINFILE@@/img_drying.png', $page->cachedcontent);
    }

    /**
     * Pages without a draft area are still created.
     */
    public function test_pages_without_draft_area_are_created(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $cm = $this->make_wiki_cm();

        (new wiki_settings($cm, ['pages' => [
            ['title' => 'Plain', 'newcontent_editor' => ['text' => '<p>Text</p>', 'format' => FORMAT_HTML]],
        ]]))->add_settings();

        $subwikiid = (int) $DB->get_field('wiki_subwikis', 'id', ['wikiid' => $cm->instance], MUST_EXIST);
        $this->assertNotFalse(wiki_get_page_by_title($subwikiid, 'Plain'));
    }
}
