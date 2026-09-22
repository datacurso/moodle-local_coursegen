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
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Unit tests for quiz_settings - where each generated question lands.
 *
 * A mold's slot carries two things the question itself does not: the page it
 * sits on and the mark it is worth. Both had no way in: the mark was never
 * passed to quiz_add_quiz_question() at all, and the page came from an HTTP
 * request parameter, which is always absent outside the web flow.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\mod_settings\quiz_settings
 */
final class quiz_settings_test extends \advanced_testcase {
    /**
     * Create a quiz and return it with the cm-like object create_mod_service passes.
     *
     * @param array $options Module generator options.
     * @return array [quiz instance, cm-like object]
     */
    private function make_quiz_cm(array $options = []): array {
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', $options + ['course' => $course->id]);

        return [$quiz, (object) ['coursemodule' => $quiz->cmid, 'instance' => $quiz->id]];
    }

    /**
     * One truefalse question payload, in the shape the service sends.
     *
     * @param string $name The question name.
     * @param array $extra Extra keys merged into the payload (page, maxmark, ...).
     * @return array
     */
    private function truefalse_payload(string $name, array $extra = []): array {
        return array_merge([
            'qtype' => 'truefalse',
            'name' => $name,
            'questiontext' => ['text' => 'Es verdadero.', 'format' => FORMAT_HTML],
            'generalfeedback' => ['text' => '', 'format' => FORMAT_HTML],
            'defaultmark' => 2,
            'penalty' => 1,
            'correctanswer' => 1,
            'feedbacktrue' => ['text' => 'Bien', 'format' => FORMAT_HTML],
            'feedbackfalse' => ['text' => 'Mal', 'format' => FORMAT_HTML],
            'showstandardinstruction' => 1,
        ], $extra);
    }

    /**
     * The quiz's slots, in slot order.
     *
     * @param int $quizid The quiz instance id.
     * @return array quiz_slots rows.
     */
    private function slots(int $quizid): array {
        global $DB;

        return array_values($DB->get_records(
            'quiz_slots',
            ['quizid' => $quizid],
            'slot ASC',
            'id, slot, page, maxmark'
        ));
    }

    /**
     * A payload carrying page and maxmark places the slot exactly there.
     */
    public function test_slot_keeps_the_page_and_mark_the_payload_carries(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        [$quiz, $cm] = $this->make_quiz_cm(['questionsperpage' => 0]);
        $settings = new quiz_settings($cm, ['questions' => [
            $this->truefalse_payload('Primera', ['page' => 1, 'maxmark' => 4]),
            $this->truefalse_payload('Segunda', ['page' => 2, 'maxmark' => 6]),
            $this->truefalse_payload('Tercera', ['page' => 2, 'maxmark' => 8]),
        ]]);

        $settings->add_settings();
        $slots = $this->slots((int) $quiz->id);

        $this->assertCount(3, $slots);
        $this->assertSame([1, 2, 2], array_map('intval', array_column($slots, 'page')));
        // Without the fourth argument every slot inherited question.defaultmark.
        $this->assertSame([4.0, 6.0, 8.0], array_map('floatval', array_column($slots, 'maxmark')));
        // Function recompute_quiz_sumgrades() still runs, so the total follows the marks.
        $this->assertSame(18.0, (float) $DB->get_field('quiz', 'sumgrades', ['id' => $quiz->id]));
    }

    /**
     * A payload with neither key keeps the behaviour the model-driven path has
     * always had: append at the end, on the page questionsperpage dictates,
     * worth the question's own default mark.
     */
    public function test_payload_without_page_or_mark_keeps_the_previous_behaviour(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$quiz, $cm] = $this->make_quiz_cm(['questionsperpage' => 1]);
        $settings = new quiz_settings($cm, ['questions' => [
            $this->truefalse_payload('Primera'),
            $this->truefalse_payload('Segunda'),
        ]]);

        $settings->add_settings();
        $slots = $this->slots((int) $quiz->id);

