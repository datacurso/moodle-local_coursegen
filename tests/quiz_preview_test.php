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

/**
 * Tests for the quiz preview, drawn from the questions its result carries.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\quiz_preview
 */
final class quiz_preview_test extends \advanced_testcase {
    use preview_page_setup;

    /**
     * The parameters of a finished quiz that carries the given questions.
     *
     * @param array $questions The questions the AI wrote, as the editing form submits them.
     * @return array
     */
    private function parameters_with_questions(array $questions): array {
        $quiz = ['id' => 3, 'course' => 1, 'name' => 'A quiz', 'intro' => '', 'introformat' => 1,
            'preferredbehaviour' => 'deferredfeedback', 'navmethod' => 'free', 'questionsperpage' => 1,
            'timelimit' => 0, 'attempts' => 0, 'grademethod' => 1, 'sumgrades' => 2, 'grade' => 10, 'timeopen' => 0, 'timeclose' => 0];
        return [
            'name' => 'A quiz',
            'structure' => ['quiz' => [$quiz]],
            'structure_tables' => ['quiz' => 'quiz'],
            'structure_aliases' => [],
            'mod_settings' => ['questions' => $questions],
        ];
    }

    /**
     * One true/false question, as the editing form submits it.
     *
     * @param string $text
     * @param string $sourceid
     * @return array
     */
    private function question(string $text, string $sourceid): array {
        return ['qtype' => 'truefalse', 'name' => 'Question', 'questiontext' => ['text' => $text, 'format' => FORMAT_HTML],
            'defaultmark' => 1, 'correctanswer' => 1, 'feedbacktrue' => ['text' => 'Good', 'format' => FORMAT_HTML],
            'feedbackfalse' => ['text' => 'Bad', 'format' => FORMAT_HTML], 'source_id' => $sourceid];
    }

    /**
     * Two questions with the same name each show their own text, taken from the result.
     */
    public function test_questions_with_the_same_name_each_show_their_own_text(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();
        $parameters = $this->parameters_with_questions([
            $this->question('<p>Alpha question</p>', '31'),
            $this->question('<p>Beta question</p>', '32'),
        ]);

        $html = $this->text_of($this->preview_of('quiz', $parameters)->render());

        $this->assertStringContainsString('Alpha question', $html);
        $this->assertStringContainsString('Beta question', $html);
        $this->assertStringNotContainsString('[[coursegen:', $html);
    }

    /**
     * A quiz the payload describes, which holds its questions already as slots, is not drawn from written ones.
     */
    public function test_a_quiz_without_written_questions_does_not_fail(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();
        $parameters = $this->parameters_with_questions([]);

        $html = $this->preview_of('quiz', $parameters)->render();

        $this->assertIsString($html);
    }
}
