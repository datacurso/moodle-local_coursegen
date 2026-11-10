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

use local_coursegen\local\service\create_mod_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/aiprovider_datacurso_stub.php');

/**
 * Nothing the AI writes can store active content in an activity, whatever the activity type.
 *
 * The text returned by the model is untrusted input: a document the AI reads can carry hidden
 * instructions. Each test creates one activity type from an AI result whose text fields all carry
 * a script element, an onerror attribute and a javascript: link (plus a bare javascript: URL where
 * the field holds a URL), and then reads every row the activity stored. The titles that Moodle
 * itself only accepts as plain text (glossary concept, wiki page title, forum subject) are left
 * plain: the core APIs reject markup there.
 *
 * Mindfree, observation 1 of the report of 2026-10-26 (stored XSS in lessons).
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\create_mod_service
 * @covers     \local_coursegen\utils\ai_text_sanitiser
 */
final class ai_text_sanitisation_test extends \advanced_testcase {
    /** @var string Text the AI writes, with the three vectors of the report and some legitimate markup. */
    private const PAYLOAD = '<p>Legitimate <strong>content</strong></p>'
        . '<script>alert("script")</script>'
        . '<img src="https://example.com/a.png" onerror="alert(\'onerror\')">'
        . '<a href="javascript:alert(\'link\')">Read more</a>';

    /**
     * @var string Pattern of anything active that must not be stored.
     *
     * A scheme followed by a space ("javascript :") is not a scheme for a browser, so it is inert.
     */
    private const ACTIVE = '/<\s*script|\bon[a-z]+\s*=|javascript:/i';

    /**
     * Create an activity from an AI result in a new course, as the plugin does.
     *
     * @param string $modname Activity type.
     * @param array $parameters Parameters of the AI result (merged over the common ones).
     * @return \stdClass The course.
     */
    private function create_from_ai(string $modname, array $parameters): \stdClass {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $PAGE->set_course($course);

        $common = [
            'modulename' => $modname,
            'name' => 'Activity ' . self::PAYLOAD,
            'introeditor' => ['text' => self::PAYLOAD, 'format' => FORMAT_HTML, 'itemid' => 0],
            'visible' => 1,
            'cmidnumber' => '',
            'mod_settings' => [],
        ];
        $parameters = array_replace($common, self::module_defaults($modname), $parameters);
        create_mod_service::create_from_ai_result(
            ['resource_type' => $modname, 'parameters' => $parameters],
            $course,
            1
        );
        return $course;
    }

    /**
     * Settings the module itself requires, as the AI service sends them; they carry no text.
     *
     * @param string $modname Activity type.
     * @return array
     */
    private static function module_defaults(string $modname): array {
        $editor = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
        $defaults = [
            'lesson' => ['mediafile' => 0, 'available' => 0, 'deadline' => 0, 'practice' => 0, 'grade' => 100],
            'glossary' => ['displayformat' => 'dictionary', 'assessed' => 0],
            'forum' => ['grade_forum' => 0, 'assessed' => 0],
            'feedback' => ['page_after_submit_editor' => $editor, 'page_after_submit' => '',
                'page_after_submitformat' => FORMAT_HTML],
            'workshop' => ['grade' => 80, 'gradinggrade' => 20, 'instructauthorseditor' => $editor,
                'instructreviewerseditor' => $editor, 'conclusioneditor' => $editor, 'gradecategory' => 0,
                'gradinggradecategory' => 0, 'submissionstart' => 0, 'submissionend' => 0, 'assessmentstart' => 0,
                'assessmentend' => 0],
            'assign' => ['alwaysshowdescription' => 1, 'submissiondrafts' => 0, 'requiresubmissionstatement' => 0,
                'sendnotifications' => 0, 'sendstudentnotifications' => 1, 'sendlatenotifications' => 0, 'duedate' => 0,
                'allowsubmissionsfromdate' => 0, 'grade' => 100, 'cutoffdate' => 0, 'gradingduedate' => 0,
                'teamsubmission' => 0, 'requireallteammemberssubmit' => 0, 'teamsubmissiongroupingid' => 0,
                'blindmarking' => 0, 'attemptreopenmethod' => 'untilpass', 'maxattempts' => 1, 'markingworkflow' => 0,
                'markingallocation' => 0, 'markercount' => 0, 'markinganonymous' => 0, 'activityformat' => 0,
                'timelimit' => 0, 'submissionattachments' => 0],
            'quiz' => ['timeopen' => 0, 'timeclose' => 0, 'preferredbehaviour' => 'deferredfeedback', 'attempts' => 0,
                'attemptonlast' => 0, 'grademethod' => 1, 'decimalpoints' => 2, 'questiondecimalpoints' => -1,
                'attemptduring' => 1, 'correctnessduring' => 1, 'maxmarksduring' => 1, 'marksduring' => 1,
                'specificfeedbackduring' => 1, 'generalfeedbackduring' => 1, 'rightanswerduring' => 1,
                'overallfeedbackduring' => 0, 'attemptimmediately' => 1, 'correctnessimmediately' => 1,
                'maxmarksimmediately' => 1, 'marksimmediately' => 1, 'specificfeedbackimmediately' => 1,
                'generalfeedbackimmediately' => 1, 'rightanswerimmediately' => 1, 'overallfeedbackimmediately' => 1,
                'attemptopen' => 1, 'correctnessopen' => 1, 'maxmarksopen' => 1, 'marksopen' => 1,
                'specificfeedbackopen' => 1, 'generalfeedbackopen' => 1, 'rightansweropen' => 1,
                'overallfeedbackopen' => 1, 'attemptclosed' => 1, 'correctnessclosed' => 1, 'maxmarksclosed' => 1,
                'marksclosed' => 1, 'specificfeedbackclosed' => 1, 'generalfeedbackclosed' => 1,
                'rightanswerclosed' => 1, 'overallfeedbackclosed' => 1, 'questionsperpage' => 1, 'shuffleanswers' => 1,
                'sumgrades' => 0, 'grade' => 10, 'timelimit' => 0, 'overduehandling' => 'autosubmit',
                'graceperiod' => 86400, 'quizpassword' => '', 'subnet' => '', 'browsersecurity' => '', 'delay1' => 0,
                'delay2' => 0, 'showuserpicture' => 0, 'showblocks' => 0, 'navmethod' => 'free'],
        ];
        return $defaults[$modname] ?? [];
    }

