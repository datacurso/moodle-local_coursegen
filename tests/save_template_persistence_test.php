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

use local_coursegen\external\save_template;
use local_coursegen\local\models\template;
use local_coursegen\local\models\template_activity;
use local_coursegen\local\models\template_section;

/**
 * Persistence contract of the save_template web service.
 *
 * The create-template form collects limits, allowed types, naming rules and
 * one behavior/action choice per section/activity; every one of those values
 * must survive the round trip to the database, both on create and on re-save.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\save_template
 * @runTestsInSeparateProcesses
 */
final class save_template_persistence_test extends \advanced_testcase {
    /**
     * Build a base course with one page in section 1 and one forum in section 2.
     *
     * @return array [course, pagecm, forumcm, sectionids]
     */
    private function create_base_course(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 2]);
        $page = $generator->create_module('page', ['course' => $course->id, 'section' => 1]);
        $forum = $generator->create_module('forum', ['course' => $course->id, 'section' => 2]);

        $sectionids = $DB->get_records_menu('course_sections', ['course' => $course->id], 'section', 'section, id');

        return [$course, $page, $forum, $sectionids];
    }

    /**
     * A full payload from the form is stored field by field.
     */
    public function test_every_configured_value_is_persisted(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page, $forum, $sectionids] = $this->create_base_course();

        $result = save_template::execute(
            0,
            'Virtual course template',
            'Institutional base template',
            (int) $course->id,
            3,
            false,
            json_encode(['page', 'forum']),
            'Unit {N} — {name}',
            0,
            [
                [
                    'sectionid' => (int) $sectionids[1],
                    'sectionnum' => 1,
                    'behavior' => 'custom',
                    'activities' => [
                        [
                            'cmid' => (int) $page->cmid,
                            'action' => 'modify',
                            'useasreference' => true,
                            'prompt' => 'Rewrite this page for nursing students',
                        ],
                    ],
                ],
                [
                    'sectionid' => (int) $sectionids[2],
                    'sectionnum' => 2,
                    'behavior' => 'keep',
                    'activities' => [
                        [
                            'cmid' => (int) $forum->cmid,
                            'action' => 'keep',
                            'useasreference' => false,
                            'prompt' => '',
                        ],
                    ],
                ],
            ]
        );

        $tpl = new template($result['id']);
        $this->assertSame('Virtual course template', $tpl->get('name'));
        $this->assertSame('Institutional base template', $tpl->get('description'));
        $this->assertSame((int) $course->id, (int) $tpl->get('courseid'));
        $this->assertSame(3, (int) $tpl->get('maxsections'));
        $this->assertSame(0, (int) $tpl->get('nolimit'));
        $this->assertSame(['page', 'forum'], json_decode($tpl->get('allowedtypes')));
        $this->assertSame('Unit {N} — {name}', $tpl->get('namingpattern'));
        $this->assertSame(0, (int) $tpl->get('namingstart'));

        $sections = template_section::get_records(['templateid' => $result['id']], 'sectionnum');
        $this->assertCount(2, $sections);
        $behaviors = array_map(static fn($s) => $s->get('behavior'), array_values($sections));
        $this->assertSame(['custom', 'keep'], $behaviors);

        $pagerow = template_activity::get_record(['templateid' => $result['id'], 'cmid' => (int) $page->cmid]);
        $this->assertSame('modify', $pagerow->get('action'));
        $this->assertSame(1, (int) $pagerow->get('useasreference'));
        $this->assertSame('Rewrite this page for nursing students', $pagerow->get('prompt'));

        $forumrow = template_activity::get_record(['templateid' => $result['id'], 'cmid' => (int) $forum->cmid]);
        $this->assertSame('keep', $forumrow->get('action'));
        $this->assertSame(0, (int) $forumrow->get('useasreference'));
        $this->assertSame('', (string) $forumrow->get('prompt'));
    }

    /**
     * When the teacher may not add sections the form sends 0: stored as NULL,
     * which downstream reads as zero extra sections.
     */
    public function test_zero_extra_sections_is_stored_as_null(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page, , $sectionids] = $this->create_base_course();

        $result = save_template::execute(
            0,
            'No extras',
            '',
            (int) $course->id,
            0,
            false,
            '[]',
            '',
            1,
            [[
                'sectionid' => (int) $sectionids[1],
                'sectionnum' => 1,
                'behavior' => 'custom',
                'activities' => [[
                    'cmid' => (int) $page->cmid,
                    'action' => 'keep',
                    'useasreference' => true,
                    'prompt' => '',
                ]],
            ]]
        );

        $tpl = new template($result['id']);
        $this->assertNull($tpl->get('maxsections'));
    }

    /**
     * Re-saving an existing template replaces its configuration instead of
     * stacking duplicated child rows.
     */
    public function test_resave_replaces_children_and_updates_fields(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page, $forum, $sectionids] = $this->create_base_course();

        $payloadsection = [
            'sectionid' => (int) $sectionids[1],
            'sectionnum' => 1,
            'behavior' => 'custom',
            'activities' => [[
                'cmid' => (int) $page->cmid,
                'action' => 'modify',
                'useasreference' => true,
                'prompt' => 'First prompt',
            ]],
        ];

        $first = save_template::execute(
            0,
            'Before edit',
            '',
            (int) $course->id,
            1,
            false,
            '["page"]',
            '',
            1,
            [$payloadsection]
        );

        $payloadsection['behavior'] = 'exclude';
        $payloadsection['activities'][0]['action'] = 'reference';
        $payloadsection['activities'][0]['prompt'] = 'Second prompt';
        $payloadsection['activities'][] = [
            'cmid' => (int) $forum->cmid,
            'action' => 'keep',
            'useasreference' => false,
            'prompt' => '',
        ];

        $second = save_template::execute(
            $first['id'],
            'After edit',
            'Updated',
            (int) $course->id,
            2,
            false,
            '["page","forum"]',
            '',
            1,
            [$payloadsection]
        );

        $this->assertSame($first['id'], $second['id']);

        $tpl = new template($second['id']);
        $this->assertSame('After edit', $tpl->get('name'));
        $this->assertSame(2, (int) $tpl->get('maxsections'));

        $this->assertCount(1, template_section::get_records(['templateid' => $second['id']]));
        $activities = template_activity::get_records(['templateid' => $second['id']]);
        $this->assertCount(2, $activities);

        $pagerow = template_activity::get_record(['templateid' => $second['id'], 'cmid' => (int) $page->cmid]);
        $this->assertSame('reference', $pagerow->get('action'));
        $this->assertSame('Second prompt', $pagerow->get('prompt'));
    }
}
