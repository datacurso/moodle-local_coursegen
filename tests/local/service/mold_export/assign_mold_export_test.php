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

namespace local_coursegen\local\service\mold_export;

/**
 * Unit tests for assign_mold_export — settings, editors, flattened plugin config, grade pass and rubric.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\local\service\mold_export\assign_mold_export
 */
final class assign_mold_export_test extends \advanced_testcase {
    /**
     * Create an assignment with submission/feedback plugin settings and return its cm_info.
     *
     * @return \cm_info
     */
    private function make_assign(): \cm_info {
        global $DB;
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'name' => 'Mold assign', 'intro' => '<p>Task</p>', 'introformat' => FORMAT_HTML,
            'duedate' => 1800000000, 'grade' => 100, 'submissiondrafts' => 1, 'maxattempts' => 3,
            'attemptreopenmethod' => 'manual', 'markingworkflow' => 1, 'sendstudentnotifications' => 0,
            'assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_onlinetext_wordlimit' => 500,
            'assignsubmission_onlinetext_wordlimit_enabled' => 1,
            'assignsubmission_file_enabled' => 1, 'assignsubmission_file_maxfiles' => 2,
            'assignsubmission_file_maxsizebytes' => 1048576, 'assignsubmission_file_filetypes' => '.pdf,.docx',
            'assignfeedback_comments_enabled' => 1, 'assignfeedback_comments_commentinline' => 1,
            'assignfeedback_editpdf_enabled' => 0,
            'completion' => 2, 'completionsubmit' => 1,
        ]);
        // The generator only accepts the activity text through a draft editor; write the columns directly.
        $DB->set_field('assign', 'activity', '<p>Steps @@PLUGINFILE@@/s.png</p>', ['id' => $assign->id]);
        $DB->set_field('assign', 'activityformat', FORMAT_HTML, ['id' => $assign->id]);
        $DB->set_field('grade_items', 'gradepass', 55, [
            'itemmodule' => 'assign', 'iteminstance' => $assign->id, 'itemnumber' => 0,
        ]);
        return get_fast_modinfo($course->id)->get_cm($assign->cmid);
    }

    /**
     * Plugin config rows are flattened with the mod_form names; gradepass and the grading method are read from their tables.
     */
    public function test_exports_settings_editors_and_plugin_config(): void {
        $this->resetAfterTest();
        $cm = $this->make_assign();
        $context = \context_module::instance($cm->id);

        $result = assign_mold_export::export($cm);

        $this->assertSame(['text' => '<p>Task</p>', 'format' => 1], $result['introeditor']);
        $this->assertSame(1, $result['activityeditor']['format']);
        $this->assertStringContainsString(
            '/pluginfile.php/' . $context->id . '/mod_assign/activityattachment/',
            $result['activityeditor']['text']
        );
        $this->assertEquals(1800000000, $result['duedate']);
        $this->assertEquals(100, $result['grade']);
        $this->assertEquals(1, $result['submissiondrafts']);
        $this->assertEquals(3, $result['maxattempts']);
        $this->assertSame('manual', $result['attemptreopenmethod']);
        $this->assertEquals(1, $result['markingworkflow']);
        $this->assertEquals(1, $result['completionsubmit']);
        foreach (['alwaysshowdescription', 'allowsubmissionsfromdate', 'cutoffdate', 'gradingduedate',
            'requiresubmissionstatement', 'teamsubmission', 'requireallteammemberssubmit', 'preventsubmissionnotingroup',
            'blindmarking', 'hidegrader', 'markingallocation', 'markinganonymous', 'sendnotifications',
            'sendlatenotifications', 'sendstudentnotifications'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertEquals(1, $result['assignsubmission_onlinetext_enabled']);
        $this->assertEquals(500, $result['assignsubmission_onlinetext_wordlimit']);
        $this->assertEquals(1, $result['assignsubmission_onlinetext_wordlimit_enabled']);
        $this->assertEquals(1, $result['assignsubmission_file_enabled']);
        $this->assertEquals(2, $result['assignsubmission_file_maxfiles']);
        $this->assertEquals(1048576, $result['assignsubmission_file_maxsizebytes']);
        $this->assertSame('.pdf,.docx', $result['assignsubmission_file_filetypes']);
        $this->assertEquals(1, $result['assignfeedback_comments_enabled']);
        $this->assertEquals(1, $result['assignfeedback_comments_commentinline']);
        $this->assertEquals(0, $result['assignfeedback_editpdf_enabled']);
        foreach (['assignsubmission_file_filetypeslist', 'assignsubmission_file_maxfilesubmissions',
            'assignsubmission_file_maxsubmissionsizebytes', 'assignsubmission_onlinetext_wordlimitenabled'] as $key) {
            $this->assertArrayNotHasKey($key, $result);
        }
        $this->assertSame(55.0, $result['gradepass']);
        $this->assertSame('', $result['advancedgradingmethod_submissions']);
        $this->assertArrayNotHasKey('mod_settings', $result);
        $this->assertArrayNotHasKey('activity', $result);
    }

    /**
     * An active rubric travels as mod_settings.rubric in the AI schema shape.
     */
    public function test_exports_active_rubric(): void {
        $this->resetAfterTest();
        $cm = $this->make_assign();
        $context = \context_module::instance($cm->id);
        $this->getDataGenerator()->get_plugin_generator('gradingform_rubric')
            ->get_test_rubric($context, 'mod_assign', 'submissions');

        $result = assign_mold_export::export($cm);

        $this->assertSame('rubric', $result['advancedgradingmethod_submissions']);
        $rubric = $result['mod_settings']['rubric'];
        $this->assertSame('testrubric', $rubric['name']);
        $this->assertSame(['Spelling is important', 'Pictures'], array_column($rubric['criteria'], 'description'));
        $this->assertSame([
            ['definition' => 'Nothing but mistakes', 'points' => 0.0],
            ['definition' => 'Several mistakes', 'points' => 1.0],
            ['definition' => 'No mistakes', 'points' => 2.0],
        ], $rubric['criteria'][0]['levels']);
    }

    /**
     * A criterion with fewer than two levels is skipped with a warning; a rubric left without criteria is omitted.
     */
    public function test_rubric_criteria_with_fewer_than_two_levels_are_skipped(): void {
        $this->resetAfterTest();
        $cm = $this->make_assign();
        $context = \context_module::instance($cm->id);
        $generator = $this->getDataGenerator()->get_plugin_generator('gradingform_rubric');
        $generator->create_instance($context, 'mod_assign', 'submissions', 'mixed', 'Mixed levels', [
            'Single level' => ['Only' => 0],
            'Two levels' => ['Low' => 0, 'High' => 2],
        ]);

        $result = assign_mold_export::export($cm);
        $this->assertDebuggingCalled();

        $this->assertSame('rubric', $result['advancedgradingmethod_submissions']);
        $this->assertSame(['Two levels'], array_column($result['mod_settings']['rubric']['criteria'], 'description'));
    }

    /**
     * When every criterion is unusable the rubric is left out entirely.
     */
    public function test_rubric_without_usable_criteria_is_omitted(): void {
        $this->resetAfterTest();
        $cm = $this->make_assign();
        $context = \context_module::instance($cm->id);
        $generator = $this->getDataGenerator()->get_plugin_generator('gradingform_rubric');
        $generator->create_instance($context, 'mod_assign', 'submissions', 'broken', 'Broken', [
            'Single level' => ['Only' => 0],
        ]);

        $result = assign_mold_export::export($cm);
        // One notice for the skipped criterion, one for the omitted rubric.
        $this->assertDebuggingCalledCount(2);

        $this->assertSame('rubric', $result['advancedgradingmethod_submissions']);
        $this->assertArrayNotHasKey('mod_settings', $result);
    }
}
