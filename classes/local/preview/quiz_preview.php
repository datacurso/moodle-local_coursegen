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

namespace local_coursegen\local\preview;

use local_coursegen\local\preview\quiz\view;

/**
 * A quiz, drawn by mod_quiz's own view code and the question engine, against the payload.
 *
 * Every quiz brings its questions with it, each as the question bank loads
 * it, and the engine draws them from that. For a quiz the run writes, the
 * questions are the template's with what the AI wrote laid into the rows of
 * the question it came from.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_preview extends preview_base {
    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'quiz';
    }

    /**
     * The quiz page, as mod/quiz/view.php draws it, followed by its questions.
     *
     * @return string
     */
    public function render(): string {
        $quiz = $this->instance();
        if ($quiz === null) {
            return $this->nothing_yet();
        }
        $cm = $this->cm();
        $course = $this->course();
        $context = $this->context();
        $slots = $this->slots();
        $here = $this->url_to();
        $view = new view($quiz, $cm, $course, $context, $slots, $here);
        return $view->page();
    }

    /**
     * The slots, each with its question's data, as the result carries them.
     *
     * The questions are the ones in the result's own `questions`: the bank
     * rows of every question, with what the AI wrote laid into the row of
     * the question it came from. That is the one place a quiz's questions
     * are read from; the same questions as the editing form takes them
     * (mod_settings.questions) are what creates the quiz, not what shows it.
     * A quiz's tree holds only its description, name and settings.
     *
     * @return array
     */
    protected function slots(): array {
        $questions = $this->parameters['questions'] ?? [];
        return (array) $questions;
    }

    /**
     * This module reads its own page at the narrower width (mod/quiz/view.php).
     *
     * @return bool
     */
    public function limited_width(): bool {
        return true;
    }
}
