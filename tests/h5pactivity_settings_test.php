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

use local_coursegen\mod_settings\h5pactivity_settings;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');

/**
 * Tests for the H5P activity post-creation settings handler.
 *
 * The AI result may carry H5P-specific mod_settings beyond the package
 * fields consumed by the parameters handler. This handler applies the
 * supported ones (passing_score) and reports the rest as developer
 * debugging instead of silently discarding them.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\mod_settings\h5pactivity_settings
 */
final class h5pactivity_settings_test extends \advanced_testcase {
    /**
     * Create an H5P activity and return the course module object the settings
     * handler receives from add_moduleinfo().
     *
     * @param float $gradepass Passing grade already stored on the grade item.
     * @return object Course module style object (course, instance, coursemodule).
     */
    private function create_h5pactivity(float $gradepass = 0.0): object {
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('h5pactivity', [
            'course' => $course->id,
            'grade' => 100,
            'gradepass' => $gradepass,
        ]);

        return (object) [
            'course' => $course->id,
            'instance' => $module->id,
            'coursemodule' => $module->cmid,
            'modulename' => 'h5pactivity',
        ];
    }

    /**
     * Fetch the grade item of the given H5P activity.
     *
     * @param object $cm Course module style object.
     * @return \grade_item
     */
    private function fetch_grade_item(object $cm): \grade_item {
        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'h5pactivity',
            'iteminstance' => $cm->instance,
            'courseid' => $cm->course,
        ]);
        $this->assertNotEmpty($gradeitem);

        return $gradeitem;
    }

    /**
     * The passing_score setting reaches the grade item when the activity has
     * no passing grade yet.
     */
    public function test_passing_score_applies_to_grade_item_when_not_set(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cm = $this->create_h5pactivity(0.0);

        (new h5pactivity_settings($cm, ['passing_score' => 85]))->add_settings();

        $this->assertEquals(85.0, (float) $this->fetch_grade_item($cm)->gradepass);
    }

    /**
     * A passing grade already applied through the module parameters is not
     * overwritten: the handler is idempotent with the creation flow.
     */
    public function test_passing_score_does_not_overwrite_existing_gradepass(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cm = $this->create_h5pactivity(70.0);

        (new h5pactivity_settings($cm, ['passing_score' => 85]))->add_settings();

        $this->assertEquals(70.0, (float) $this->fetch_grade_item($cm)->gradepass);
    }

    /**
     * Unconsumed mod_settings keys are reported as developer debugging. The
     * package fields consumed upstream (file_path/file_name) and passing_score
     * are not reported.
     */
    public function test_unconsumed_settings_keys_produce_debugging(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cm = $this->create_h5pactivity(0.0);

        (new h5pactivity_settings($cm, [
            'file_path' => 'generated/packages/sample-activity.h5p',
            'file_name' => 'sample-activity.h5p',
            'passing_score' => 85,
            'behaviour' => ['enableRetry' => true],
            'custom_future_setting' => 'ignored',
        ]))->add_settings();

        $debugging = $this->getDebuggingMessages();
        $this->assertDebuggingCalledCount(1);
        $this->assertStringContainsString('behaviour', $debugging[0]->message);
        $this->assertStringContainsString('custom_future_setting', $debugging[0]->message);
        $this->assertStringNotContainsString('file_path', $debugging[0]->message);
        $this->assertStringNotContainsString('passing_score', $debugging[0]->message);
    }

    /**
     * Consumed keys only produce no debugging at all.
     */
    public function test_consumed_settings_keys_produce_no_debugging(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cm = $this->create_h5pactivity(0.0);

        (new h5pactivity_settings($cm, [
            'file_path' => 'generated/packages/sample-activity.h5p',
            'file_name' => 'sample-activity.h5p',
            'passing_score' => 85,
        ]))->add_settings();

        $this->assertDebuggingNotCalled();
    }
}
