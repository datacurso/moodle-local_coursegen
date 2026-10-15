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

use local_coursegen\local\models\template;
use local_coursegen\local\service\template_config_transfer;
use local_coursegen\local\service\template_persistence_service;

/**
 * Exporting a template and importing it over a course that has the same structure.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_config_transfer
 */
final class template_config_transfer_test extends \advanced_testcase {
    /**
     * A course with a page and a forum in its first section, and a template that keeps the page
     * and has one instance of the forum with an instruction.
     *
     * @return array{0: \stdClass, 1: int} The course and the template's id.
     */
    private function create_template(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 1]);
        $page = $generator->create_module('page', ['course' => $course->id, 'section' => 1, 'name' => 'Intro']);
        $forum = $generator->create_module('forum', ['course' => $course->id, 'section' => 1, 'name' => 'Talk']);
        $sectionid = (int) get_fast_modinfo($course)->get_section_info(1)->id;

        $template = new template(0);
        $template->set('name', 'Original');
        $template->set('courseid', (int) $course->id);
        $template->create();
        template_persistence_service::save_sections((int) $template->get('id'), [[
            'sectionid' => $sectionid,
            'sectionnum' => 1,
            'behavior' => 'aimodify',
            'activities' => [
                ['cmid' => (int) $page->cmid, 'action' => 'keep', 'useasreference' => true,
                    'templatescope' => 'course', 'prompt' => ''],
            ],
            'instances' => [[
                'sourcecmid' => (int) $forum->cmid, 'sourcename' => 'Talk', 'name' => 'Talk', 'typelabel' => 'Forum',
                'modname' => 'forum', 'prompt' => 'Ask about the topic', 'aftercmid' => (int) $forum->cmid,
                'sortorder' => 0,
            ]],
            'spaces' => [],
        ]]);
        return [$course, (int) $template->get('id')];
    }

    /**
     * What an export holds can be imported over the same course without any problem.
     */
    public function test_an_export_matches_its_own_course(): void {
        [$course, $templateid] = $this->create_template();

        $data = template_config_transfer::parse(json_encode(template_config_transfer::export($templateid)));
        $result = template_config_transfer::import((int) $course->id, $data, '', true);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['problems']);
        $this->assertSame(1, $result['counts']['instances']);
    }

    /**
     * Importing rebuilds the template with its instruction, under the name asked for.
     */
    public function test_import_rebuilds_the_template_with_its_instruction(): void {
        global $DB;

        [$course, $templateid] = $this->create_template();
        $data = template_config_transfer::export($templateid);

        $result = template_config_transfer::import((int) $course->id, $data, 'Imported');

        $this->assertTrue($result['ok']);
        $this->assertSame($templateid, $result['templateid']);
        $this->assertSame('Imported', $DB->get_field('local_coursegen_template', 'name', ['id' => $templateid]));
        $prompts = $DB->get_fieldset_select('local_coursegen_tpl_instance', 'prompt', 'templateid = ?', [$templateid]);
        $this->assertSame(['Ask about the topic'], array_values($prompts));
    }

    /**
     * A course that lacks an activity of the template gets nothing written, and the activity is named.
     */
    public function test_a_missing_activity_stops_the_import(): void {
        global $DB;

        [$course, $templateid] = $this->create_template();
        $data = template_config_transfer::export($templateid);
        $forum = array_values(get_fast_modinfo($course)->get_instances_of('forum'))[0];
        course_delete_module($forum->id);
        $DB->set_field('local_coursegen_template', 'name', 'Untouched', ['id' => $templateid]);

        $result = template_config_transfer::import((int) $course->id, $data, 'Changed');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['templateid']);
        $this->assertSame('forum', $result['problems'][0]['modname']);
        $this->assertSame('Talk', $result['problems'][0]['name']);
        $this->assertSame('Untouched', $DB->get_field('local_coursegen_template', 'name', ['id' => $templateid]));
    }

    /**
     * Content that is not a configuration of this plugin is refused.
     */
    public function test_a_file_that_is_not_a_configuration_is_refused(): void {
        $this->expectException(\moodle_exception::class);
        template_config_transfer::parse('{"format": 99}');
    }
}
