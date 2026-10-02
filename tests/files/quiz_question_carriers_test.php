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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * The rows of the questions a quiz asks, which its backup structure only refers to.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\files\quiz_question_carriers
 */
final class quiz_question_carriers_test extends \advanced_testcase {
    /**
     * A quiz with one multiple choice question.
     *
     * @return array{0: \stdClass, 1: \stdClass} The quiz and the question.
     */
    private function quiz_with_question(): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $context = \context_module::instance($quiz->cmid);
        $category = $generator->create_question_category(['contextid' => $context->id]);
        $question = $generator->create_question('multichoice', 'one_of_four', ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $quiz);
        return [$quiz, $question];
    }

    /**
     * The rows of the quiz's questions.
     *
     * @param \stdClass $quiz
     * @return text_carrier[]
     */
    private function carriers_of(\stdClass $quiz): array {
        $activity = (object) ['id' => (int) $quiz->cmid, 'instance' => (int) $quiz->id, 'modname' => 'quiz'];
        $provider = new quiz_question_carriers();
        return $provider->carriers($activity);
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
     * The areas of a row as "area@item" strings.
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
     * The question keeps its text and general feedback under its own id, in the context of its category.
     */
    public function test_the_question_row_declares_its_text_areas(): void {
        $this->resetAfterTest();
        [$quiz, $question] = $this->quiz_with_question();

        $rows = $this->of_table($this->carriers_of($quiz), 'question');

        $this->assertCount(1, $rows);
        $this->assertSame((int) $question->id, $rows[0]->id);
        $this->assertSame(
            ['question/questiontext@' . $question->id, 'question/generalfeedback@' . $question->id],
            $this->described($rows[0])
        );
        $this->assertSame(\context_module::instance($quiz->cmid)->id, $rows[0]->areas[0]->contextid);
    }

    /**
     * Each answer keeps its text and its feedback under its own id.
     */
    public function test_every_answer_declares_its_areas(): void {
        global $DB;
        $this->resetAfterTest();
        [$quiz, $question] = $this->quiz_with_question();
        $answers = $DB->get_records('question_answers', ['question' => $question->id], 'id');

        $rows = $this->of_table($this->carriers_of($quiz), 'question_answers');

        $this->assertCount(count($answers), $rows);
        $first = reset($answers);
        $this->assertSame(
            ['question/answer@' . $first->id, 'question/answerfeedback@' . $first->id],
            $this->described($rows[0])
        );
    }

    /**
     * The options of the question's type keep their feedback under the id of the question.
     */
    public function test_the_options_of_the_type_declare_one_area_per_text(): void {
        $this->resetAfterTest();
        [$quiz, $question] = $this->quiz_with_question();

        $rows = $this->of_table($this->carriers_of($quiz), 'qtype_multichoice_options');

        $this->assertCount(1, $rows);
        $areas = $this->described($rows[0]);
        $this->assertContains('question/correctfeedback@' . $question->id, $areas);
        $this->assertContains('question/partiallycorrectfeedback@' . $question->id, $areas);
        $this->assertContains('question/incorrectfeedback@' . $question->id, $areas);
    }

    /**
     * A quiz with no questions has no rows.
     */
    public function test_a_quiz_without_questions_has_no_rows(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);

        $this->assertSame([], $this->carriers_of($quiz));
    }
}
