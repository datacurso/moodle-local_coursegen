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

use local_coursegen\local\models\template_instance;
use local_coursegen\output\sections_config;

/**
 * save_template's persistence of virtual template instances, and
 * sections_config's hydration of them back into the review in their saved
 * position — round-trip, removal on resave, and ordering by
 * anchor/sortorder. The "source no longer valid" degrade path is covered
 * separately in save_template_instances_degrade_test.php.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\save_template
 * @covers     \local_coursegen\local\service\template_persistence_service
 * @covers     \local_coursegen\output\sections_config
 *
 * @runTestsInSeparateProcesses
 */
final class save_template_instances_test extends \advanced_testcase {
    use sections_config_fixture_trait;

    /**
     * A saved instance persists every field and renders back into the
     * review, positioned immediately after its anchor, with its badge
     * showing the snapshotted source name.
     */
    public function test_persists_and_hydrates_instance_round_trip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page, $forum] = $this->create_course_fixture();
        $section2 = get_fast_modinfo($course)->get_section_info(2);

        $saved = $this->save_with_instances(
            (int) $course->id,
            (int) $section2->id,
            2,
            [
                ['cmid' => (int) $forum->cmid, 'action' => 'keep', 'useasreference' => true, 'prompt' => ''],
            ],
            [$this->instance_payload((int) $forum->cmid, 'Forum template', 'Discussion 1', (int) $forum->cmid, 0)]
        );

        $record = template_instance::get_record(['templateid' => (int) $saved['id']]);
        $this->assertNotFalse($record);
        $this->assertSame((int) $forum->cmid, (int) $record->get('sourcecmid'));
        $this->assertSame('Forum template', $record->get('sourcename'));
        $this->assertSame('Discussion 1', $record->get('name'));
        $this->assertSame('Page', $record->get('typelabel'));
        $this->assertSame((int) $forum->cmid, (int) $record->get('anchorcmid'));

        $html = sections_config::render(get_fast_modinfo($course), (int) $saved['id']);

        $instanceid = (int) $record->get('id');
        $this->assertStringContainsString('data-instance-id="' . $instanceid . '"', $html);
        $this->assertStringContainsString('value="Discussion 1"', $html);
        $badge = get_string('template_instance_badge', 'local_coursegen', 'Forum template');
        $this->assertStringContainsString($badge, $this->extract_instance_area($html, $instanceid));

        // The instance renders AFTER the forum row it is anchored to.
        $forumpos = strpos($html, 'data-id="' . $forum->cmid . '"');
        $instancepos = strpos($html, 'data-instance-id="' . $instanceid . '"');
        $this->assertLessThan($instancepos, $forumpos, 'Instance must render after its anchor row');
    }

    /**
     * Re-saving a template with fewer instances than before deletes the
     * dropped one — the same full replace-all-children pattern
     * template_activity/template_section already follow.
     */
    public function test_resaving_without_an_instance_deletes_it(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();
        $section1 = get_fast_modinfo($course)->get_section_info(1);

        $saved = $this->save_with_instances(
            (int) $course->id,
            (int) $section1->id,
            1,
            [['cmid' => (int) $page->cmid, 'action' => 'template', 'useasreference' => true, 'prompt' => '']],
            [$this->instance_payload((int) $page->cmid, 'Page template', 'Lesson 1')]
        );
        $this->assertCount(1, template_instance::get_records(['templateid' => (int) $saved['id']]));

        $this->save_with_instances(
            (int) $course->id,
            (int) $section1->id,
            1,
            [['cmid' => (int) $page->cmid, 'action' => 'template', 'useasreference' => true, 'prompt' => '']],
            []
        );
        $this->assertCount(0, template_instance::get_records(['templateid' => (int) $saved['id']]));
    }

    /**
     * Two instances sharing the same anchor keep their relative sortorder
     * after a full save/hydrate round trip, not just in the pure-logic
     * unit test (template_instance_layout_test).
     */
    public function test_instance_order_survives_save_and_hydrate(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();
        $section1 = get_fast_modinfo($course)->get_section_info(1);

        $saved = $this->save_with_instances(
            (int) $course->id,
            (int) $section1->id,
            1,
            [['cmid' => (int) $page->cmid, 'action' => 'template', 'useasreference' => true, 'prompt' => '']],
            [
                $this->instance_payload((int) $page->cmid, 'Page template', 'Second', (int) $page->cmid, 2),
                $this->instance_payload((int) $page->cmid, 'Page template', 'First', (int) $page->cmid, 1),
            ]
        );

        $html = sections_config::render(get_fast_modinfo($course), (int) $saved['id']);
        $firstpos = strpos($html, 'value="First"');
        $secondpos = strpos($html, 'value="Second"');
        $this->assertNotFalse($firstpos);
        $this->assertNotFalse($secondpos);
        $this->assertLessThan($secondpos, $firstpos);
    }
}
