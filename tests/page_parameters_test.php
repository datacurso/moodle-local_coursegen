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

use local_coursegen\local\service\create_mod_service;
use local_coursegen\mod_parameters\page_parameters;

/**
 * A page written by the AI service as a standard activity is created as it is; only the template agent's page is rebuilt.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\mod_parameters\page_parameters
 * @covers     \local_coursegen\local\service\create_mod_service
 */
final class page_parameters_test extends \advanced_testcase {
    /**
     * The parameters of a standard page: its text sits in the editor fields, with no plain content field.
     *
     * @param array $overrides Values that replace the defaults.
     * @return array The parameters of the generated activity.
     */
    private function standard_parameters(array $overrides = []): array {
        $defaults = [
            'modulename' => 'page',
            'name' => 'Solar system',
            'section' => 1,
            'introeditor' => ['text' => '<p>Intro</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
            'page' => ['text' => '<h2>Planets</h2><p>Body</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
            'display' => 5,
            'printintro' => '0',
            'printlastmodified' => '1',
            'visible' => 1,
            'mod_settings' => [],
        ];
        return array_merge($defaults, $overrides);
    }

    /**
     * The parameters of a page the template agent wrote: its text is plain html in content and intro.
     *
     * @return array The parameters of the generated activity.
     */
    private function agent_parameters(): array {
        return [
            'name' => 'Guide',
            'content' => '<p>Written by the agent</p>',
            'intro' => '<p>Agent intro</p>',
        ];
    }

    /**
     * A standard page is handed to add_moduleinfo as it came.
     */
    public function test_a_standard_page_is_left_as_it_is(): void {
        $parameters = (object) $this->standard_parameters();

        $result = (new page_parameters($parameters))->get_parameters();

        $this->assertSame($parameters, $result);
        $this->assertSame('<h2>Planets</h2><p>Body</p>', $result->page['text']);
    }

    /**
     * A standard page whose editor field has already been turned into an object is left as it is too.
     */
    public function test_a_page_editor_given_as_an_object_is_left_as_it_is(): void {
        $parameters = (object) $this->standard_parameters(['page' => (object) ['text' => '<p>Body</p>']]);

        $result = (new page_parameters($parameters))->get_parameters();

        $this->assertSame($parameters, $result);
    }

    /**
     * The page of the template agent is still built from its plain html.
     */
    public function test_an_agent_page_is_still_built_from_its_content(): void {
        $parameters = (object) $this->agent_parameters();

        $result = (new page_parameters($parameters))->get_parameters();

        $this->assertSame('page', $result->modulename);
        $this->assertSame('<p>Written by the agent</p>', $result->page['text']);
        $this->assertSame('<p>Agent intro</p>', $result->introeditor['text']);
    }

    /**
     * An agent page with no text is refused, as before.
     */
    public function test_an_agent_page_without_text_is_refused(): void {
        $parameters = (object) ['name' => 'Guide', 'content' => ' ', 'intro' => ''];

        $this->expectException(\moodle_exception::class);

        (new page_parameters($parameters))->get_parameters();
    }

    /**
     * A page editor with no text is not a standard page, so the agent path refuses it.
     */
    public function test_a_page_editor_without_text_is_not_a_standard_page(): void {
        $parameters = (object) ['name' => 'Guide', 'page' => ['format' => FORMAT_HTML]];

        $this->expectException(\moodle_exception::class);

        (new page_parameters($parameters))->get_parameters();
    }

    /**
     * Creating a standard page through the service stores its name, intro and text.
     */
    public function test_a_standard_page_is_created_through_the_service(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $PAGE->set_course($course);
        $result = ['resource_type' => 'page', 'cmid' => 0, 'parameters' => $this->standard_parameters()];

        $newcm = create_mod_service::create_from_ai_result($result, $course, 1);

        $page = $DB->get_record('page', ['id' => $newcm->instance], '*', MUST_EXIST);
        $this->assertSame('Solar system', $page->name);
        $this->assertStringContainsString('<h2>Planets</h2>', $page->content);
        $this->assertStringContainsString('Intro', $page->intro);
    }

    /**
     * A standard page whose text carries an image tag keeps the tag.
     */
    public function test_a_standard_page_with_an_image_keeps_its_tag(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $PAGE->set_course($course);
        $text = '<p>Sun</p><img src="/generated_images/abc/sun.png" alt="Sun">';
        $parameters = $this->standard_parameters(['page' => ['text' => $text, 'format' => FORMAT_HTML, 'itemid' => 0]]);

        $newcm = create_mod_service::create_from_ai_result(
            ['resource_type' => 'page', 'cmid' => 0, 'parameters' => $parameters],
            $course,
            1
        );

        $page = $DB->get_record('page', ['id' => $newcm->instance], '*', MUST_EXIST);
        $this->assertStringContainsString('<img src="/generated_images/abc/sun.png"', $page->content);
    }
}