    /**
     * Assert that no stored text of the given tables carries active content, and that they hold rows.
     *
     * @param string[] $tables Tables the activity writes the AI text to.
     */
    private function assert_nothing_active_stored(array $tables): void {
        global $DB;

        foreach ($tables as $table) {
            $records = $DB->get_records($table);
            $this->assertNotEmpty($records, "The activity stored nothing in {$table}.");
            foreach ($records as $record) {
                foreach ((array)$record as $field => $value) {
                    if (!is_string($value)) {
                        continue;
                    }
                    $message = "{$table}.{$field} keeps active content.";
                    $this->assertDoesNotMatchRegularExpression(self::ACTIVE, $value, $message);
                }
            }
        }
    }

    /**
     * The common fields that reach add_moduleinfo() directly (name, editor and plain strings) are cleaned.
     */
    public function test_page(): void {
        $this->create_from_ai('page', [
            'page' => ['text' => self::PAYLOAD, 'format' => FORMAT_HTML, 'itemid' => 0],
            'content' => self::PAYLOAD,
            'display' => 5,
            'printintro' => 0,
            'printlastmodified' => 1,
        ]);

        $this->assert_nothing_active_stored(['page']);
    }

    /**
     * Lesson pages, their options and the feedback of each option are cleaned.
     */
    public function test_lesson(): void {
        $this->create_from_ai('lesson', ['mod_settings' => ['pages' => [
            ['page_type' => 'content', 'title' => 'Page ' . self::PAYLOAD, 'content_html' => self::PAYLOAD,
                'button_text' => 'Next ' . self::PAYLOAD],
            ['page_type' => 'multichoice', 'title' => 'Question', 'content_html' => self::PAYLOAD, 'options' => [
                ['text' => self::PAYLOAD, 'correct' => true, 'feedback' => self::PAYLOAD],
                ['text' => 'Wrong ' . self::PAYLOAD, 'correct' => false, 'feedback' => self::PAYLOAD],
            ]],
        ]]]);

        $this->assert_nothing_active_stored(['lesson', 'lesson_pages', 'lesson_answers']);
    }

    /**
     * Book chapters and their titles are cleaned.
     */
    public function test_book(): void {
        $this->create_from_ai('book', ['mod_settings' => ['chapters' => [
            ['title' => 'Chapter ' . self::PAYLOAD, 'subchapter' => 0,
                'content_editor' => ['text' => self::PAYLOAD, 'format' => FORMAT_HTML, 'itemid' => 0]],
        ]]]);

        $this->assert_nothing_active_stored(['book', 'book_chapters']);
    }

    /**
     * Database fields, example entries (a URL field included) and display templates are cleaned.
     */
    public function test_data(): void {
        $this->create_from_ai('data', ['mod_settings' => [
            'fields' => [
                ['type' => 'text', 'name' => 'Title', 'description' => self::PAYLOAD, 'required' => false],
                ['type' => 'textarea', 'name' => 'Body', 'description' => 'Body', 'required' => false],
                ['type' => 'url', 'name' => 'Link', 'description' => 'Link', 'required' => false],
            ],
            'example_entries' => [
                ['values' => [
                    ['field_name' => 'Title', 'value' => 'Entry ' . self::PAYLOAD],
                    ['field_name' => 'Body', 'value' => self::PAYLOAD],
                    ['field_name' => 'Link', 'value' => 'javascript:alert(document.cookie)'],
                ]],
            ],
            'templates' => [
                'listtemplate' => '<div>[[Title]]</div>' . self::PAYLOAD,
                'singletemplate' => '<div>[[Body]]</div>' . self::PAYLOAD,
            ],
        ]]);

        $this->assert_nothing_active_stored(['data', 'data_fields', 'data_content']);
    }

