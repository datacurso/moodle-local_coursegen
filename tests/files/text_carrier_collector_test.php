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

namespace local_coursegen\local\files;

use local_coursegen\local\backup\activity_reader;

/**
 * The rows of an activity and the file areas its module declares for each.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\files\text_carrier_collector
 * @covers     \local_coursegen\local\files\text_carrier
 * @covers     \local_coursegen\local\files\file_area
 */
final class text_carrier_collector_test extends \advanced_testcase {
    /**
     * The rows found in an activity.
     *
     * @param \stdClass $module What a module generator returned.
     * @param string $modname
     * @return text_carrier[]
     */
    private function carriers(\stdClass $module, string $modname): array {
        activity_reader::require_backup_api();
        $collector = new text_carrier_collector();
        $cm = (object) ['id' => (int) $module->cmid, 'modname' => $modname, 'course' => (int) $module->course];
        $this->assertTrue(activity_reader::walk($cm, $collector, true));
        return $collector->get_carriers();
    }

    /**
     * The rows of one table.
     *
     * @param text_carrier[] $carriers
     * @param string $table
     * @return text_carrier[]
     */
    private function of_table(array $carriers, string $table): array {
        $found = [];
        foreach ($carriers as $carrier) {
            if ($carrier->table === $table) {
                $found[] = $carrier;
            }
        }
        return $found;
    }

    /**
     * The areas of a row as "component/area@item" strings.
     *
     * @param text_carrier $carrier
     * @return string[]
     */
    private function described(text_carrier $carrier): array {
        $described = [];
        foreach ($carrier->areas as $area) {
            $described[] = $area->component . '/' . $area->filearea . '@' . $area->itemid;
        }
        return $described;
    }

    /**
     * A page is one row whose files have no item id, in the module's own context.
     */
    public function test_a_page_is_one_row_with_its_intro_and_content_areas(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $rows = $this->of_table($this->carriers($page, 'page'), 'page');

        $this->assertCount(1, $rows);
        $this->assertSame((int) $page->id, $rows[0]->id);
        $this->assertSame(['mod_page/intro@0', 'mod_page/content@0'], $this->described($rows[0]));
        $this->assertSame(\context_module::instance($page->cmid)->id, $rows[0]->areas[0]->contextid);
    }

    /**
     * A row that the module stores its files under keeps their item id: the id of the row.
     */
    public function test_a_lesson_page_keeps_its_files_under_its_own_id(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_lesson')->create_content($lesson);
        $pageid = (int) $DB->get_field('lesson_pages', 'id', ['lessonid' => $lesson->id], MUST_EXIST);

        $rows = $this->of_table($this->carriers($lesson, 'lesson'), 'lesson_pages');

        $this->assertCount(1, $rows);
        $this->assertSame(['mod_lesson/page_contents@' . $pageid], $this->described($rows[0]));
    }

    /**
     * The answers of a lesson page declare their own two areas.
     */
    public function test_a_lesson_answer_declares_its_answer_and_response_areas(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_lesson')->create_question_truefalse($lesson);
        $answerid = (int) $DB->get_field('lesson_answers', 'id', ['lessonid' => $lesson->id], IGNORE_MULTIPLE);

        $rows = $this->of_table($this->carriers($lesson, 'lesson'), 'lesson_answers');

        $this->assertNotEmpty($rows);
        $this->assertSame(
            ['mod_lesson/page_answers@' . $answerid, 'mod_lesson/page_responses@' . $answerid],
            $this->described($rows[0])
        );
    }

    /**
     * What people file in a module is found: the entries of a glossary.
     */
    public function test_the_entries_of_a_glossary_are_found(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_glossary')->create_content($glossary, ['concept' => 'Term']);
        $entryid = (int) $DB->get_field('glossary_entries', 'id', ['glossaryid' => $glossary->id], MUST_EXIST);

        $rows = $this->of_table($this->carriers($glossary, 'glossary'), 'glossary_entries');

        $this->assertCount(1, $rows);
        $this->assertContains('mod_glossary/entry@' . $entryid, $this->described($rows[0]));
    }

    /**
     * A row that declares no areas takes those of the nearest parent that does: the pages of a wiki.
     */
    public function test_a_wiki_page_takes_the_areas_of_its_subwiki(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $wiki = $this->getDataGenerator()->create_module('wiki', ['course' => $course->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_wiki')->create_first_page($wiki);
        $subwikiid = (int) $DB->get_field('wiki_subwikis', 'id', ['wikiid' => $wiki->id], MUST_EXIST);

        $carriers = $this->carriers($wiki, 'wiki');

        foreach (['wiki_pages', 'wiki_versions'] as $table) {
            $rows = $this->of_table($carriers, $table);
            $this->assertNotEmpty($rows, $table);
            $this->assertSame(['mod_wiki/attachments@' . $subwikiid], $this->described($rows[0]), $table);
        }
    }
}
