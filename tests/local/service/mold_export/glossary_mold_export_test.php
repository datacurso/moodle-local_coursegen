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
 * Unit tests for glossary_mold_export — settings columns plus the approved teacher entries as rows.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\local\service\mold_export\glossary_mold_export
 */
final class glossary_mold_export_test extends \advanced_testcase {
    /**
     * Approved teacher entries travel in id order as concept + definition_editor; unapproved ones do not.
     */
    public function test_exports_settings_and_entries(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', [
            'course' => $course->id, 'name' => 'Mold glossary', 'intro' => '<p>Terms</p>', 'introformat' => FORMAT_HTML,
            'displayformat' => 'fullwithauthor', 'entbypage' => 7, 'allowcomments' => 1, 'completionentries' => 3,
        ]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_glossary');
        $first = $generator->create_entry([
            'glossaryid' => $glossary->id, 'concept' => '⟦term⟧',
            'definition' => '<p>⟦def⟧ <img src="@@PLUGINFILE@@/d.png"></p>', 'definitionformat' => FORMAT_HTML,
        ]);
        $generator->create_entry([
            'glossaryid' => $glossary->id, 'concept' => 'Literal', 'definition' => 'Plain', 'definitionformat' => FORMAT_MOODLE,
        ]);
        $generator->create_entry([
            'glossaryid' => $glossary->id, 'concept' => 'Hidden', 'definition' => 'x', 'approved' => 0,
        ]);
        $cm = get_fast_modinfo($course->id)->get_cm($glossary->cmid);
        $context = \context_module::instance($glossary->cmid);

        $result = glossary_mold_export::export($cm);

        $this->assertSame(['text' => '<p>Terms</p>', 'format' => 1], $result['introeditor']);
        $this->assertSame('fullwithauthor', $result['displayformat']);
        $this->assertEquals(7, $result['entbypage']);
        $this->assertEquals(1, $result['allowcomments']);
        $this->assertEquals(3, $result['completionentries']);
        foreach (['approvaldisplayformat', 'defaultapproval', 'editalways', 'allowduplicatedentries', 'usedynalink',
            'showalphabet', 'showall', 'showspecial', 'allowprintview', 'assessed', 'scale', 'mainglossary',
            'globalglossary'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $entries = $result['mod_settings']['entries'];
        $this->assertCount(2, $entries);
        $this->assertSame('⟦term⟧', $entries[0]['concept']);
        $this->assertSame(1, $entries[0]['definition_editor']['format']);
        $this->assertStringContainsString(
            '/pluginfile.php/' . $context->id . '/mod_glossary/entry/' . $first->id . '/d.png',
            $entries[0]['definition_editor']['text']
        );
        $this->assertSame(['concept' => 'Literal', 'definition_editor' => ['text' => 'Plain', 'format' => 0]], $entries[1]);
    }
}
