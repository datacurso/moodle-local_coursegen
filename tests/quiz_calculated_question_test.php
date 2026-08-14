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

use local_coursegen\mod_settings\quiz_settings;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/question/editlib.php');
require_once($CFG->dirroot . '/question/type/numerical/questiontype.php');

/**
 * Tests for calculated question creation through the import-style path.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\mod_settings\quiz_settings
 */
final class quiz_calculated_question_test extends \advanced_testcase {
    /**
     * Create a course + quiz and return the cm object quiz_settings expects.
     *
     * @return \stdClass cm-like object with coursemodule and instance.
     */
    private function create_quiz_cm(): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);

        return (object) [
            'coursemodule' => $quiz->cmid,
            'instance' => $quiz->id,
        ];
    }

    /**
     * Build a calculated question payload as sent by the AI service.
     *
     * @param array $datasetoverrides Overrides merged into each dataset definition.
     * @return array Question payload.
     */
    private function calculated_question_payload(array $datasetoverrides = []): array {
        $dataset = static function (string $name) use ($datasetoverrides): array {
            return array_merge([
                'name' => $name,
                'distribution' => 'uniform',
                'min' => 1,
                'max' => 10,
                'length' => 1,
                // Deliberately wrong: the service cannot be trusted on this.
                'itemcount' => 99,
                // No 'status' key: the service does not send one.
                'datasetitem' => [
                    ['itemnumber' => 1, 'value' => 3.0],
                    ['itemnumber' => 2, 'value' => 5.0],
                ],
            ], $datasetoverrides);
        };

        return [
            'qtype' => 'calculated',
            'name' => 'AI calculated sum',
            'questiontext' => ['text' => 'How much is {a} + {b}?', 'format' => FORMAT_HTML],
            'generalfeedback' => ['text' => '', 'format' => FORMAT_HTML],
            'defaultmark' => 1,
            'penalty' => 0.3333333,
            'synchronize' => 0,
            'answernumbering' => 'abc',
            'shuffleanswers' => 1,
            'showunits' => \qtype_numerical::UNITNONE,
            'unitpenalty' => 0.1,
            'unitgradingtype' => 0,
            'answer' => ['{a} + {b}'],
            'fraction' => ['1.0'],
            'tolerance' => ['0.01'],
            'tolerancetype' => ['1'],
            'correctanswerlength' => ['2'],
            'correctanswerformat' => ['1'],
            'feedback' => [['text' => 'Correct!', 'format' => FORMAT_HTML]],
            'dataset' => [$dataset('a'), $dataset('b')],
        ];
    }

    /**
     * The import path must create private dataset definitions with the REAL
     * item count and all dataset items, regardless of the service payload.
     */
    public function test_creates_usable_calculated_question(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $cm = $this->create_quiz_cm();
        $settings = new quiz_settings($cm, ['questions' => [$this->calculated_question_payload()]]);

        $sink = $this->redirectEvents();
        $settings->add_settings();
        $events = $sink->get_events();
        $sink->close();

        // The question exists and is attached to the quiz.
        $question = $DB->get_record('question', ['name' => 'AI calculated sum'], '*', MUST_EXIST);
        $this->assertSame('calculated', $question->qtype);

        // Both dataset definitions were created as private, with the real item count.
        $definitions = $DB->get_records_sql("
            SELECT qdd.*
              FROM {question_dataset_definitions} qdd
              JOIN {question_datasets} qd ON qd.datasetdefinition = qdd.id
             WHERE qd.question = ?", [$question->id]);
        $this->assertCount(2, $definitions);
        foreach ($definitions as $definition) {
            $this->assertEquals(0, $definition->category, 'Dataset must be private (category 0).');
            $this->assertEquals(2, $definition->itemcount, 'Item count must match the real items.');
            $this->assertEquals(2, $DB->count_records(
                'question_dataset_items',
                ['definition' => $definition->id]
            ));
        }

        // The question_created event was triggered, as importprocess() does.
        $created = array_filter($events, static function ($event) {
            return $event instanceof \core\event\question_created;
        });
        $this->assertCount(1, $created);
        $this->assertEquals($question->id, reset($created)->objectid);
    }

    /**
     * A dataset without items can never produce a working question: the whole
     * question must be skipped (with a debugging notice), not half-created.
     */
    public function test_skips_question_when_dataset_has_no_items(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $cm = $this->create_quiz_cm();
        $payload = $this->calculated_question_payload(['datasetitem' => []]);
        $settings = new quiz_settings($cm, ['questions' => [$payload]]);

        $settings->add_settings();
        $this->assertDebuggingCalledCount(1);

        $this->assertFalse($DB->record_exists('question', ['name' => 'AI calculated sum']));
    }
}
