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

namespace local_coursegen\external;

use local_coursegen\local\template\template_actions;
use local_coursegen\local\template\template_service;
use local_coursegen\template_test_helper;

/**
 * The professor-facing view of a saved template (get_template_structure): the sections of the template
 * course with their activities, and the ones the AI modifies marked.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\get_template_structure
 * @covers     \local_coursegen\external\get_template_structure_rows
 * @covers     \local_coursegen\external\get_template_structure_schema
 */
final class get_template_structure_view_test extends \advanced_testcase {
    use template_test_helper;

    /** @var template_service Service used to save the templates of the tests. */
    private template_service $service;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->service = new template_service();
    }

    /**
     * The view of a template, checked against the contract of the service.
     *
     * @param int $templateid
     * @return array
     */
    private function view(int $templateid): array {
        $result = get_template_structure::execute($templateid);
        $returns = get_template_structure::execute_returns();
        return \core_external\external_api::clean_returnvalue($returns, $result);
    }

    /**
     * The rows of one section of a view.
     *
     * @param array $view
     * @param int $number
     * @return array
     */
    private function rows(array $view, int $number): array {
        foreach ($view['sections'] as $section) {
            if ($section['num'] === $number) {
                return $section['activities'];
            }
        }
        return [];
    }

    public function test_the_view_has_no_section_limit_any_more(): void {
        [$course] = $this->make_course();

        $templateid = $this->save_items($course, []);

        $view = $this->view($templateid);

        $keys = array_keys($view);
        $this->assertSame(['sections'], $keys);
    }

    public function test_every_section_of_the_course_is_listed_in_order(): void {
        [$course] = $this->make_course();

        $templateid = $this->save_items($course, []);

        $view = $this->view($templateid);

        $numbers = array_column($view['sections'], 'num');
        $this->assertSame([0, 1, 2], $numbers);
    }

    public function test_an_activity_with_nothing_saved_is_kept_and_locked(): void {
        [$course, $page] = $this->make_course();

        $templateid = $this->save_items($course, []);

        $view = $this->view($templateid);

        $row = $this->rows($view, 1)[0];
        $this->assertSame((string) $page, $row['id']);
        $this->assertSame('keep', $row['action']);
        $this->assertTrue($row['locked']);
        $this->assertFalse($row['aigenerated']);
    }

    public function test_an_activity_the_ai_modifies_is_marked_and_not_locked(): void {
        [$course, $page] = $this->make_course();
        $items = [['cmid' => $page, 'action' => template_actions::AI, 'instruction' => 'Update it']];

        $templateid = $this->save_items($course, $items);

        $view = $this->view($templateid);

        $row = $this->rows($view, 1)[0];
        $this->assertSame('modify', $row['action']);
        $this->assertFalse($row['locked']);
        $this->assertTrue($row['aigenerated']);
    }

    public function test_a_section_with_a_modified_activity_is_not_locked_and_the_others_are(): void {
        [$course, $page] = $this->make_course();
        $items = [['cmid' => $page, 'action' => template_actions::AI, 'instruction' => '']];

        $templateid = $this->save_items($course, $items);

        $view = $this->view($templateid);

        $bynumber = array_column($view['sections'], null, 'num');
        $this->assertSame('aimodify', $bynumber[1]['behavior']);
        $this->assertFalse($bynumber[1]['locked']);
        $this->assertSame('keep', $bynumber[2]['behavior']);
        $this->assertTrue($bynumber[2]['locked']);
    }

    public function test_a_hidden_activity_does_not_reach_a_student(): void {
        [$course, $page, $quiz] = $this->make_course();
        $templateid = $this->save_items($course, []);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $this->assignUserCapability('local/coursegen:createtemplatecoursewithai', \context_system::instance()->id);

        $view = $this->view($templateid);

        $rows = $this->rows($view, 1);
        $ids = array_column($rows, 'id');
        $this->assertSame([(string) $page], $ids);
        $this->assertNotContains((string) $quiz, $ids);
    }

    public function test_a_row_has_no_space_or_instance_fields_any_more(): void {
        [$course] = $this->make_course();

        $templateid = $this->save_items($course, []);

        $view = $this->view($templateid);

        $row = $this->rows($view, 1)[0];
        $this->assertArrayNotHasKey('isspace', $row);
        $this->assertArrayNotHasKey('spacerequired', $row);
        $this->assertFalse($row['isinstance']);
        $this->assertSame('', $row['generationuid']);
    }

    public function test_the_view_of_an_unknown_template_is_refused(): void {
        $this->expectException(\moodle_exception::class);

        get_template_structure::execute(987654);
    }

    public function test_a_user_without_the_capability_cannot_see_the_view(): void {
        [$course] = $this->make_course();
        $templateid = $this->save_items($course, []);
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);

        get_template_structure::execute($templateid);
    }
}
