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

/**
 * Class quiz_settings
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_settings extends base_settings {
    /**
     * Add specific settings for book module.
     */
    public function add_settings() {
        foreach ($this->modsettings['questions'] as $question) {
            $this->add_question($question);
        }
    }

    /**
     * Question types created through the import-style path instead of save_question().
     *
     * For calculated types, save_question() is a multi-page wizard: called outside
     * the web form flow it creates the dataset DEFINITIONS but never the dataset
     * ITEMS (values), leaving the question unusable ('cannotgetdsfordependent').
     */
    const CALCULATED_QTYPES = ['calculated', 'calculatedmulti'];

    /**
     * Add question to quiz.
     *
     * @param array $aiquestiondata Question data.
     */
    protected function add_question($aiquestiondata) {
        global $DB, $USER;
        $cm = $this->cm;
        $context = \context_module::instance($cm->coursemodule);
        require_capability('moodle/question:add', $context);

        $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

        $categoryinfo = question_make_default_categories([$context]);

        if (in_array($aiquestiondata['qtype'], self::CALCULATED_QTYPES, true)) {
            try {
                $question = $this->add_calculated_question($aiquestiondata, $categoryinfo, $context);
            } catch (\Throwable $exception) {
                // A bad formula raises inside Moodle's validation; skip this question
                // (the transaction already rolled back) without failing the whole quiz.
                debugging(
                    'coursegen: could not create ' . $aiquestiondata['qtype'] . ' question "'
                        . ($aiquestiondata['name'] ?? '') . '": ' . $exception->getMessage(),
                    DEBUG_DEVELOPER
                );
                return;
            }
        } else {
            $category = "{$categoryinfo->id},{$categoryinfo->contextid}";
            $aiquestiondata['category'] = $category;

            $questionrecord = new \stdClass();
            $questionrecord->category = $categoryinfo->id;
            $questionrecord->qtype = $aiquestiondata['qtype'];
            $questionrecord->createdby = $USER->id;

            $qtypeobj = \question_bank::get_qtype($aiquestiondata['qtype']);

            $question = $qtypeobj->save_question($questionrecord, (object)$aiquestiondata);
        }

        // Purge this question from the cache.
        \question_bank::notify_question_edited($question->id);

        require_capability('mod/quiz:manage', $context);

        [$quiz, $cm] = get_module_from_cmid($cm->coursemodule);

        // Get the course object and related bits.
        $course = get_course($cm->course);
        $quizobj = new \mod_quiz\quiz_settings($quiz, $cm, $course);
        $structure = $quizobj->get_structure();
        $gradecalculator = $quizobj->get_grade_calculator();

        // Add a single question to the current quiz.
        $structure->check_can_be_edited();
        quiz_require_question_use($question->id);
        $addonpage = optional_param('addonpage', 0, PARAM_INT);
        quiz_add_quiz_question($question->id, $quiz, $addonpage);
        quiz_delete_previews($quiz);
        $gradecalculator->recompute_quiz_sumgrades();
    }

    /**
     * Create a calculated/calculatedmulti question through the import-style path.
     *
     * Replicates question/format.php::importprocess(): inserts the question row,
     * its bank entry and version, then calls save_question_options() directly with
     * import_process=true so qtype_calculated::import_datasets() creates the dataset
     * definitions AND their items in one pass.
     *
     * @param array $aiquestiondata Question payload from the AI service.
     * @param \stdClass $categoryinfo Default question category for the quiz context.
     * @param \context_module $context Module context.
     * @return \stdClass The created question record (with id).
     */
    protected function add_calculated_question($aiquestiondata, $categoryinfo, $context) {
        global $DB, $USER;

        $transaction = $DB->start_delegated_transaction();
        try {
            $question = new \stdClass();
            $question->category = $categoryinfo->id;
            $question->qtype = $aiquestiondata['qtype'];
            $question->name = $aiquestiondata['name'];
            $question->questiontext = $aiquestiondata['questiontext']['text'] ?? '';
            $question->questiontextformat = $aiquestiondata['questiontext']['format'] ?? FORMAT_HTML;
            $question->generalfeedback = $aiquestiondata['generalfeedback']['text'] ?? '';
            $question->generalfeedbackformat = $aiquestiondata['generalfeedback']['format'] ?? FORMAT_HTML;
            $question->defaultmark = $aiquestiondata['defaultmark'] ?? 1;
            $question->penalty = $aiquestiondata['penalty'] ?? 0.3333333;
            $question->length = 1;
            $question->stamp = make_unique_id_code();
            $question->createdby = $USER->id;
            $question->modifiedby = $USER->id;
            $question->timecreated = time();
            $question->timemodified = time();
            $question->id = $DB->insert_record('question', $question);

            $bankentry = new \stdClass();
            $bankentry->questioncategoryid = $categoryinfo->id;
            $bankentry->idnumber = null;
            $bankentry->ownerid = $USER->id;
            $bankentry->id = $DB->insert_record('question_bank_entries', $bankentry);

            $version = new \stdClass();
            $version->questionbankentryid = $bankentry->id;
            $version->questionid = $question->id;
            $version->version = 1;
            $version->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
            $DB->insert_record('question_versions', $version);

            // Options payload in the shape save_question_options() expects on the
            // import path: flat question text/feedback strings, per-answer arrays as
            // sent by the service, and dataset definitions+items cast to objects.
            $data = (object) $aiquestiondata;
            $data->id = $question->id;
            $data->category = $categoryinfo->id;
            $data->context = $context;
            $data->import_process = true;
            $data->questiontext = $question->questiontext;
            $data->questiontextformat = $question->questiontextformat;
            $data->generalfeedback = $question->generalfeedback;
            $data->generalfeedbackformat = $question->generalfeedbackformat;

            $datasets = [];
            foreach (($aiquestiondata['dataset'] ?? []) as $datasetdata) {
                $dataset = (object) $datasetdata;
                $items = [];
                foreach (($datasetdata['datasetitem'] ?? []) as $itemdata) {
                    $items[] = (object) $itemdata;
                }
                if (count($items) === 0) {
                    // Without items the question always fails at attempt time
                    // ('cannotgetdsfordependent'); better to skip it whole.
                    throw new \coding_exception('Dataset "' . ($datasetdata['name'] ?? '?')
                        . '" has no items; the calculated question cannot work.');
                }
                // import_datasets() only inserts items when status is exactly
                // 'private' or 'shared', and the attempt runtime picks the variant
                // range from MIN(itemcount): neither can be trusted to the service.
                $dataset->status = 'private';
                $dataset->itemcount = count($items);
                $dataset->datasetitem = $items;
                $datasets[] = $dataset;
            }
            $data->dataset = $datasets;

            \question_bank::get_qtype($question->qtype)->save_question_options($data);

            // Keep parity with importprocess(): observers and logs must see
            // these questions like any imported one.
            \core\event\question_created::create_from_question_instance($question, $context)->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            // Rolls back this question's rows and rethrows for the caller to skip it.
            $transaction->rollback($exception);
        }

        return $question;
    }
}
