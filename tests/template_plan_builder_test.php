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

use local_coursegen\local\placeholder\template_plan_builder;

/**
 * Turning the sections of a course and what was found in their activities into the sections a template saves.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\placeholder\template_plan_builder
 */
final class template_plan_builder_test extends \basic_testcase {
    /**
     * One activity of a course structure.
     *
     * @param int $cmid Course module id.
     * @param string $modname Module type.
     * @param int $placeholders Placeholders found in it.
     * @param string $name Its name.
     * @return array
     */
    private static function activity(int $cmid, string $modname, int $placeholders, string $name = ''): array {
        $label = $name;
        if ($label === '') {
            $label = $modname . ' ' . $cmid;
        }
        $typelabel = ucfirst($modname);
        return [
            'cmid' => $cmid,
            'modname' => $modname,
            'name' => $label,
            'typelabel' => $typelabel,
            'placeholders' => $placeholders,
        ];
    }

    /**
     * One section of a course structure.
     *
     * @param int $id Section id.
     * @param int $num Section number.
     * @param array[] $activities Its activities.
     * @return array
     */
    private static function section(int $id, int $num, array $activities): array {
        return ['sectionid' => $id, 'sectionnum' => $num, 'activities' => $activities];
    }

    /**
     * The plan of a course with one section.
     *
     * @param array[] $activities The activities of the section.
     * @param string $scope The scope of the molds.
     * @return array
     */
    private static function plan_of_one_section(array $activities, string $scope = 'course'): array {
        $section = self::section(10, 0, $activities);
        return template_plan_builder::build([$section], $scope);
    }

    /**
     * An activity with placeholders becomes a mold, gets one instance right after it, and its section may be modified.
     */
    public function test_a_placeholder_activity_becomes_a_mold_with_one_instance(): void {
        $label = self::activity(101, 'label', 0);
        $guide = self::activity(102, 'page', 3, 'Guide');
        $forum = self::activity(103, 'forum', 0);
        $first = self::section(10, 0, [$label, $guide]);
        $second = self::section(11, 1, [$forum]);

        $plan = template_plan_builder::build([$first, $second], 'course');

        $built = $plan['sections'][0];
        $this->assertSame('aimodify', $built['behavior']);
        $this->assertSame('keep', $built['activities'][0]['action']);
        $this->assertSame('template', $built['activities'][1]['action']);
        $this->assertCount(1, $built['instances']);
        $instance = $built['instances'][0];
        $this->assertSame(102, $instance['sourcecmid']);
        $this->assertSame(102, $instance['aftercmid']);
        $this->assertSame('Guide', $instance['name']);
        $this->assertSame('Guide', $instance['sourcename']);
        $this->assertSame('Page', $instance['typelabel']);
        $this->assertSame('page', $instance['modname']);
        $this->assertSame('', $instance['prompt']);
        $this->assertSame('keep', $plan['sections'][1]['behavior']);
        $this->assertSame([], $plan['sections'][1]['instances']);
        $this->assertSame([102], $plan['molds']);
    }

    /**
     * Every activity of the course is listed in its section, in order, with the keep action by default.
     */
    public function test_every_activity_is_listed_in_order(): void {
        $first = self::activity(1, 'page', 0);
        $second = self::activity(2, 'lesson', 2);
        $third = self::activity(3, 'forum', 0);

        $plan = self::plan_of_one_section([$first, $second, $third]);

        $listed = $plan['sections'][0]['activities'];
        $cmids = array_column($listed, 'cmid');
        $actions = array_column($listed, 'action');
        $this->assertSame([1, 2, 3], $cmids);
        $this->assertSame(['keep', 'template', 'keep'], $actions);
        $this->assertSame(2, $plan['kept']);
    }

    /**
     * Two molds in one section get an instance each, in a stable order.
     */
    public function test_two_molds_in_a_section_get_ordered_instances(): void {
        $first = self::activity(1, 'page', 1);
        $second = self::activity(2, 'page', 1);

        $plan = self::plan_of_one_section([$first, $second]);

        $instances = $plan['sections'][0]['instances'];
        $sources = array_column($instances, 'sourcecmid');
        $anchors = array_column($instances, 'aftercmid');
        $orders = array_column($instances, 'sortorder');
        $this->assertSame([1, 2], $sources);
        $this->assertSame([1, 2], $anchors);
        $this->assertSame([0, 1], $orders);
    }

    /**
     * Two activities with the same name stay two different instances.
     */
    public function test_two_activities_with_the_same_name_make_two_instances(): void {
        $first = self::activity(1, 'page', 1, 'Same');
        $second = self::activity(2, 'page', 1, 'Same');

        $plan = self::plan_of_one_section([$first, $second]);

        $instances = $plan['sections'][0]['instances'];
        $names = array_column($instances, 'name');
        $sources = array_column($instances, 'sourcecmid');
        $this->assertSame(['Same', 'Same'], $names);
        $this->assertSame([1, 2], $sources);
    }

    /**
     * A type that cannot be a mold is kept and listed, never dropped.
     */
    public function test_an_unsupported_type_with_placeholders_is_kept_and_reported(): void {
        $chat = self::activity(1, 'chat', 4, 'Chat room');
        $page = self::activity(2, 'page', 1);

        $plan = self::plan_of_one_section([$chat, $page]);

        $this->assertSame('keep', $plan['sections'][0]['activities'][0]['action']);
        $this->assertSame([['cmid' => 1, 'modname' => 'chat', 'name' => 'Chat room']], $plan['unsupported']);
        $this->assertSame([2], $plan['molds']);
        $this->assertCount(1, $plan['sections'][0]['instances']);
    }

