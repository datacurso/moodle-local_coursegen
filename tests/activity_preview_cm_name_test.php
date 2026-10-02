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

use local_coursegen\local\preview\preview_factory;

/**
 * Tests for the name the preview gives the course module its page is built on.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\activity_preview::name_course_module
 */
final class activity_preview_cm_name_test extends \advanced_testcase {
    /**
     * The page heading is printed from the course module: it carries the generated name.
     *
     * @dataProvider instance_names_provider
     * @param string $modname
     * @param string $name
     */
    public function test_the_course_module_carries_the_generated_name(string $modname, string $name): void {
        $this->resetAfterTest(true);
        $cm = $this->template_cm($modname);
        $this->assertSame('Molde - Lección estándar', $cm->name);

        $preview = preview_factory::for_activity($modname, ['name' => $name]);
        $preview->name_course_module($cm);

        $this->assertSame($name, $cm->name);
        $this->assertSame(s($name), $cm->get_formatted_name());
    }

    /**
     * Instance names with accents, numbers and trailing text, for several modules.
     *
     * @return array
     */
    public static function instance_names_provider(): array {
        return [
            'lesson' => ['lesson', 'Lección 1'],
            'forum' => ['forum', 'Foro Formativo - Lección 3'],
            'page' => ['page', 'Evaluación: módulo ñandú 12 (final)'],
            'assign' => ['assign', 'Tarea - Lección 2'],
        ];
    }

    /**
     * An activity with no name leaves the course module's name alone.
     */
    public function test_an_unnamed_activity_leaves_the_course_module_name(): void {
        $this->resetAfterTest(true);
        $cm = $this->template_cm('page');

        $preview = preview_factory::for_activity('page', []);
        $preview->name_course_module($cm);

        $this->assertSame('Molde - Lección estándar', $cm->name);
    }

    /**
     * A course module of this type named like the template activity.
     *
     * @param string $modname
     * @return \cm_info
     */
    private function template_cm(string $modname): \cm_info {
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module($modname, [
            'course' => $course->id,
            'name' => 'Molde - Lección estándar',
        ]);
        return get_fast_modinfo($course)->get_cm($module->cmid);
    }
}
