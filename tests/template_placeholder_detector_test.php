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

use local_coursegen\local\service\template_placeholder_detector;

/**
 * Unit tests for the detector that tells whether an activity has a placeholder.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_placeholder_detector
 *
 * @runTestsInSeparateProcesses
 */
final class template_placeholder_detector_test extends \advanced_testcase {
    /** @var string A valid marker in the double bracket dialect. */
    private const BRACKET_MARKER = '[[coursegen:aiprompt: write the lesson]]';

    /** @var string A valid marker in the angle bracket dialect. */
    private const ANGLE_MARKER = '⟦coursegen:aiprompt: write the lesson⟧';

    /**
     * Create a page and answer its course module info.
     *
     * @param array $record Fields of the page to override.
     * @return \cm_info
     */
    private function page_cm(array $record): \cm_info {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $record['course'] = $course->id;
        $page = $generator->create_module('page', $record);
        $modinfo = get_fast_modinfo($course);
        return $modinfo->get_cm($page->cmid);
    }

    /**
     * A marker in the double bracket dialect in the page content counts.
     */
    public function test_page_with_a_bracket_marker_has_a_placeholder(): void {
        $this->resetAfterTest();
        $cm = $this->page_cm(['content' => '<p>' . self::BRACKET_MARKER . '</p>']);

        $found = template_placeholder_detector::has_placeholder($cm);

        $this->assertTrue($found);
    }

    /**
     * A marker in the angle bracket dialect in the page content counts.
     */
    public function test_page_with_an_angle_marker_has_a_placeholder(): void {
        $this->resetAfterTest();
        $cm = $this->page_cm(['content' => '<p>' . self::ANGLE_MARKER . '</p>']);

        $found = template_placeholder_detector::has_placeholder($cm);

        $this->assertTrue($found);
    }

    /**
     * A repeat block alone is a placeholder, in both dialects.
     */
    public function test_page_with_only_a_repeat_block_has_a_placeholder(): void {
        $this->resetAfterTest();
        $bracket = $this->page_cm(['content' => '[[coursegen:repeat: one per unit]]<li>x</li>[[/coursegen:repeat]]']);
        $angle = $this->page_cm(['content' => '⟦coursegen:repeat: one per unit⟧<li>x</li>⟦/coursegen:repeat⟧']);

        $bracketfound = template_placeholder_detector::has_placeholder($bracket);
        $anglefound = template_placeholder_detector::has_placeholder($angle);

        $this->assertTrue($bracketfound);
        $this->assertTrue($anglefound);
    }

    /**
     * A page with no marker at all is not a mold.
     */
    public function test_page_without_a_marker_has_no_placeholder(): void {
        $this->resetAfterTest();
        $cm = $this->page_cm(['content' => '<p>Plain lesson text.</p>']);

        $found = template_placeholder_detector::has_placeholder($cm);

        $this->assertFalse($found);
    }

    /**
     * Plain double brackets, without the coursegen prefix, are not accepted by the AI service any more.
     */
    public function test_plain_double_brackets_are_not_a_placeholder(): void {
        $this->resetAfterTest();
        $cm = $this->page_cm(['content' => '<p>[[lesson title]] and ⟦lesson summary⟧</p>']);

        $found = template_placeholder_detector::has_placeholder($cm);

        $this->assertFalse($found);
    }

    /**
     * A marker with no instruction does not count, as the AI service rejects it.
     */
    public function test_marker_with_an_empty_instruction_is_not_a_placeholder(): void {
        $this->resetAfterTest();
        $cm = $this->page_cm(['content' => '<p>[[coursegen:aiprompt:]] ⟦coursegen:aiprompt:  ⟧</p>']);

        $found = template_placeholder_detector::has_placeholder($cm);

        $this->assertFalse($found);
    }

    /**
     * A marker in any of the activity's text fields counts, not only in its main content.
     */
    public function test_marker_in_the_introduction_alone_counts(): void {
        $this->resetAfterTest();
        $cm = $this->page_cm(['intro' => '<p>' . self::BRACKET_MARKER . '</p>', 'content' => '<p>Plain.</p>']);

        $found = template_placeholder_detector::has_placeholder($cm);

        $this->assertTrue($found);
    }

    /**
     * A valid marker in one field is enough when the other fields are empty or plain.
     */
    public function test_one_valid_marker_among_other_fields_counts(): void {
        $this->resetAfterTest();
        $cm = $this->page_cm([
            'intro' => '<p>[[coursegen:aiprompt:]]</p>',
            'content' => '<p>[[plain]] ' . self::ANGLE_MARKER . '</p>',
        ]);

        $found = template_placeholder_detector::has_placeholder($cm);

        $this->assertTrue($found);
    }

    /**
     * In a wiki page the double brackets are the wiki's own link syntax, so only the angle dialect counts there.
     */
    public function test_wiki_page_reads_only_the_angle_dialect(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $bracketwiki = $generator->create_module('wiki', ['course' => $course->id]);
        $anglewiki = $generator->create_module('wiki', ['course' => $course->id]);
        $wikigenerator = $generator->get_plugin_generator('mod_wiki');
        $wikigenerator->create_first_page($bracketwiki, ['content' => '<p>' . self::BRACKET_MARKER . '</p>']);
        $wikigenerator->create_first_page($anglewiki, ['content' => '<p>' . self::ANGLE_MARKER . '</p>']);
        $modinfo = get_fast_modinfo($course);
        $bracketcm = $modinfo->get_cm($bracketwiki->cmid);
        $anglecm = $modinfo->get_cm($anglewiki->cmid);

        $bracketfound = template_placeholder_detector::has_placeholder($bracketcm);
        $anglefound = template_placeholder_detector::has_placeholder($anglecm);

        $this->assertFalse($bracketfound);
        $this->assertTrue($anglefound);
    }

