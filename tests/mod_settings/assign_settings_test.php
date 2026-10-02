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

use core\context\module;
use local_coursegen\local\warning_collector;

/**
 * Unit tests for assign_settings: rubric creation and its warning-based degradation.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\mod_settings\assign_settings
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\mod_settings\assign_settings::class)]
final class assign_settings_test extends \advanced_testcase {
    /**
     * Leave no injected failure behind.
     */
    protected function tearDown(): void {
        warning_collector::clear_test_failures();
        parent::tearDown();
    }

    /**
     * Create an assignment with the rubric method active (as add_moduleinfo leaves it when the AI
     * requested a rubric) and return a cm-like object shaped as create_mod_service passes it.
     *
     * @return object Object with ->coursemodule (cmid) and ->instance (assign id).
     */
    private function make_assign_cm(): object {
        global $CFG;
        require_once($CFG->dirroot . '/grade/grading/lib.php');

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $manager = get_grading_manager(module::instance($assign->cmid), 'mod_assign', 'submissions');
        $manager->set_active_method('rubric');

        return (object) ['coursemodule' => $assign->cmid, 'instance' => $assign->id];
    }

    /**
     * The grading manager of the assignment.
     *
     * @param object $cm The cm-like object.
     * @return \grading_manager
     */
    private function manager(object $cm): \grading_manager {
        return get_grading_manager(module::instance($cm->coursemodule), 'mod_assign', 'submissions');
    }

    /**
     * A rubric payload with one usable criterion.
     *
     * @return array
     */
    private function rubric(): array {
        return ['rubric' => ['name' => 'AI rubric', 'criteria' => [
            ['description' => 'Clarity', 'levels' => [
                ['definition' => 'Unclear', 'points' => 0],
                ['definition' => 'Clear', 'points' => 5],
            ]],
        ]]];
    }

    /**
     * A usable rubric is created and the rubric method stays active, without warnings.
     */
    public function test_rubric_is_created(): void {
        $this->resetAfterTest();

        $cm = $this->make_assign_cm();
        $settings = new assign_settings($cm, $this->rubric());
        $settings->add_settings();

        $this->assertDebuggingNotCalled();
        $this->assertSame([], $settings->get_warnings());
        $manager = $this->manager($cm);
        $this->assertSame('rubric', $manager->get_active_method());
        $definition = $manager->get_controller('rubric')->get_definition();
        $this->assertNotEmpty($definition);
        $this->assertSame('AI rubric', $definition->name);
    }

    /**
     * When the rubric cannot be created, the assignment degrades to simple grading and the failure
     * is recorded as a rubric warning (the reset itself is a separate step).
     */
    public function test_rubric_failure_degrades_to_simple_grading_with_warning(): void {
        $this->resetAfterTest();

        $cm = $this->make_assign_cm();
        warning_collector::set_test_failure(warning_collector::STEP_RUBRIC, new \RuntimeException('definition rejected'));

        $settings = new assign_settings($cm, $this->rubric());
        $settings->add_settings();

        $this->assertDebuggingCalledCount(1);
        $warnings = $settings->get_warnings();
        $this->assertCount(1, $warnings);
        $this->assertSame(warning_collector::STEP_RUBRIC, $warnings[0]['step']);
        $this->assertSame('definition rejected', $warnings[0]['reason']);
        $this->assertSame(
            [get_string('generationwarning_rubric', 'local_coursegen')],
            warning_collector::to_messages($warnings)
        );
        $this->assertSame('', (string) $this->manager($cm)->get_active_method());
    }

    /**
     * When the grading method cannot be reset either, a second warning is recorded and nothing throws.
     */
    public function test_grading_reset_failure_is_recorded(): void {
        $this->resetAfterTest();

        $cm = $this->make_assign_cm();
        warning_collector::set_test_failure(warning_collector::STEP_RUBRIC, new \RuntimeException('definition rejected'));
        warning_collector::set_test_failure(warning_collector::STEP_GRADING_METHOD, new \RuntimeException('locked'));

        $settings = new assign_settings($cm, $this->rubric());
        $settings->add_settings();

        $this->assertDebuggingCalledCount(2);
        $steps = array_column($settings->get_warnings(), 'step');
        $this->assertSame([warning_collector::STEP_RUBRIC, warning_collector::STEP_GRADING_METHOD], $steps);
    }

    /**
     * Without a rubric in the payload nothing happens.
     */
    public function test_no_rubric_is_noop(): void {
        $this->resetAfterTest();

        $cm = $this->make_assign_cm();
        $settings = new assign_settings($cm, []);
        $settings->add_settings();

        $this->assertDebuggingNotCalled();
        $this->assertSame([], $settings->get_warnings());
        $this->assertSame('rubric', $this->manager($cm)->get_active_method());
    }
}
