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
 * Tests for the wiki preview, drawn from the tree its result carries.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\wiki_preview
 */
final class wiki_preview_test extends \advanced_testcase {
    use preview_page_setup;

    /**
     * The parameters of a finished wiki whose tree holds the given pages.
     *
     * @param array $texts Page title => content, one page each, in the order given.
     * @return array
     */
    private function parameters_with_pages(array $texts): array {
        $pages = [];
        $id = 900001;
        foreach ($texts as $title => $content) {
            $pages[] = ['id' => $id, 'title' => $title, 'cachedcontent' => '', 'timecreated' => 0, 'timemodified' => 0,
                'timerendered' => 0, 'userid' => 0, 'pageviews' => 0, 'readonly' => 0,
                'versions' => [['version' => [['id' => $id, 'content' => $content, 'contentformat' => 'html',
                    'version' => 1, 'timecreated' => 0, 'userid' => 0]]]]];
            $id++;
        }
        $wiki = ['id' => 2, 'course' => 1, 'name' => 'A wiki', 'intro' => '', 'introformat' => 1,
            'firstpagetitle' => 'Start', 'wikimode' => 'collaborative', 'defaultformat' => 'html', 'forceformat' => 0,
            'subwikis' => [['subwiki' => [['id' => 900001, 'groupid' => 0, 'userid' => 0, 'pages' => [['page' => $pages]]]]]]];
        return [
            'name' => 'A wiki',
            'structure' => ['wiki' => [$wiki]],
            'structure_tables' => ['wiki' => 'wiki', 'subwiki' => 'wiki_subwikis', 'page' => 'wiki_pages',
                'version' => 'wiki_versions'],
            'structure_aliases' => [],
        ];
    }

    /**
     * Pages in the tree are the pages of the wiki, each with its own text.
     */
    public function test_each_page_of_the_tree_shows_its_own_text(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();
        $parameters = $this->parameters_with_pages([
            'First' => '<p>Alpha wiki text</p>',
            'Second' => '<p>Beta wiki text</p>',
        ]);

        $first = $this->text_of($this->preview_of('wiki', $parameters, 0)->render());
        $second = $this->text_of($this->preview_of('wiki', $parameters, 1)->render());

        $this->assertStringContainsString('Alpha wiki text', $first);
        $this->assertStringNotContainsString('Beta wiki text', $first);
        $this->assertStringContainsString('Beta wiki text', $second);
        $this->assertStringNotContainsString('Alpha wiki text', $second);
    }

    /**
     * Two pages that share a title are both kept, each with its own text.
     */
    public function test_pages_with_the_same_title_are_both_kept(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();
        $tree = $this->parameters_with_pages(['Same' => '<p>Alpha wiki text</p>']);
        $pages = &$tree['structure']['wiki'][0]['subwikis'][0]['subwiki'][0]['pages'][0]['page'];
        $pages[] = $pages[0];
        $pages[1]['id'] = 900002;
        $pages[1]['versions'][0]['version'][0] = ['id' => 900002, 'content' => '<p>Beta wiki text</p>',
            'contentformat' => 'html', 'version' => 1, 'timecreated' => 0, 'userid' => 0];

        $first = $this->text_of($this->preview_of('wiki', $tree, 0)->render());
        $second = $this->text_of($this->preview_of('wiki', $tree, 1)->render());

        $this->assertStringContainsString('Alpha wiki text', $first);
        $this->assertStringContainsString('Beta wiki text', $second);
    }
}
