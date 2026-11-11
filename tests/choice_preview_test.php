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
 * Tests for the choice preview, drawn from the tree its result carries.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\choice_preview
 */
final class choice_preview_test extends \advanced_testcase {
    use preview_page_setup;

    /**
     * The parameters of a finished choice whose tree holds the given options.
     *
     * @param string[] $texts The option texts, in order.
     * @return array
     */
    private function parameters_with_options(array $texts): array {
        $options = [];
        $id = 85;
        foreach ($texts as $text) {
            $options[] = ['id' => $id++, 'text' => $text, 'maxanswers' => 0, 'timemodified' => 0];
        }
        $choice = ['id' => 9, 'course' => 1, 'name' => 'A choice', 'intro' => '', 'introformat' => 1, 'publish' => 0,
            'showresults' => 1, 'display' => 0, 'allowupdate' => 0, 'allowmultiple' => 0, 'showunanswered' => 0,
            'limitanswers' => 0, 'timeopen' => 0, 'timeclose' => 0, 'showpreview' => 0,
            'options' => [['option' => $options]], 'answers' => [[]]];
        return [
            'name' => 'A choice',
            'structure' => ['choice' => [$choice]],
            'structure_tables' => ['choice' => 'choice', 'option' => 'choice_options'],
            'structure_aliases' => [],
        ];
    }

    /**
     * Options that share a text are all listed, and the options are the ones of the result.
     */
    public function test_every_option_of_the_tree_is_listed(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();
        $parameters = $this->parameters_with_options(['Generated one', 'Generated one', 'Generated two']);

        $html = $this->text_of($this->preview_of('choice', $parameters)->render());

        $this->assertSame(2, substr_count($html, 'Generated one'));
        $this->assertStringContainsString('Generated two', $html);
        $this->assertStringNotContainsString('[[coursegen:', $html);
    }
}