        $this->assertCount(2, $slots);
        $this->assertSame([1, 2], array_map('intval', array_column($slots, 'page')));
        $this->assertSame([2.0, 2.0], array_map('floatval', array_column($slots, 'maxmark')));
    }

    /**
     * The two authored slot columns reach the slot they belong to.
     *
     * Neither is an argument of quiz_add_quiz_question(), so both are applied
     * afterwards through the mod_quiz\structure setters that own them.
     */
    public function test_slot_display_number_and_dependency_reach_the_slot(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$quiz, $cm] = $this->make_quiz_cm(['questionsperpage' => 0]);
        $settings = new quiz_settings($cm, ['questions' => [
            $this->truefalse_payload('Primera', ['page' => 1]),
            $this->truefalse_payload('Segunda', [
                'page' => 2,
                'displaynumber' => 'A1',
                'requireprevious' => 1,
            ]),
        ]]);

        $settings->add_settings();
        $slots = $this->slot_columns((int) $quiz->id);

        // The question that carries neither key keeps mod_quiz's own defaults:
        // automatic numbering and no dependency.
        $this->assertNull($slots[0]->displaynumber);
        $this->assertSame(0, (int) $slots[0]->requireprevious);
        $this->assertSame('A1', $slots[1]->displaynumber);
        $this->assertSame(1, (int) $slots[1]->requireprevious);
    }

    /**
     * A payload's question tags are re-applied to the created question.
     *
     * The tags are authored classification of the mold's bank; without this the
     * generated questions land untagged.
     */
    public function test_question_tags_reach_the_created_question(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        [, $cm] = $this->make_quiz_cm();
        $settings = new quiz_settings($cm, ['questions' => [
            $this->truefalse_payload('Etiquetada', ['tags' => ['Álgebra', 'Unidad 1']]),
        ]]);

        $settings->add_settings();

        $questionid = (int) $DB->get_field('question', 'id', ['name' => 'Etiquetada'], MUST_EXIST);
        $tags = [];
        foreach (\core_tag_tag::get_item_tags('core_question', 'question', $questionid) as $tag) {
            $tags[] = $tag->rawname;
        }

        $this->assertSame(['Álgebra', 'Unidad 1'], $tags);
    }

    /**
     * A question with no tags key creates no tag instance at all.
     */
    public function test_question_without_tags_creates_no_tag_instance(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        [, $cm] = $this->make_quiz_cm();
        $settings = new quiz_settings($cm, ['questions' => [$this->truefalse_payload('Sin etiquetas')]]);

        $settings->add_settings();

        $questionid = (int) $DB->get_field('question', 'id', ['name' => 'Sin etiquetas'], MUST_EXIST);

        $this->assertSame([], \core_tag_tag::get_item_tags('core_question', 'question', $questionid));
    }

    /**
     * The mold's sections are rebuilt, and the default one is updated in place.
     *
     * Every quiz is created with one section starting at slot 1
     * (mod/quiz/lib.php), so creating the mold's first section instead of
     * updating that one would hit the unique (quizid, firstslot) index.
     */
    public function test_sections_update_the_default_one_instead_of_duplicating_it(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        [$quiz, $cm] = $this->make_quiz_cm(['questionsperpage' => 0]);
        $settings = new quiz_settings($cm, [
            'questions' => [
                $this->truefalse_payload('Primera', ['page' => 1]),
                $this->truefalse_payload('Segunda', ['page' => 2]),
            ],
            'sections' => [
                ['heading' => 'Primera parte', 'shufflequestions' => 1, 'firstslot' => 1],
                ['heading' => 'Segunda parte', 'shufflequestions' => 0, 'firstslot' => 2],
            ],
        ]);

        $settings->add_settings();
        $sections = array_values($DB->get_records('quiz_sections', ['quizid' => $quiz->id], 'firstslot ASC'));

        $this->assertCount(2, $sections);
        $this->assertSame([1, 2], array_map('intval', array_column($sections, 'firstslot')));
        $this->assertSame(['Primera parte', 'Segunda parte'], array_column($sections, 'heading'));
        $this->assertSame([1, 0], array_map('intval', array_column($sections, 'shufflequestions')));
    }

    /**
     * A payload with no sections key leaves the default section exactly as
     * mod_quiz created it.
     */
    public function test_payload_without_sections_leaves_the_default_section_untouched(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        [$quiz, $cm] = $this->make_quiz_cm(['questionsperpage' => 0]);
        $settings = new quiz_settings($cm, ['questions' => [$this->truefalse_payload('Unica')]]);

        $settings->add_settings();
        $sections = array_values($DB->get_records('quiz_sections', ['quizid' => $quiz->id], 'firstslot ASC'));

        $this->assertCount(1, $sections);
        $this->assertSame(1, (int) $sections[0]->firstslot);
        $this->assertSame('', (string) $sections[0]->heading);
        $this->assertSame(0, (int) $sections[0]->shufflequestions);
    }

    /**
     * A section starting beyond the last slot is dropped instead of breaking
     * the generated quiz: mod_quiz cannot render a section with no slots.
     */
    public function test_a_section_pointing_past_the_last_slot_is_dropped(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        [$quiz, $cm] = $this->make_quiz_cm(['questionsperpage' => 0]);
        $settings = new quiz_settings($cm, [
            'questions' => [$this->truefalse_payload('Unica', ['page' => 1])],
            'sections' => [
                ['heading' => 'Primera parte', 'shufflequestions' => 0, 'firstslot' => 1],
                ['heading' => 'Inalcanzable', 'shufflequestions' => 0, 'firstslot' => 7],
            ],
        ]);

        $settings->add_settings();
        $sections = array_values($DB->get_records('quiz_sections', ['quizid' => $quiz->id], 'firstslot ASC'));

        $this->assertCount(1, $sections);
        $this->assertSame('Primera parte', $sections[0]->heading);
    }

    /**
     * The slot rows of one quiz, in slot order, with their authored columns.
     *
     * @param int $quizid The quiz instance id.
     * @return array quiz_slots rows.
     */
    private function slot_columns(int $quizid): array {
        global $DB;

        return array_values($DB->get_records(
            'quiz_slots',
            ['quizid' => $quizid],
            'slot ASC',
            'id, slot, displaynumber, requireprevious'
        ));
    }

    /**
     * Each key stands on its own: a payload with only one of them still
     * falls back for the other.
     */
    public function test_page_and_mark_fall_back_independently(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$quiz, $cm] = $this->make_quiz_cm(['questionsperpage' => 0]);
        $settings = new quiz_settings($cm, ['questions' => [
            $this->truefalse_payload('Solo marca', ['maxmark' => 5]),
            $this->truefalse_payload('Solo pagina', ['page' => 2]),
        ]]);

        $settings->add_settings();
        $slots = $this->slots((int) $quiz->id);

        $this->assertSame([1, 2], array_map('intval', array_column($slots, 'page')));
        $this->assertSame([5.0, 2.0], array_map('floatval', array_column($slots, 'maxmark')));
    }
}
