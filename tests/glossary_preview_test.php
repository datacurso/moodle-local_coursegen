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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/fixtures/preview_page_setup.php');

/**
 * Tests for the glossary preview, drawn from the tree its result carries.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\glossary_preview
 */
final class glossary_preview_test extends \advanced_testcase {
    use preview_page_setup;

    /**
     * The parameters of a finished glossary whose tree holds the given entries.
     *
     * @param array $entries Entry rows.
     * @return array
     */
    private function parameters_with_entries(array $entries): array {
        $glossary = ['id' => 4, 'course' => 1, 'name' => 'A glossary', 'intro' => '', 'introformat' => 1,
            'displayformat' => 'dictionary', 'entbypage' => 10, 'showall' => 1, 'showalphabet' => 1, 'showspecial' => 1,
            'allowprintview' => 1, 'usedynalink' => 1, 'defaultapproval' => 1, 'editalways' => 0,
            'entries' => [['entry' => $entries]]];
        return [
            'name' => 'A glossary',
            'structure' => ['glossary' => [$glossary]],
            'structure_tables' => ['glossary' => 'glossary', 'entry' => 'glossary_entries'],
            'structure_aliases' => [],
        ];
    }

    /**
     * One entry row.
     *
     * @param int $id
     * @param string $concept
     * @param string $definition
     * @return array
     */
    private function entry(int $id, string $concept, string $definition): array {
        return ['id' => $id, 'userid' => 0, 'concept' => $concept, 'definition' => $definition,
            'definitionformat' => FORMAT_HTML, 'definitiontrust' => 0, 'attachment' => '', 'timecreated' => 0,
            'timemodified' => 0, 'teacherentry' => 1, 'sourceglossaryid' => 0, 'usedynalink' => 1,
            'casesensitive' => 0, 'fullmatch' => 1, 'approved' => 1];
    }

    /**
     * Two entries with the same concept each show their own definition.
     */
    public function test_entries_with_the_same_concept_each_show_their_own_definition(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();
        $parameters = $this->parameters_with_entries([
            $this->entry(900001, 'Term', '<p>Alpha definition</p>'),
            $this->entry(900002, 'Term', '<p>Beta definition</p>'),
        ]);

        $html = $this->text_of($this->preview_of('glossary', $parameters)->render());

        $this->assertStringContainsString('Alpha definition', $html);
        $this->assertStringContainsString('Beta definition', $html);
        $this->assertSame(2, substr_count($html, 'Term'));
        $this->assertStringNotContainsString('[[coursegen:', $html);
    }

    /**
     * A glossary whose tree holds no entries is the empty glossary it will be created as.
     */
    public function test_a_glossary_without_entries_does_not_fail(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();

        $html = $this->preview_of('glossary', $this->parameters_with_entries([]))->render();

        $this->assertIsString($html);
    }
}