    /**
     * The wiki's introduction is ordinary text, so the double bracket dialect still counts there.
     */
    public function test_wiki_introduction_still_reads_both_dialects(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $wiki = $generator->create_module('wiki', ['course' => $course->id, 'intro' => '<p>' . self::BRACKET_MARKER . '</p>']);
        $modinfo = get_fast_modinfo($course);
        $cm = $modinfo->get_cm($wiki->cmid);

        $found = template_placeholder_detector::has_placeholder($cm);

        $this->assertTrue($found);
    }

    /**
     * In the templates of a database the double brackets name its fields, so only the angle dialect counts there.
     */
    public function test_database_template_reads_only_the_angle_dialect(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $bracketdata = $generator->create_module('data', [
            'course' => $course->id,
            'singletemplate' => '<h2>[[Title]] ' . self::BRACKET_MARKER . '</h2>',
        ]);
        $angledata = $generator->create_module('data', [
            'course' => $course->id,
            'listtemplate' => '<h2>[[Title]] ' . self::ANGLE_MARKER . '</h2>',
        ]);
        $modinfo = get_fast_modinfo($course);
        $bracketcm = $modinfo->get_cm($bracketdata->cmid);
        $anglecm = $modinfo->get_cm($angledata->cmid);

        $bracketfound = template_placeholder_detector::has_placeholder($bracketcm);
        $anglefound = template_placeholder_detector::has_placeholder($anglecm);

        $this->assertFalse($bracketfound);
        $this->assertTrue($anglefound);
    }

    /**
     * The introduction of a database is ordinary text, so the double bracket dialect counts there.
     */
    public function test_database_introduction_still_reads_both_dialects(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $data = $generator->create_module('data', ['course' => $course->id, 'intro' => '<p>' . self::BRACKET_MARKER . '</p>']);
        $modinfo = get_fast_modinfo($course);
        $cm = $modinfo->get_cm($data->cmid);

        $found = template_placeholder_detector::has_placeholder($cm);

        $this->assertTrue($found);
    }

    /**
     * Create a quiz holding one question and answer its course module info.
     *
     * @param string $qtype The question type.
     * @param string $questiontext The text of the question.
     * @return \cm_info
     */
    private function quiz_cm_with_question(string $qtype, string $questiontext): \cm_info {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz = $generator->create_module('quiz', ['course' => $course->id]);
        $questiongenerator = $generator->get_plugin_generator('core_question');
        $coursecontext = \context_course::instance($course->id);
        $category = $questiongenerator->create_question_category(['contextid' => $coursecontext->id]);
        $question = $questiongenerator->create_question($qtype, null, [
            'category' => $category->id,
            'questiontext' => ['text' => $questiontext, 'format' => FORMAT_HTML],
        ]);
        quiz_add_quiz_question($question->id, $quiz);
        $modinfo = get_fast_modinfo($course);
        return $modinfo->get_cm($quiz->cmid);
    }

    /**
     * In a gap select question the double brackets number its gaps, so only the angle dialect counts there.
     */
    public function test_gap_select_question_reads_only_the_angle_dialect(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $bracketcm = $this->quiz_cm_with_question('gapselect', 'The [[1]] sat on the [[2]]. ' . self::BRACKET_MARKER);
        $anglecm = $this->quiz_cm_with_question('gapselect', 'The [[1]] sat on the [[2]]. ' . self::ANGLE_MARKER);

        $bracketfound = template_placeholder_detector::has_placeholder($bracketcm);
        $anglefound = template_placeholder_detector::has_placeholder($anglecm);

        $this->assertFalse($bracketfound);
        $this->assertTrue($anglefound);
    }

    /**
     * Any other question type reads both dialects in its text.
     */
    public function test_other_question_types_read_both_dialects(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $cm = $this->quiz_cm_with_question('shortanswer', 'Name the frog. ' . self::BRACKET_MARKER);

        $found = template_placeholder_detector::has_placeholder($cm);

        $this->assertTrue($found);
    }

    /**
     * A quiz with a question and no marker anywhere has no placeholder.
     */
    public function test_quiz_without_a_marker_has_no_placeholder(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $cm = $this->quiz_cm_with_question('shortanswer', 'Name the frog.');

        $found = template_placeholder_detector::has_placeholder($cm);

        $this->assertFalse($found);
    }

    /**
     * The same detection works on parameters already built, nested lists included.
     */
    public function test_parameters_are_read_through_nested_lists(): void {
        $parameters = [
            'name' => 'Book',
            'structure' => [
                'name' => 'Book',
                'chapters' => [
                    [
                        'chapter' => [
                            ['title' => 'One', 'content' => 'x'],
                            ['title' => 'Two', 'content' => self::ANGLE_MARKER],
                        ],
                    ],
                ],
            ],
        ];

        $found = template_placeholder_detector::parameters_have_placeholder('book', $parameters);

        $this->assertTrue($found);
    }

    /**
     * Parameters with no text field and no questions have no placeholder.
     */
    public function test_empty_parameters_have_no_placeholder(): void {
        $found = template_placeholder_detector::parameters_have_placeholder('page', ['structure' => []]);

        $this->assertFalse($found);
    }

    /**
     * The name of a field that is switched off in one module is read normally in another.
     */
    public function test_a_switched_off_field_name_is_only_switched_off_in_its_module(): void {
        $parameters = ['structure' => ['content' => self::BRACKET_MARKER]];

        $wikifound = template_placeholder_detector::parameters_have_placeholder('wiki', $parameters);
        $pagefound = template_placeholder_detector::parameters_have_placeholder('page', $parameters);

        $this->assertFalse($wikifound);
        $this->assertTrue($pagefound);
    }
}
