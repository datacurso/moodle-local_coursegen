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

namespace local_coursegen\local\service;

/**
 * Unit tests for template_activity_export — per-type dispatch and the generic fallback.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\local\service\template_activity_export
 */
final class template_activity_export_test extends \advanced_testcase {
    /**
     * A module type without a mold exporter still gets the minimal {name, section} payload.
     */
    public function test_unsupported_module_falls_back_to_name_and_section(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id, 'name' => 'Debate', 'section' => 1,
        ]);
        $cm = get_fast_modinfo($course->id)->get_cm($forum->cmid);

        $this->assertSame(['name' => 'Debate', 'section' => 1], template_activity_export::parameters_for($cm));
    }

    /**
     * A module type with a mold exporter is routed to it.
     */
    public function test_supported_module_is_routed_to_its_exporter(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $label = $this->getDataGenerator()->create_module('label', [
            'course' => $course->id, 'intro' => '<p>Hi</p>', 'introformat' => FORMAT_HTML,
        ]);
        $cm = get_fast_modinfo($course->id)->get_cm($label->cmid);

        $parameters = template_activity_export::parameters_for($cm);

        $this->assertSame(['text' => '<p>Hi</p>', 'format' => 1], $parameters['introeditor']);
        $this->assertSame(0, $parameters['section']);
    }
}
