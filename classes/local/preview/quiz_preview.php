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
 * A kept quiz brings its questions with it, each as the question bank loaded
 * it, and the engine draws them from that. A quiz the run writes brings the
 * questions the answer wrote, in the shape a question's editing form submits
 * them in, which is the shape the plugin creates them from; those are put into
 * the shape the engine builds a question from, and then drawn the same way.
 * The drawing is the engine's in both cases; only the data changes hands.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_preview extends ported_preview {
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
        $view = new view($quiz, $this->cm(), $this->course(), $this->context(), $this->slots(), $this->url_to());
        return $view->page();
    }

    /**
     * The slots, each with its question's data, from whichever side wrote them.
     *
     * @return array
     */
    protected function slots(): array {
        $drafted = $this->parameters['mod_settings']['questions'] ?? null;
        $ismould = (string) ($this->source['uid'] ?? '') === (string) $this->here->get_param('uid');
        if (!$ismould && is_array($drafted) && $drafted) {
            return $this->slots_from_draft($drafted);
        }
        return (array) (($this->source['parameters'] ?? [])['questions'] ?? []);
    }

    /**
     * The answer's questions as slots, their data in the engine's shape.
     *
     * The answer writes a question the way its editing form submits it; the
     * plugin creates the question from exactly that, through the question
     * type's own save_question(). Here the same fields are laid out the way
     * the bank loads a saved question back, which is what the engine builds a
     * question from. Answers and hints are numbered from one, and the marks
     * that decide which answers are right are the fractions the form carries.
     *
     * @param array $drafted
     * @return array
     */
    protected function slots_from_draft(array $drafted): array {
        $slots = [];
        $slotnumber = 0;
        $id = 1;
        foreach ($drafted as $question) {
            $question = (array) $question;
            $slotnumber++;
            $data = quiz_question_data::from_form($question, $id, $this->context()->id);
            $slots[] = [
                'slot' => $slotnumber,
                'page' => $slotnumber,
                'maxmark' => (float) ($question['defaultmark'] ?? 1),
                'displaynumber' => null,
                'question' => $data,
            ];
        }
        return $slots;
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