    /**
     * Glossary entries are cleaned.
     */
    public function test_glossary(): void {
        $this->create_from_ai('glossary', ['mod_settings' => ['entries' => [
            ['concept' => 'Concept',
                'definition_editor' => ['text' => self::PAYLOAD, 'format' => FORMAT_HTML, 'itemid' => 0]],
        ]]]);

        $this->assert_nothing_active_stored(['glossary', 'glossary_entries']);
    }

    /**
     * Wiki pages are cleaned.
     */
    public function test_wiki(): void {
        $this->create_from_ai('wiki', [
            'firstpagetitle' => 'First page',
            'wikimode' => 'collaborative',
            'defaultformat' => 'html',
            'mod_settings' => ['pages' => [
                ['title' => 'First page',
                    'newcontent_editor' => ['text' => self::PAYLOAD, 'format' => FORMAT_HTML, 'itemid' => 0]],
                ['title' => 'Second page',
                    'newcontent_editor' => ['text' => self::PAYLOAD, 'format' => FORMAT_HTML, 'itemid' => 0]],
            ]],
        ]);

        $this->assert_nothing_active_stored(['wiki', 'wiki_pages', 'wiki_versions']);
    }

    /**
     * Forum discussions are cleaned.
     */
    public function test_forum(): void {
        $this->create_from_ai('forum', [
            'type' => 'general',
            'mod_settings' => ['discussions' => [
                ['subject' => 'Subject', 'message' => self::PAYLOAD],
            ]],
        ]);

        $this->assert_nothing_active_stored(['forum', 'forum_discussions', 'forum_posts']);
    }

    /**
     * Feedback questions and their options are cleaned.
     */
    public function test_feedback(): void {
        $this->create_from_ai('feedback', ['mod_settings' => ['questions' => [
            ['id' => 0, 'typ' => 'textarea', 'name' => 'Question ' . self::PAYLOAD, 'label' => 'q1',
                'itemwidth' => 30, 'itemheight' => 5],
            ['id' => 0, 'typ' => 'multichoice', 'name' => 'Choose ' . self::PAYLOAD, 'label' => 'q2',
                'subtype' => 'r', 'ignoreempty' => 0, 'hidenoselect' => 1, 'options' => '',
                'values' => "One <b>bold</b>\nTwo " . self::PAYLOAD],
        ]]]);

        $this->assert_nothing_active_stored(['feedback', 'feedback_item']);
    }

    /**
     * Workshop assessment criteria are cleaned.
     */
    public function test_workshop(): void {
        $this->create_from_ai('workshop', ['mod_settings' => ['criteria' => [
            ['description' => self::PAYLOAD, 'max_points' => 10],
        ]]]);

        $this->assert_nothing_active_stored(['workshop', 'workshopform_accumulative']);
    }

    /**
     * Assignment rubrics are cleaned.
     */
    public function test_assign(): void {
        $this->create_from_ai('assign', ['mod_settings' => ['rubric' => [
            'name' => 'Rubric ' . self::PAYLOAD,
            'criteria' => [
                ['description' => self::PAYLOAD, 'levels' => [
                    ['definition' => self::PAYLOAD, 'points' => 0],
                    ['definition' => 'Good ' . self::PAYLOAD, 'points' => 5],
                ]],
            ],
        ]]]);

        $this->assert_nothing_active_stored(['assign', 'grading_definitions', 'gradingform_rubric_criteria',
            'gradingform_rubric_levels']);
    }

    /**
     * Quiz questions, their answers and feedback are cleaned.
     */
    public function test_quiz(): void {
        $editor = ['text' => self::PAYLOAD, 'format' => FORMAT_HTML];
        $this->create_from_ai('quiz', ['mod_settings' => ['questions' => [
            [
                'qtype' => 'multichoice',
                'name' => 'Question ' . self::PAYLOAD,
                'questiontext' => $editor,
                'generalfeedback' => $editor,
                'defaultmark' => 1,
                'penalty' => 0.3333333,
                'single' => 1,
                'shuffleanswers' => 1,
                'answernumbering' => 'abc',
                'answer' => [$editor, ['text' => 'Wrong ' . self::PAYLOAD, 'format' => FORMAT_HTML]],
                'fraction' => [1, 0],
                'feedback' => [$editor, $editor],
                'correctfeedback' => $editor,
                'partiallycorrectfeedback' => $editor,
                'incorrectfeedback' => $editor,
            ],
        ]]]);

        $this->assert_nothing_active_stored(['quiz', 'question', 'question_answers', 'qtype_multichoice_options']);
    }
}
