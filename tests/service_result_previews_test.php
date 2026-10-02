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

namespace local_coursegen;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/fixtures/preview_page_setup.php');

use local_coursegen\local\preview\activity_preview_lookup;

/**
 * Tests for the previews over the result the service really produced for a template source.
 *
 * The fixture is the output of the service's own course template node, with only the model call stubbed
 * (tests/fixtures/service_result.json): a book, a glossary, a wiki, a choice and a quiz.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\activity_preview_lookup
 * @covers     \local_coursegen\local\preview\result_activity_check
 */
final class service_result_previews_test extends \advanced_testcase {
    use preview_page_setup;

    /**
     * The preview of an activity of the fixture, found the way the page finds it.
     *
     * @param string $modname
     * @param int $index
     * @return string The visible text.
     */
    private function drawn(string $modname, int $index = 0): string {
        $json = file_get_contents(__DIR__ . '/fixtures/service_result.json');
        $answer = json_decode($json, true);
        $activity = $this->service_activity($modname);
        $found = activity_preview_lookup::from_answer($answer, $activity['uid']);
        $preview = $this->preview_of($found['modname'], $found['parameters'], $index);
        return $this->text_of($preview->render());
    }

    /**
     * Each chapter of the book shows the text the service wrote for it, though two share a title.
     */
    public function test_each_chapter_of_the_book_shows_its_own_generated_text(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();
        $chapters = $this->service_activity('book')['parameters']['mod_settings']['chapters'];

        $pages = [];
        foreach (array_keys($chapters) as $index) {
            $pages[] = $this->drawn('book', $index);
        }

        foreach ($chapters as $index => $chapter) {
            $text = $this->text_of($chapter['content_editor']['text']);
            $this->assertStringContainsString($text, $pages[$index]);
            $others = array_diff_key($pages, [$index => true]);
            $this->assertStringNotContainsString($text, implode(' ', $others));
        }
        $this->assertSame(['Capitulo', 'Capitulo'], array_slice(array_column($chapters, 'title'), 1));
    }

    /**
     * The options of a choice are the rows of its tree, named by option_record_ids.
     */
    public function test_each_option_of_the_choice_is_shown(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();
        $parameters = $this->service_activity('choice')['parameters'];

        $html = $this->drawn('choice');

        foreach ($parameters['option'] as $option) {
            $this->assertStringContainsString($option, $html);
        }
        $this->assertCount(count($parameters['option']), $parameters['option_record_ids']);
    }

    /**
     * A glossary generated from a template holds no entries, and is drawn without them.
     */
    public function test_the_glossary_is_drawn_without_entries(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();

        $html = $this->drawn('glossary');

        $this->assertStringNotContainsString('coursegen:', $html);
    }

    /**
     * The quiz is drawn from its questions, whose ids the records of mod_settings name.
     */
    public function test_the_quiz_is_drawn_from_its_questions(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();
        $parameters = $this->service_activity('quiz')['parameters'];

        $html = $this->drawn('quiz');

        foreach ($parameters['questions'] as $slot) {
            $this->assertStringContainsString($slot['question']['questiontext'], $html);
        }
    }

    /**
     * A wiki page the service wrote is drawn from its own row of the tree.
     */
    public function test_the_wiki_page_the_service_wrote_is_drawn(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();
        $page = $this->service_activity('wiki')['parameters']['mod_settings']['pages'][0];

        $html = $this->drawn('wiki');

        $this->assertStringContainsString($page['title'], $html);
        $this->assertStringNotContainsString('coursegen:', $html);
    }

    /**
     * A page whose element the result gives no table for is refused, naming the activity and the record.
     */
    public function test_a_wiki_page_without_a_table_is_refused_naming_the_record(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();
        $answer = ['generated_activities' => [$this->service_activity('wiki')]];
        unset($answer['generated_activities'][0]['parameters']['structure_tables']['page']);

        try {
            activity_preview_lookup::from_answer($answer, $answer['generated_activities'][0]['uid']);
            $this->fail('A page the result cannot show must be refused');
        } catch (\moodle_exception $exception) {
            $this->assertSame('courseai_preview_record_without_row', $exception->errorcode);
            $this->assertStringContainsString('new-', $exception->a->record);
        }
    }
}
