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

use local_coursegen\external\get_template_reference_slots;
use local_coursegen\local\models\template;
use local_coursegen\local\models\template_activity;
use local_coursegen\local\reference\reference_file_storage;
use local_coursegen\local\reference\reference_slot_listing;

/**
 * The rows the teacher sees for the places of a template, and the web service that gives them.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\reference\reference_slot_listing
 * @covers     \local_coursegen\external\get_template_reference_slots
 */
final class reference_slot_listing_test extends \advanced_testcase {
    /**
     * A template whose page holds a document place and an image place.
     *
     * @return array{0: int, 1: \stdClass} The template and the page.
     */
    private function template_with_two_places(): array {
        $course = $this->getDataGenerator()->create_course();
        $content = '[[coursegen:reference: Guide]]<iframe src="@@PLUGINFILE@@/guide.pdf"></iframe>'
            . '[[coursegen:reference: Cover]]<img src="@@PLUGINFILE@@/cover.png">';
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Unit one',
            'content' => $content,
        ]);
        $template = new template(0, (object) ['name' => 'Test template', 'courseid' => $course->id]);
        $template->create();
        $activity = new template_activity(0, (object) [
            'templateid' => $template->get('id'),
            'sectionid' => 0,
            'cmid' => $page->cmid,
            'action' => template_activity::ACTION_TEMPLATE,
        ]);
        $activity->create();
        return [(int) $template->get('id'), $page];
    }

    /**
     * Each place is a row with its label, its activity and what it accepts, empty until a file is brought.
     */
    public function test_each_place_is_a_row_without_a_file_at_first(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        [$templateid, $page] = $this->template_with_two_places();

        $rows = reference_slot_listing::rows((int) $user->id, $templateid);

        $this->assertCount(2, $rows);
        $this->assertSame($page->cmid . '.1', $rows[0]['key']);
        $this->assertSame('Guide', $rows[0]['instruction']);
        $this->assertSame('Unit one', $rows[0]['activityname']);
        $this->assertSame('', $rows[0]['filename']);
        $this->assertFalse($rows[0]['hasfile']);
        $this->assertStringContainsString('.pdf', $rows[0]['accept']);
        $this->assertStringContainsString('.png', $rows[1]['accept']);
        $this->assertStringNotContainsString('.pdf', $rows[1]['accept']);
    }

    /**
     * A place that already has a file shows its name, and only for the teacher who brought it.
     */
    public function test_a_place_with_a_file_shows_its_name_to_its_teacher_only(): void {
        $this->resetAfterTest(true);
        $teacher = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        [$templateid, $page] = $this->template_with_two_places();
        $directory = make_request_directory();
        $path = $directory . '/upload.tmp';
        file_put_contents($path, 'PDFDATA');
        reference_file_storage::stage((int) $teacher->id, $templateid, $page->cmid . '.1', 'mine.pdf', $path);

        $mine = reference_slot_listing::rows((int) $teacher->id, $templateid);
        $theirs = reference_slot_listing::rows((int) $other->id, $templateid);

        $this->assertSame('mine.pdf', $mine[0]['filename']);
        $this->assertTrue($mine[0]['hasfile']);
        $this->assertSame('', $theirs[0]['filename']);
    }

    /**
     * The web service answers with the rows of the logged in teacher.
     */
    public function test_the_web_service_lists_the_rows(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        [$templateid] = $this->template_with_two_places();

        $result = get_template_reference_slots::execute($templateid);

        $this->assertTrue($result['hasslots']);
        $this->assertCount(2, $result['slots']);
    }

    /**
     * A template with no place says so.
     */
    public function test_a_template_without_places_has_none(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $template = new template(0, (object) ['name' => 'Empty template', 'courseid' => $course->id]);
        $template->create();

        $result = get_template_reference_slots::execute((int) $template->get('id'));

        $this->assertFalse($result['hasslots']);
        $this->assertSame([], $result['slots']);
    }
}
