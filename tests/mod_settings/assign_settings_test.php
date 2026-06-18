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

/**
 * Unit tests for assign_settings — creation of the AI-generated rubric.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\mod_settings\assign_settings
 */
final class assign_settings_test extends \advanced_testcase {

    /**
     * Create an assignment and return a cm-like object shaped as create_mod_service passes it.
     *
     * @return object Object with ->coursemodule (cmid) and ->instance (assign id).
     */
    private function make_assign_cm(): object {
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        return (object) ['coursemodule' => $assign->cmid, 'instance' => $assign->id];
    }

    /**
     * A valid rubric payload: two criteria (2 and 3 levels).
     *
     * @return array
     */
    private function sample_rubric(): array {
        return ['rubric' => [
            'name' => 'Rúbrica de ensayo',
            'criteria' => [
                ['description' => 'Claridad', 'levels' => [
                    ['definition' => 'Confuso', 'points' => 0],
                    ['definition' => 'Claro', 'points' => 10],
                ]],
                ['description' => 'Profundidad', 'levels' => [
                    ['definition' => 'Escasa', 'points' => 0],
                    ['definition' => 'Adecuada', 'points' => 5],
                    ['definition' => 'Excelente', 'points' => 10],
                ]],
            ],
        ]];
    }

    /**
     * The rubric is registered as the active method with all its criteria and levels.
     */
    public function test_creates_rubric_definition(): void {
        $this->resetAfterTest();
        global $DB, $CFG;
        require_once($CFG->dirroot . '/grade/grading/lib.php');

        $cm = $this->make_assign_cm();
        (new assign_settings($cm, $this->sample_rubric()))->add_settings();

        $context = \context_module::instance($cm->coursemodule);
        $manager = get_grading_manager($context, 'mod_assign', 'submissions');
        $this->assertSame('rubric', $manager->get_active_method());

        $controller = $manager->get_controller('rubric');
        $this->assertTrue($controller->is_form_defined());

        $definition = $controller->get_definition();
        $this->assertSame('Rúbrica de ensayo', $definition->name);
        $this->assertEquals(\gradingform_controller::DEFINITION_STATUS_READY, (int) $definition->status);

        $criteria = $DB->get_records('gradingform_rubric_criteria', ['definitionid' => $definition->id]);
        $this->assertCount(2, $criteria);

        list($insql, $params) = $DB->get_in_or_equal(array_keys($criteria));
        $levels = $DB->get_records_select('gradingform_rubric_levels', "criterionid $insql", $params);
        $this->assertCount(5, $levels);

        $scores = array_map(static fn($level) => (float) $level->score, $levels);
        $this->assertContains(10.0, $scores);
        $this->assertContains(0.0, $scores);
    }

    /**
     * No rubric in the payload -> nothing is created, simple grading is preserved.
     */
    public function test_no_rubric_is_noop(): void {
        $this->resetAfterTest();
        global $DB;

        $cm = $this->make_assign_cm();
        (new assign_settings($cm, []))->add_settings();
        (new assign_settings($cm, ['rubric' => null]))->add_settings();
        (new assign_settings($cm, ['rubric' => ['name' => 'x', 'criteria' => []]]))->add_settings();

        $context = \context_module::instance($cm->coursemodule);
        $manager = get_grading_manager($context, 'mod_assign', 'submissions');
        $this->assertNotEquals('rubric', $manager->get_active_method());
        $this->assertSame(0, $DB->count_records('grading_definitions'));
    }

    /**
     * A criterion with fewer than two valid levels is skipped (a rubric row needs >= 2 levels).
     */
    public function test_criterion_with_one_level_is_skipped(): void {
        $this->resetAfterTest();
        global $DB;

        $cm = $this->make_assign_cm();
        $modsettings = ['rubric' => ['name' => 'R', 'criteria' => [
            ['description' => 'Solo un nivel', 'levels' => [['definition' => 'x', 'points' => 1]]],
            ['description' => 'Valido', 'levels' => [
                ['definition' => 'Bajo', 'points' => 0],
                ['definition' => 'Alto', 'points' => 4],
            ]],
        ]]];
        (new assign_settings($cm, $modsettings))->add_settings();

        $context = \context_module::instance($cm->coursemodule);
        $manager = get_grading_manager($context, 'mod_assign', 'submissions');
        $definition = $manager->get_controller('rubric')->get_definition();
        $criteria = $DB->get_records('gradingform_rubric_criteria', ['definitionid' => $definition->id]);
        $this->assertCount(1, $criteria);
    }
}
