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
 * Unit tests for feedback_mold_export — settings, the after-submit editor and every question decoded per type.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\local\service\mold_export\feedback_mold_export
 * @covers \local_coursegen\local\service\mold_export\feedback_presentation_decoder
 */
final class feedback_mold_export_test extends \advanced_testcase {
    /**
     * Each item type is decoded into the fields the plugin's own presentation builder consumes; pagebreaks are skipped.
     */
    public function test_exports_settings_and_decoded_questions(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $feedback = $this->getDataGenerator()->create_module('feedback', [
            'course' => $course->id, 'name' => 'Mold survey', 'intro' => '<p>S</p>', 'introformat' => FORMAT_HTML,
            'anonymous' => 2, 'multiple_submit' => 1, 'publish_stats' => 1, 'autonumbering' => 0,
            'page_after_submit' => '<p>Thanks @@PLUGINFILE@@/t.png</p>', 'page_after_submitformat' => FORMAT_HTML,
            'site_after_submit' => 'https://example.com', 'completion' => 2, 'completionsubmit' => 1,
        ]);
        $context = \context_module::instance($feedback->cmid);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_feedback');
        $label = $generator->create_item_label($feedback, [
            'presentation_editor' => ['text' => '<p>Intro @@PLUGINFILE@@/l.png</p>', 'format' => 1, 'itemid' => 0],
        ]);
        $generator->create_item_multichoice($feedback, [
            'name' => '⟦q⟧', 'label' => 'q1', 'required' => 1, 'subtype' => 'c', 'horizontal' => 1, 'values' => "A\nB|C",
        ]);
        $generator->create_item_multichoicerated($feedback, [
            'name' => 'Rate', 'subtype' => 'd', 'horizontal' => 0, 'values' => "1/Low\n5/High",
        ]);
        $generator->create_item_numeric($feedback, ['name' => 'Num', 'rangefrom' => 1, 'rangeto' => 10]);
        $generator->create_item_pagebreak($feedback);
        $generator->create_item_textarea($feedback, ['name' => 'Area', 'itemwidth' => 60, 'itemheight' => 5]);
        $generator->create_item_textfield($feedback, ['name' => 'Field', 'itemsize' => 30, 'itemmaxlength' => 255]);
        $generator->create_item_info($feedback, ['name' => 'Info']);
        $cm = get_fast_modinfo($course->id)->get_cm($feedback->cmid);

        $result = feedback_mold_export::export($cm);

        $this->assertSame(['text' => '<p>S</p>', 'format' => 1], $result['introeditor']);
        $this->assertEquals(2, $result['anonymous']);
        $this->assertEquals(1, $result['multiple_submit']);
        $this->assertEquals(1, $result['publish_stats']);
        $this->assertEquals(0, $result['autonumbering']);
        $this->assertSame('https://example.com', $result['site_after_submit']);
        $this->assertEquals(1, $result['completionsubmit']);
        foreach (['email_notification', 'timeopen', 'timeclose'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame(1, $result['page_after_submit_editor']['format']);
        $this->assertStringContainsString(
            '/pluginfile.php/' . $context->id . '/mod_feedback/page_after_submit/',
            $result['page_after_submit_editor']['text']
        );
        $this->assertArrayNotHasKey('page_after_submit', $result);

        $questions = $result['mod_settings']['questions'];
        $this->assertSame(['label', 'multichoice', 'multichoicerated', 'numeric', 'textarea', 'textfield', 'info'],
            array_column($questions, 'typ'));
        $this->assertSame([1, 2, 3, 4, 6, 7, 8], array_column($questions, 'position'));

        $this->assertSame(1, $questions[0]['presentation_editor']['format']);
        $this->assertStringContainsString('/pluginfile.php/' . $context->id . '/mod_feedback/item/' . $label->id . '/l.png',
            $questions[0]['presentation_editor']['text']);

        $mc = $questions[1];
        $this->assertSame('⟦q⟧', $mc['name']);
        $this->assertSame('q1', $mc['label']);
        $this->assertEquals(1, $mc['required']);
        $this->assertSame('c', $mc['subtype']);
        $this->assertSame(1, $mc['horizontal']);
        $this->assertSame("A\nB\nC", $mc['values']);
        $this->assertSame(1, $mc['hidenoselect']);
        $this->assertSame(0, $mc['ignoreempty']);
        $this->assertSame('c>>>>>A|B|C<<<<<1', $mc['presentation']);
        foreach (['dependitem', 'dependvalue', 'options'] as $key) {
            $this->assertArrayHasKey($key, $mc);
        }

        $rated = $questions[2];
        $this->assertSame('d', $rated['subtype']);
        $this->assertSame(0, $rated['horizontal']);
        $this->assertSame("1/Low\n5/High", $rated['values']);

        $this->assertSame(1.0, $questions[3]['rangefrom']);
        $this->assertSame(10.0, $questions[3]['rangeto']);
        $this->assertSame(60, $questions[4]['itemwidth']);
        $this->assertSame(5, $questions[4]['itemheight']);
        $this->assertSame(30, $questions[5]['itemsize']);
        $this->assertSame(255, $questions[5]['itemmaxlength']);
        $this->assertEquals(\feedback_item_info::MODE_COURSE, $questions[6]['presentation']);
        $this->assertArrayNotHasKey('values', $questions[6]);
    }
}
