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
 * Unit tests for choice_mold_export — option rows in mod_form shape plus the choice settings.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\local\service\mold_export\choice_mold_export
 */
final class choice_mold_export_test extends \advanced_testcase {
    /**
     * Options travel as parallel option/limit/optionid lists, the way mod_form posts them.
     */
    public function test_exports_option_rows_and_settings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $choice = $this->getDataGenerator()->create_module('choice', [
            'course' => $course->id, 'name' => 'Mold choice', 'intro' => '<p>Vote</p>', 'introformat' => FORMAT_HTML,
            'option' => ['⟦coursegen:repeat: one⟧⟦text⟧⟦/coursegen:repeat⟧', 'Other'], 'limit' => [3, 0],
            'limitanswers' => 1, 'allowmultiple' => 1, 'showresults' => 3, 'publish' => 1, 'display' => 1,
            'completion' => 2, 'completionsubmit' => 1,
        ]);
        $cm = get_fast_modinfo($course->id)->get_cm($choice->cmid);

        $result = choice_mold_export::export($cm);

        $this->assertSame(['text' => '<p>Vote</p>', 'format' => 1], $result['introeditor']);
        $this->assertSame(['⟦coursegen:repeat: one⟧⟦text⟧⟦/coursegen:repeat⟧', 'Other'], $result['option']);
        $this->assertSame([3, 0], $result['limit']);
        $this->assertSame([0, 0], $result['optionid']);
        $this->assertSame(2, $result['option_repeats']);
        $this->assertEquals(1, $result['limitanswers']);
        $this->assertEquals(1, $result['allowmultiple']);
        $this->assertEquals(3, $result['showresults']);
        $this->assertEquals(1, $result['publish']);
        $this->assertEquals(1, $result['display']);
        $this->assertEquals(1, $result['completionsubmit']);
        $settings = ['allowupdate', 'showavailable', 'showpreview', 'showunanswered', 'includeinactive', 'timeopen', 'timeclose'];
        foreach ($settings as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertArrayNotHasKey('mod_settings', $result);
    }
}
