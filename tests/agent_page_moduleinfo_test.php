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

use local_coursegen\local\service\agent_page_moduleinfo;

/**
 * Unit tests for the page the template agent writes, turned into what add_moduleinfo needs.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\agent_page_moduleinfo
 */
final class agent_page_moduleinfo_test extends \advanced_testcase {
    /**
     * The result row of a page as the agent returns it: the html it wrote plus the backup structure of the template page.
     *
     * @param array $overrides Values that replace the defaults of the parameters.
     * @return array The parameters of the generated activity.
     */
    private function parameters(array $overrides = []): array {
        $defaults = [
            'name' => 'Guia Didactica',
            'section' => 0,
            'content' => '<p>Texto escrito por la IA</p>',
            'intro' => '',
            'structure' => [
                'id' => '2372',
                'moduleid' => 11342,
                'modulename' => 'page',
                'page' => [[
                    'name' => 'Guia Didactica',
                    'intro' => '',
                    'introformat' => '1',
                    'content' => '<p>Texto de la plantilla</p>',
                    'contentformat' => '1',
                    'display' => '5',
                    'displayoptions' => 'a:2:{s:10:"printintro";s:1:"0";s:17:"printlastmodified";s:1:"1";}',
                ]],
            ],
            'structure_tables' => ['page' => 'page'],
            'structure_aliases' => [],
            'files' => [],
        ];
        return array_merge($defaults, $overrides);
    }

    /**
     * The page is addressed to the page module, with the text of the agent in the editor field the module reads.
     */
    public function test_the_text_of_the_agent_goes_into_the_page_editor(): void {
        $this->resetAfterTest(true);

        $info = agent_page_moduleinfo::build($this->parameters(), 0);

        $this->assertSame('page', $info['modulename']);
        $this->assertSame('Guia Didactica', $info['name']);
        $this->assertSame('<p>Texto escrito por la IA</p>', $info['page']['text']);
        $this->assertSame(FORMAT_HTML, $info['page']['format']);
        $this->assertSame(0, $info['page']['itemid']);
    }

    /**
     * The description of the page comes from the agent and has its own editor field.
     */
    public function test_the_intro_of_the_agent_goes_into_the_intro_editor(): void {
        $this->resetAfterTest(true);

        $info = agent_page_moduleinfo::build($this->parameters(['intro' => '<p>Resumen</p>']), 0);

        $this->assertSame('<p>Resumen</p>', $info['introeditor']['text']);
        $this->assertSame(FORMAT_HTML, $info['introeditor']['format']);
    }

    /**
     * How the page is displayed is what the template page said, not a default.
     */
    public function test_the_display_settings_are_the_ones_of_the_template_page(): void {
        $this->resetAfterTest(true);
        $structure = $this->parameters()['structure'];
        $structure['page'][0]['display'] = '6';
        $structure['page'][0]['displayoptions'] = 'a:4:{s:10:"popupwidth";s:3:"800";s:11:"popupheight";s:3:"600";'
            . 's:10:"printintro";s:1:"1";s:17:"printlastmodified";s:1:"0";}';

        $info = agent_page_moduleinfo::build($this->parameters(['structure' => $structure]), 0);

        $this->assertSame(6, $info['display']);
        $this->assertSame(800, $info['popupwidth']);
        $this->assertSame(600, $info['popupheight']);
        $this->assertSame('1', $info['printintro']);
        $this->assertSame('0', $info['printlastmodified']);
    }

    /**
     * A template page that has no display options still gives a valid page.
     */
    public function test_a_page_without_display_options_gets_the_moodle_defaults(): void {
        $this->resetAfterTest(true);
        $structure = $this->parameters()['structure'];
        unset($structure['page'][0]['displayoptions']);

        $info = agent_page_moduleinfo::build($this->parameters(['structure' => $structure]), 0);

        $this->assertSame(5, $info['display']);
        $this->assertSame('0', $info['printintro']);
        $this->assertSame('1', $info['printlastmodified']);
    }

