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
use local_coursegen\local\service\guideline_activity_settings;

/**
 * Hidden state and completion rules the guideline asked for are applied to the created activities.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\guideline_activity_settings
 * @covers     \local_coursegen\local\service\guideline_cm_map
 */
final class guideline_activity_settings_test extends \advanced_testcase {
    /**
     * Create a module with the generator and describe it as the service's entry.
     *
     * @param \stdClass $course
     * @param string $modname
     * @param string $uid
     * @param array $parameters The guideline keys of the entry.
     * @return array The generated activity entry and the course module row.
     */
    private function activity(\stdClass $course, string $modname, string $uid, array $parameters): array {
        $instance = $this->getDataGenerator()->create_module($modname, ['course' => $course->id, 'section' => 1]);
        $entry = [
            'uid' => $uid,
            'cmid' => -abs(crc32($uid)),
            'resource_type' => $modname,
            'parameters' => $parameters + ['name' => $uid, 'section' => 1],
        ];
        return [$entry, get_coursemodule_from_instance($modname, $instance->id)];
    }

    /**
     * Run the settings over entries built with activity().
     *
     * @param \stdClass $course
     * @param array[] $pairs Entry and cm pairs.
     * @return void
     */
    private function run_settings(\stdClass $course, array $pairs): void {
        $entries = array_column($pairs, 0);
        $map = [];
        foreach ($pairs as [$entry, $cm]) {
            $map[$entry['cmid']] = (int) $cm->id;
        }
        guideline_activity_settings::apply((int) $course->id, $entries, $map);
    }

    /**
     * Read a course module row fresh from the database.
     *
     * @param \stdClass $cm
     * @return \stdClass
     */
    private function cm_row(\stdClass $cm): \stdClass {
        global $DB;
        return $DB->get_record('course_modules', ['id' => $cm->id], '*', MUST_EXIST);
    }

    /**
     * The rule of each module type writes the matching Moodle 4.5 completion fields.
     */
    public function test_rules_write_the_module_completion_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 0]);
        $page = $this->activity($course, 'page', 'p', ['completion_rule' => 'view', 'visible' => true]);
        $quiz = $this->activity($course, 'quiz', 'q', ['completion_rule' => 'grade']);
        $assign = $this->activity($course, 'assign', 'a', ['completion_rule' => 'submit']);
        $forum = $this->activity($course, 'forum', 'f', ['completion_rule' => 'post']);
        $this->run_settings($course, [$page, $quiz, $assign, $forum]);

        $this->assertEquals(1, $DB->get_field('course', 'enablecompletion', ['id' => $course->id]));
        $row = $this->cm_row($page[1]);
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, $row->completion);
        $this->assertEquals(1, $row->completionview);
        $row = $this->cm_row($quiz[1]);
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, $row->completion);
        $this->assertEquals(0, $row->completiongradeitemnumber);
        $row = $this->cm_row($assign[1]);
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, $row->completion);
        $this->assertEquals(1, $DB->get_field('assign', 'completionsubmit', ['id' => $assign[1]->instance]));
        $row = $this->cm_row($forum[1]);
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, $row->completion);
        $this->assertEquals(1, $DB->get_field('forum', 'completionposts', ['id' => $forum[1]->instance]));
    }

    /**
     * A hidden activity ends hidden and the course cache reflects it.
     */
    public function test_visible_false_hides_the_activity(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $hidden = $this->activity($course, 'page', 'h', ['visible' => false]);
        $shown = $this->activity($course, 'page', 's', ['visible' => true]);
        $this->run_settings($course, [$hidden, $shown]);

        $this->assertEquals(0, $this->cm_row($hidden[1])->visible);
        $this->assertEquals(1, $this->cm_row($shown[1])->visible);
        $modinfo = get_fast_modinfo($course->id);
        $this->assertEquals(0, $modinfo->get_cm($hidden[1]->id)->visible);
        $this->assertEquals(1, $modinfo->get_cm($shown[1]->id)->visible);
    }

    /**
     * Entries with no key, or the rule none, are left exactly as created.
     */
    public function test_entries_without_keys_are_untouched(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 0]);
        $plain = $this->activity($course, 'quiz', 'plain', []);
        $none = $this->activity($course, 'page', 'none', ['completion_rule' => 'none', 'visible' => true]);
        $before = $this->cm_row($plain[1]);
        $this->run_settings($course, [$plain, $none]);

        $this->assertEquals($before, $this->cm_row($plain[1]));
        $this->assertEquals(COMPLETION_TRACKING_NONE, $this->cm_row($none[1])->completion);
        $this->assertEquals(0, get_course($course->id)->enablecompletion);
    }

    /**
     * A rule the module cannot honour is skipped, and an unknown rule is ignored.
     */
    public function test_unsupported_rules_are_skipped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $label = $this->activity($course, 'label', 'l', ['completion_rule' => 'view']);
        $page = $this->activity($course, 'page', 'p', ['completion_rule' => 'post']);
        $odd = $this->activity($course, 'page', 'o', ['completion_rule' => 'bogus']);
        $this->run_settings($course, [$label, $page, $odd]);
        $this->assertDebuggingCalledCount(2);

        foreach ([$label, $page, $odd] as $pair) {
            $this->assertEquals(COMPLETION_TRACKING_NONE, $this->cm_row($pair[1])->completion);
        }
    }

    /**
     * A graded rule on a module without a grade item falls back to tracking the view.
     */
    public function test_grade_without_grade_item_falls_back_to_view(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->activity($course, 'page', 'p', ['completion_rule' => 'grade']);
        $this->run_settings($course, [$page]);
        $this->assertDebuggingCalled();

        $row = $this->cm_row($page[1]);
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, $row->completion);
        $this->assertEquals(1, $row->completionview);
    }

    /**
     * An activity that was never created is skipped without error.
     */
    public function test_unmapped_activity_is_skipped(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $entry = ['uid' => 'x', 'cmid' => -5, 'parameters' => ['visible' => false, 'completion_rule' => 'view']];
        guideline_activity_settings::apply((int) $course->id, [$entry], []);
        $this->assertTrue(true);
    }

    /**
     * The creation path accepts the new keys in the parameters of a result.
     */
    public function test_creation_accepts_the_guideline_keys(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $PAGE->set_course($course);
        $result = [
            'resource_type' => 'label',
            'cmid' => -3,
            'parameters' => [
                'modulename' => 'label',
                'name' => 'Hidden label',
                'section' => 1,
                'introeditor' => ['text' => '<p>x</p>', 'format' => FORMAT_HTML],
                'visible' => false,
                'completion_rule' => 'view',
                'mod_settings' => [],
            ],
        ];
        $newcm = create_mod_service::create_from_ai_result($result, $course, 1);
        $this->assertEquals(0, $DB->get_field('course_modules', 'visible', ['id' => $newcm->coursemodule]));
    }
}
