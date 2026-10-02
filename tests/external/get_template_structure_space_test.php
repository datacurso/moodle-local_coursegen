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

use local_coursegen\external\get_template_structure;
use local_coursegen\local\models\template;
use local_coursegen\local\models\template_activity;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../sections_config_fixture_trait.php');

/**
 * How get_template_structure shows a file resource the template marked as a space for the teacher.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\get_template_structure
 *
 * @runTestsInSeparateProcesses
 */
final class get_template_structure_space_test extends \advanced_testcase {
    use sections_config_fixture_trait;

    /**
     * A template of a course, with the given actions saved by cmid.
     *
     * @param \stdClass $course
     * @param array $actions Cmid => [action, instruction, required].
     * @return int The template id.
     */
    private function make_template(\stdClass $course, array $actions): int {
        $template = new template(0, (object) ['name' => 'Space fixture', 'courseid' => $course->id]);
        $template->create();
        $templateid = (int) $template->get('id');
        $modinfo = get_fast_modinfo($course);
        foreach ($actions as $cmid => $saved) {
            $cm = $modinfo->get_cm($cmid);
            $record = [
                'templateid' => $templateid,
                'sectionid' => (int) $cm->get_section_info()->id,
                'cmid' => $cmid,
                'action' => $saved[0],
                'spaceinstruction' => $saved[1],
                'spacerequired' => $saved[2],
            ];
            (new template_activity(0, (object) $record))->create();
        }
        return $templateid;
    }

    /**
     * The rows of a section, by activity id.
     *
     * @param array $result
     * @param int $sectionnum
     * @return array
     */
    private function rows_by_id(array $result, int $sectionnum): array {
        $rows = [];
        foreach ($result['sections'] as $section) {
            if ((int) $section['num'] === $sectionnum) {
                $rows = array_column($section['activities'], null, 'id');
            }
        }
        return $rows;
    }

    /**
     * A file resource marked as a space is returned as a locked space row, with its label and requirement.
     */
    public function test_a_resource_space_is_a_locked_space_row(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course] = $this->create_course_fixture();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id, 'section' => 1]);
        $templateid = $this->make_template($course, [$resource->cmid => ['space', 'Course guide in PDF', 1]]);

        $rows = $this->rows_by_id(get_template_structure::execute($templateid), 1);

        $row = $rows[(string) $resource->cmid];
        $this->assertTrue($row['isspace']);
        $this->assertTrue($row['locked']);
        $this->assertFalse($row['aigenerated']);
        $this->assertSame('resource', $row['modname']);
        $this->assertSame('Course guide in PDF', $row['spaceinstruction']);
        $this->assertTrue($row['spacerequired']);
    }

    /**
     * An optional space says so, and a space with no label has an empty one.
     */
    public function test_an_optional_space_without_label(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course] = $this->create_course_fixture();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id, 'section' => 1]);
        $templateid = $this->make_template($course, [$resource->cmid => ['space', null, 0]]);

        $rows = $this->rows_by_id(get_template_structure::execute($templateid), 1);

        $row = $rows[(string) $resource->cmid];
        $this->assertTrue($row['isspace']);
        $this->assertFalse($row['spacerequired']);
        $this->assertSame('', $row['spaceinstruction']);
    }

    /**
     * The space keeps the position of its activity among the others of its section.
     */
    public function test_the_space_keeps_its_position(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $page] = $this->create_course_fixture();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id, 'section' => 1]);
        $templateid = $this->make_template($course, [$resource->cmid => ['space', 'Guide', 0]]);

        $result = get_template_structure::execute($templateid);

        $ids = array_column($result['sections'][1]['activities'], 'id');
        $this->assertSame([(string) $page->cmid, (string) $resource->cmid], $ids);
    }

    /**
     * A space saved on an activity that is not a file resource is read as exclude: the teacher does not see it.
     */
    public function test_a_space_on_another_type_is_hidden(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $page, $forum] = $this->create_course_fixture();
        $templateid = $this->make_template($course, [
            $page->cmid => ['space', 'Not a file', 1],
            $forum->cmid => ['space', 'Not a file', 1],
        ]);

        $result = get_template_structure::execute($templateid);

        $this->assertArrayNotHasKey((string) $page->cmid, $this->rows_by_id($result, 1));
        $this->assertArrayNotHasKey((string) $forum->cmid, $this->rows_by_id($result, 2));
    }

    /**
     * Every other row says it is not a space.
     */
    public function test_a_kept_row_is_not_a_space(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $page] = $this->create_course_fixture();
        $templateid = $this->make_template($course, []);

        $rows = $this->rows_by_id(get_template_structure::execute($templateid), 1);

        $row = $rows[(string) $page->cmid];
        $this->assertFalse($row['isspace']);
        $this->assertFalse($row['spacerequired']);
        $this->assertSame('', $row['spaceinstruction']);
    }

    /**
     * The response no longer carries a catalog of activities to add.
     */
    public function test_the_response_has_no_activity_catalog(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course] = $this->create_course_fixture();
        $templateid = $this->make_template($course, []);

        $result = get_template_structure::execute($templateid);

        $this->assertArrayNotHasKey('allowedactivities', $result);
    }
}