    /**
     * A broken serialized value of the display options does not stop the page from being created.
     */
    public function test_garbage_in_the_display_options_falls_back_to_the_defaults(): void {
        $this->resetAfterTest(true);
        $structure = $this->parameters()['structure'];
        $structure['page'][0]['displayoptions'] = 'not serialized at all';

        $info = agent_page_moduleinfo::build($this->parameters(['structure' => $structure]), 0);

        $this->assertSame('0', $info['printintro']);
        $this->assertSame('1', $info['printlastmodified']);
    }

    /**
     * Without a structure the page is still built from what the agent wrote.
     */
    public function test_a_result_without_a_structure_is_built_from_the_text_alone(): void {
        $this->resetAfterTest(true);
        $parameters = $this->parameters();
        unset($parameters['structure']);

        $info = agent_page_moduleinfo::build($parameters, 0);

        $this->assertSame('<p>Texto escrito por la IA</p>', $info['page']['text']);
        $this->assertSame(5, $info['display']);
    }

    /**
     * A page the agent left without text is refused, so no empty page is created.
     */
    public function test_a_page_without_text_is_refused(): void {
        $this->resetAfterTest(true);

        $this->expectException(\moodle_exception::class);

        agent_page_moduleinfo::build($this->parameters(['content' => '']), 0);
    }

    /**
     * A page the agent left without a name is refused.
     */
    public function test_a_page_without_a_name_is_refused(): void {
        $this->resetAfterTest(true);

        $this->expectException(\moodle_exception::class);

        agent_page_moduleinfo::build($this->parameters(['name' => '  ']), 0);
    }

    /**
     * Visibility and completion are the ones of the template activity, and the availability of the template is not copied.
     */
    public function test_the_activity_settings_are_the_ones_of_the_template_activity(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'visible' => 0,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => 1,
            'availability' => '{"op":"&","c":[{"type":"completion","cm":999,"e":1}],"showc":[true]}',
        ]);

        $info = agent_page_moduleinfo::build($this->parameters(), (int) $page->cmid);

        $this->assertSame(0, $info['visible']);
        $this->assertSame(COMPLETION_TRACKING_AUTOMATIC, $info['completion']);
        $this->assertSame(1, $info['completionview']);
        $this->assertSame(0, $info['completionexpected']);
        $this->assertSame('{"op":"&","c":[],"showc":[]}', $info['availabilityconditionsjson']);
    }

    /**
     * A template activity that no longer exists does not stop the page from being created.
     */
    public function test_a_missing_template_activity_gets_the_default_activity_settings(): void {
        $this->resetAfterTest(true);

        $info = agent_page_moduleinfo::build($this->parameters(), 987654);

        $this->assertSame(1, $info['visible']);
        $this->assertSame(COMPLETION_TRACKING_NONE, $info['completion']);
    }

    /**
     * The pieces of the backup structure that add_moduleinfo has no use for do not travel to it.
     */
    public function test_the_backup_structure_is_not_passed_to_add_moduleinfo(): void {
        $this->resetAfterTest(true);

        $info = agent_page_moduleinfo::build($this->parameters(), 0);

        $this->assertArrayNotHasKey('structure', $info);
        $this->assertArrayNotHasKey('structure_tables', $info);
        $this->assertArrayNotHasKey('structure_aliases', $info);
        $this->assertArrayNotHasKey('files', $info);
    }

    /**
     * Names and texts with markup and unicode go through untouched, so the page is the one the agent wrote.
     */
    public function test_unicode_and_markup_are_kept(): void {
        $this->resetAfterTest(true);
        $content = '<h2>Guía Didáctica 🎓</h2><iframe src="$@COURSEGENLINK*11340@$"></iframe>';

        $info = agent_page_moduleinfo::build($this->parameters(['content' => $content, 'name' => 'Guía 🎓']), 0);

        $this->assertSame($content, $info['page']['text']);
        $this->assertSame('Guía 🎓', $info['name']);
    }
}