    /**
     * A section whose only placeholders are in an unsupported type stays in keep behavior.
     */
    public function test_a_section_with_no_mold_keeps_its_behavior(): void {
        $chat = self::activity(1, 'chat', 4);

        $plan = self::plan_of_one_section([$chat]);

        $this->assertSame('keep', $plan['sections'][0]['behavior']);
    }

    /**
     * The scope of the molds follows the option; the activities that are kept stay with the course scope.
     */
    public function test_the_scope_applies_to_the_molds(): void {
        $mold = self::activity(1, 'page', 1);
        $plain = self::activity(2, 'page', 0);

        $plan = self::plan_of_one_section([$mold, $plain], 'section');

        $this->assertSame('section', $plan['sections'][0]['activities'][0]['templatescope']);
        $this->assertSame('course', $plan['sections'][0]['activities'][1]['templatescope']);
    }

    /**
     * An unknown scope is refused.
     */
    public function test_an_unknown_scope_is_refused(): void {
        $section = self::section(10, 0, []);
        $this->expectException(\invalid_parameter_exception::class);

        template_plan_builder::build([$section], 'galaxy');
    }

    /**
     * A section with no activity is kept in the plan with the keep behavior.
     */
    public function test_an_empty_section_is_kept_in_the_plan(): void {
        $section = self::section(10, 3, []);

        $plan = template_plan_builder::build([$section], 'course');

        $this->assertSame(3, $plan['sections'][0]['sectionnum']);
        $this->assertSame('keep', $plan['sections'][0]['behavior']);
        $this->assertSame([], $plan['sections'][0]['activities']);
        $this->assertSame([], $plan['sections'][0]['instances']);
    }

    /**
     * A course with no section gives an empty plan.
     */
    public function test_a_course_without_sections_gives_an_empty_plan(): void {
        $plan = template_plan_builder::build([], 'course');

        $this->assertSame([], $plan['sections']);
        $this->assertSame([], $plan['molds']);
        $this->assertSame(0, $plan['kept']);
    }

    /**
     * A name longer than the column is cut by characters, not by bytes.
     */
    public function test_a_long_multibyte_name_is_cut_to_the_column_size(): void {
        $name = str_repeat('ñ📚', 200);
        $page = self::activity(1, 'page', 1, $name);

        $plan = self::plan_of_one_section([$page]);

        $instance = $plan['sections'][0]['instances'][0];
        $length = mb_strlen($instance['name']);
        $sourcelength = mb_strlen($instance['sourcename']);
        $valid = mb_check_encoding($instance['name'], 'UTF-8');
        $this->assertSame(255, $length);
        $this->assertSame(255, $sourcelength);
        $this->assertTrue($valid);
    }

    /**
     * An activity with no name gets one from its type and id.
     */
    public function test_a_nameless_activity_gets_a_name(): void {
        $activity = ['cmid' => 7, 'modname' => 'label', 'name' => '', 'typelabel' => 'Text', 'placeholders' => 1];

        $plan = self::plan_of_one_section([$activity]);

        $this->assertSame('label 7', $plan['sections'][0]['instances'][0]['name']);
    }

    /**
     * An activity name made only of spaces counts as no name.
     */
    public function test_a_blank_name_counts_as_no_name(): void {
        $activity = ['cmid' => 8, 'modname' => 'page', 'name' => "  \n ", 'typelabel' => 'Page', 'placeholders' => 1];

        $plan = self::plan_of_one_section([$activity]);

        $this->assertSame('page 8', $plan['sections'][0]['instances'][0]['name']);
    }

    /**
     * A course with five hundred activities is planned at once.
     */
    public function test_plans_five_hundred_activities(): void {
        $activities = [];
        for ($index = 1; $index <= 500; $index++) {
            $placeholders = $index % 2;
            $activities[] = self::activity($index, 'page', $placeholders);
        }

        $plan = self::plan_of_one_section($activities);

        $this->assertCount(500, $plan['sections'][0]['activities']);
        $this->assertCount(250, $plan['sections'][0]['instances']);
        $this->assertCount(250, $plan['molds']);
    }

    /**
     * The saved shape matches what the save service of the template reads.
     */
    public function test_the_activity_and_instance_keys_match_the_save_service(): void {
        $page = self::activity(1, 'page', 1);

        $plan = self::plan_of_one_section([$page]);

        $section = $plan['sections'][0];
        $sectionkeys = array_keys($section);
        $activitykeys = array_keys($section['activities'][0]);
        $instancekeys = array_keys($section['instances'][0]);
        $this->assertSame(['sectionid', 'sectionnum', 'behavior', 'activities', 'instances', 'spaces'], $sectionkeys);
        $this->assertSame(
            ['cmid', 'action', 'useasreference', 'prompt', 'templatescope', 'spacerequired', 'spaceinstruction'],
            $activitykeys
        );
        $this->assertSame(
            ['sourcecmid', 'sourcename', 'name', 'typelabel', 'modname', 'prompt', 'aftercmid', 'sortorder'],
            $instancekeys
        );
    }
}
