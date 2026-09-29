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
 * Unit tests for label_mold_export — intro editor with rewritten pluginfile URLs plus course-module columns.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\local\service\mold_export\label_mold_export
 * @covers \local_coursegen\local\service\mold_export\base_mold_export
 */
final class label_mold_export_test extends \advanced_testcase {
    /**
     * The intro travels as an editor dict with absolute pluginfile URLs, next to the cm columns.
     */
    public function test_exports_intro_editor_and_cm_columns(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $label = $this->getDataGenerator()->create_module('label', [
            'course' => $course->id, 'name' => 'Mold label', 'section' => 1,
            'intro' => '<p>⟦texto⟧ <img src="@@PLUGINFILE@@/pic.png"></p>', 'introformat' => FORMAT_HTML,
            'showdescription' => 1, 'idnumber' => 'LBL-1', 'groupmode' => 1,
        ]);
        $cm = get_fast_modinfo($course->id)->get_cm($label->cmid);
        $context = \context_module::instance($label->cmid);

        $result = label_mold_export::export($cm);

        $this->assertSame('Mold label', $result['name']);
        $this->assertSame(1, $result['section']);
        $this->assertSame(1, $result['introeditor']['format']);
        $this->assertStringNotContainsString('@@PLUGINFILE@@', $result['introeditor']['text']);
        $this->assertStringContainsString(
            '/pluginfile.php/' . $context->id . '/mod_label/intro/pic.png',
            $result['introeditor']['text']
        );
        $this->assertStringStartsWith('<p>⟦texto⟧ <img src="http', $result['introeditor']['text']);
        $this->assertSame('LBL-1', $result['cmidnumber']);
        $this->assertSame(1, $result['showdescription']);
        $this->assertSame(1, $result['groupmode']);
        $this->assertSame(0, $result['groupingid']);
        $this->assertSame(0, $result['completion']);
        $this->assertSame(1, $result['completionunlocked']);
        $this->assertArrayNotHasKey('visible', $result);
        $this->assertArrayNotHasKey('course', $result);
        $this->assertArrayNotHasKey('intro', $result);
        $this->assertArrayNotHasKey('mod_settings', $result);
    }
}
