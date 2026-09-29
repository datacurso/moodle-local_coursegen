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
 * Unit tests for page_mold_export — page content editor, display and the decoded display options.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\local\service\mold_export\page_mold_export
 */
final class page_mold_export_test extends \advanced_testcase {
    /**
     * Content is an editor dict with rewritten URLs; displayoptions is decoded into its two flags.
     */
    public function test_exports_page_editor_and_display_options(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'name' => 'Mold page', 'intro' => '<p>Intro</p>', 'introformat' => FORMAT_HTML,
            'content' => '<p>⟦texto⟧ <img src="@@PLUGINFILE@@/a.png"></p>', 'contentformat' => FORMAT_HTML,
            'display' => 5, 'printintro' => 1, 'printlastmodified' => 0, 'completion' => 2, 'completionview' => 1,
        ]);
        $cm = get_fast_modinfo($course->id)->get_cm($page->cmid);
        $context = \context_module::instance($page->cmid);

        $result = page_mold_export::export($cm);

        $this->assertSame(['text' => '<p>Intro</p>', 'format' => 1], $result['introeditor']);
        $this->assertSame(1, $result['page']['format']);
        $this->assertStringContainsString('/pluginfile.php/' . $context->id . '/mod_page/content/', $result['page']['text']);
        $this->assertStringContainsString('/a.png', $result['page']['text']);
        $this->assertStringNotContainsString('@@PLUGINFILE@@', $result['page']['text']);
        $this->assertEquals(5, $result['display']);
        $this->assertEquals(1, $result['printintro']);
        $this->assertEquals(0, $result['printlastmodified']);
        $this->assertSame(2, $result['completion']);
        $this->assertSame(1, $result['completionview']);
        $this->assertArrayNotHasKey('displayoptions', $result);
        $this->assertArrayNotHasKey('revision', $result);
        $this->assertArrayNotHasKey('content', $result);
    }

    /**
     * A corrupt displayoptions blob leaves printintro/printlastmodified out (schema defaults apply) and warns.
     */
    public function test_corrupt_displayoptions_falls_back_to_defaults(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'display' => 5]);
        $DB->set_field('page', 'displayoptions', 'garbage', ['id' => $page->id]);
        $cm = get_fast_modinfo($course->id)->get_cm($page->cmid);

        $result = page_mold_export::export($cm);
        $this->assertDebuggingCalled();

        $this->assertEquals(5, $result['display']);
        $this->assertArrayNotHasKey('printintro', $result);
        $this->assertArrayNotHasKey('printlastmodified', $result);
        $this->assertArrayHasKey('page', $result);
    }
}
