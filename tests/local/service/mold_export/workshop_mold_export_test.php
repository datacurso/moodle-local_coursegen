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
 * Unit tests for workshop_mold_export — settings, the four editors, accumulative criteria and the phase token.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\local\service\mold_export\workshop_mold_export
 */
final class workshop_mold_export_test extends \advanced_testcase {
    /**
     * Create a workshop with the given strategy and return its cm_info.
     *
     * @param string $strategy Grading strategy.
     * @return \cm_info
     */
    private function make_workshop(string $strategy): \cm_info {
        global $DB;
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $workshop = $this->getDataGenerator()->create_module('workshop', [
            'course' => $course->id, 'name' => 'Mold workshop', 'intro' => '<p>W</p>', 'introformat' => FORMAT_HTML,
            'strategy' => $strategy, 'grade' => 80, 'gradinggrade' => 20, 'gradedecimals' => 1, 'nattachments' => 2,
            'submissionfiletypes' => '.pdf', 'useselfassessment' => 1, 'overallfeedbackmode' => 2,
        ]);
        // The generator only accepts these texts through draft editors; write the columns directly.
        $DB->update_record('workshop', (object) [
            'id' => $workshop->id,
            'instructauthors' => '<p>Authors @@PLUGINFILE@@/a.png</p>', 'instructauthorsformat' => FORMAT_HTML,
            'instructreviewers' => '<p>Reviewers</p>', 'instructreviewersformat' => FORMAT_HTML,
            'conclusion' => '<p>Done</p>', 'conclusionformat' => FORMAT_HTML,
        ]);
        $gradeitem = ['itemmodule' => 'workshop', 'iteminstance' => $workshop->id];
        $DB->set_field('grade_items', 'gradepass', 56, $gradeitem + ['itemnumber' => 0]);
        $DB->set_field('grade_items', 'gradepass', 14, $gradeitem + ['itemnumber' => 1]);
        $DB->set_field('workshop', 'phase', 20, ['id' => $workshop->id]);
        return get_fast_modinfo($course->id)->get_cm($workshop->cmid);
    }

    /**
     * Accumulative criteria travel in sort order with their max points and weight; passes come from grade items.
     */
    public function test_exports_accumulative_criteria_and_settings(): void {
        global $DB;
        $this->resetAfterTest();
        $cm = $this->make_workshop('accumulative');
        $context = \context_module::instance($cm->id);
        $DB->insert_record('workshopform_accumulative', (object) [
            'workshopid' => $cm->instance, 'sort' => 2, 'description' => 'Second', 'descriptionformat' => FORMAT_HTML,
            'grade' => 10, 'weight' => 1,
        ]);
        $DB->insert_record('workshopform_accumulative', (object) [
            'workshopid' => $cm->instance, 'sort' => 1, 'description' => '⟦coursegen:repeat: c⟧⟦texto⟧⟦/coursegen:repeat⟧',
            'descriptionformat' => FORMAT_HTML, 'grade' => 25, 'weight' => 2,
        ]);

        $result = workshop_mold_export::export($cm);

        $this->assertSame(['text' => '<p>W</p>', 'format' => 1], $result['introeditor']);
        $this->assertStringContainsString(
            '/pluginfile.php/' . $context->id . '/mod_workshop/instructauthors/',
            $result['instructauthorseditor']['text']
        );
        $this->assertSame(1, $result['instructauthorseditor']['format']);
        $this->assertSame(['text' => '<p>Reviewers</p>', 'format' => 1], $result['instructreviewerseditor']);
        $this->assertSame(['text' => '<p>Done</p>', 'format' => 1], $result['conclusioneditor']);
        $this->assertSame('accumulative', $result['strategy']);
        $this->assertEquals(80, $result['grade']);
        $this->assertEquals(20, $result['gradinggrade']);
        $this->assertEquals(1, $result['gradedecimals']);
        $this->assertEquals(2, $result['nattachments']);
        $this->assertSame('.pdf', $result['submissionfiletypes']);
        $this->assertEquals(56, $result['submissiongradepass']);
        $this->assertEquals(14, $result['gradinggradepass']);
        foreach (['submissiontypetext', 'submissiontypefile', 'maxbytes', 'latesubmissions', 'useselfassessment',
            'overallfeedbackmode', 'overallfeedbackfiles', 'useexamples', 'examplesmode', 'submissionstart',
            'submissionend', 'assessmentstart', 'assessmentend', 'phaseswitchassessment'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertArrayNotHasKey('phase', $result);
        $this->assertArrayNotHasKey('usepeerassessment', $result);
        $this->assertSame([
            ['description' => '⟦coursegen:repeat: c⟧⟦texto⟧⟦/coursegen:repeat⟧', 'max_points' => 25, 'weight' => 2],
            ['description' => 'Second', 'max_points' => 10, 'weight' => 1],
        ], $result['mod_settings']['criteria']);
        $this->assertSame('submission', $result['mod_settings']['initial_phase']);
    }

    /**
     * A non-accumulative strategy exports no criteria and says so through debugging().
     */
    public function test_non_accumulative_strategy_omits_criteria(): void {
        $this->resetAfterTest();
        $cm = $this->make_workshop('comments');

        $result = workshop_mold_export::export($cm);
        $this->assertDebuggingCalled();

        $this->assertSame('comments', $result['strategy']);
        $this->assertArrayNotHasKey('criteria', $result['mod_settings']);
        $this->assertSame('submission', $result['mod_settings']['initial_phase']);
    }
}
