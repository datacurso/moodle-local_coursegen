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
     * Every question of the result is drawn from its own bank row, the texts the AI wrote included.
     */
    public function test_every_question_of_the_result_shows_its_own_text_and_answers(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();
        $parameters = $this->service_activity('quiz')['parameters'];

        $html = $this->text_of($this->preview_of('quiz', $parameters)->render());

        foreach ($parameters['questions'] as $slot) {
            $question = $slot['question'];
            $this->assertStringContainsString($question['questiontext'], $html);
            $this->assertStringNotContainsString('[[coursegen:', $html);
        }
        $this->assertStringContainsString($parameters['questions'][0]['question']['options']['answers'][array_key_first(
            $parameters['questions'][0]['question']['options']['answers'])]['answer'], $html);
    }

    /**
     * The questions are read from the result's own questions, not from the form the plugin creates them from.
     */
    public function test_the_questions_of_the_form_are_not_what_is_drawn(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();
        $parameters = $this->service_activity('quiz')['parameters'];
        $parameters['mod_settings']['questions'][0]['questiontext'] = ['text' => 'Only in the form', 'format' => 1];

        $html = $this->text_of($this->preview_of('quiz', $parameters)->render());

        $this->assertStringNotContainsString('Only in the form', $html);
    }

    /**
     * A quiz without questions does not fail.
     */
    public function test_a_quiz_without_questions_does_not_fail(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();
        $parameters = $this->service_activity('quiz')['parameters'];
        $parameters['questions'] = [];

        $html = $this->preview_of('quiz', $parameters)->render();

        $this->assertIsString($html);
    }
}
