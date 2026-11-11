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

use local_coursegen\local\placeholder\course_template_creator;
use local_coursegen\local\placeholder\template_creation_exception;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/placeholder_course_helper.php');

/**
 * Making the template of a course from the placeholders of its activities, against a real database.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\placeholder\course_template_creator
 */
final class course_template_creator_test extends \advanced_testcase {
    use placeholder_course_helper;

    /**
     * Every test starts from a clean database and acts as the administrator, as the command does.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The template is called like the course, every placeholder activity is a mold with one instance, the rest are kept.
     */
    public function test_makes_the_template_with_the_name_of_the_course(): void {
        $marker = self::marker_text();
        $pages = ['Guide' => $marker, 'Rules' => '<p>Fixed</p>', 'About' => $marker];
        $course = $this->course_with_pages($pages, ['fullname' => 'Template v5']);
        $ids = $this->cmids_by_name($course);

        $result = course_template_creator::create($course->id);

        $this->assertTrue($result['saved']);
        $this->assertFalse($result['replaced']);
        $this->assertSame('Template v5', $result['name']);
        $actions = $this->saved_actions($result['templateid']);
        $this->assertSame('template', $actions[$ids['Guide']]);
        $this->assertSame('keep', $actions[$ids['Rules']]);
        $this->assertSame('template', $actions[$ids['About']]);
        $sources = $this->saved_instance_sources($result['templateid']);
        $expected = [$ids['Guide'], $ids['About']];
        sort($expected);
        $this->assertSame($expected, $sources);
    }

    /**
     * The saved template points at the course it was made from and keeps its name.
     */
    public function test_the_saved_template_points_at_the_course(): void {
        global $DB;
        $course = $this->course_with_one_guide(['fullname' => 'Template v5']);

        $result = course_template_creator::create($course->id);

        $saved = $DB->get_record('local_coursegen_template', ['id' => $result['templateid']]);
        $this->assertSame('Template v5', $saved->name);
        $this->assertSame((int) $course->id, (int) $saved->courseid);
    }

    /**
     * The sections that hold an instance may be modified; the others are kept.
     */
    public function test_marks_only_the_sections_with_instances_as_modifiable(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 2]);
        $marker = self::marker_text();
        $generator->create_module('page', ['course' => $course->id, 'content' => $marker, 'section' => 0]);
        $generator->create_module('page', ['course' => $course->id, 'content' => '<p>Fixed</p>', 'section' => 1]);

        $result = course_template_creator::create($course->id);

        $behaviors = $this->saved_behaviors($result['templateid']);
        $this->assertSame('aimodify', $behaviors[0]);
        $this->assertSame('keep', $behaviors[1]);
        $this->assertSame('keep', $behaviors[2]);
    }

    /**
     * Running again for the same course and name replaces the template: same id, no duplicates.
     */
    public function test_running_twice_replaces_the_template(): void {
        global $DB;
        $course = $this->course_with_one_guide();
        $first = course_template_creator::create($course->id);

        $second = course_template_creator::create($course->id);

        $this->assertSame($first['templateid'], $second['templateid']);
        $this->assertTrue($second['replaced']);
        $templates = $this->row_count('local_coursegen_template', ['courseid' => $course->id]);
        $instances = $this->row_count('local_coursegen_tpl_instance', ['templateid' => $first['templateid']]);
        $sections = $this->row_count('local_coursegen_tpl_section', ['templateid' => $first['templateid']]);
        $this->assertSame(1, $templates);
        $this->assertSame(1, $instances);
        $this->assertSame(1, $sections);
    }

    /**
     * Replacing throws away what someone changed in the saved instances and keeps the settings of the template itself.
     */
    public function test_replacing_discards_edited_instances_and_keeps_the_template_settings(): void {
        global $DB;
        $course = $this->course_with_one_guide();
        $first = course_template_creator::create($course->id);
        $templateid = $first['templateid'];
        $instance = $DB->get_record('local_coursegen_tpl_instance', ['templateid' => $templateid]);
        $DB->set_field('local_coursegen_tpl_instance', 'name', 'Edited by hand', ['id' => $instance->id]);
        $DB->set_field('local_coursegen_tpl_instance', 'prompt', 'Custom prompt', ['id' => $instance->id]);
        $DB->set_field('local_coursegen_template', 'description', 'Written by an admin', ['id' => $templateid]);
        $DB->set_field('local_coursegen_template', 'namingpattern', 'Week {N}', ['id' => $templateid]);

        course_template_creator::create($course->id);

        $after = $DB->get_record('local_coursegen_tpl_instance', ['templateid' => $templateid]);
        $this->assertSame('Guide', $after->name);
        $this->assertEmpty($after->prompt);
        $template = $DB->get_record('local_coursegen_template', ['id' => $templateid]);
        $this->assertSame('Written by an admin', $template->description);
        $this->assertSame('Week {N}', $template->namingpattern);
    }

    /**
     * With replacing turned off an existing template is a refusal.
     */
    public function test_refuses_when_replacing_is_off_and_the_template_exists(): void {
        $course = $this->course_with_one_guide();
        course_template_creator::create($course->id);
        $message = 'The existing template should have been refused.';

        try {
            course_template_creator::create($course->id, ['replace' => false]);
            $this->fail($message);
        } catch (template_creation_exception $error) {
            $reason = $error->reason();
            $this->assertSame(template_creation_exception::ALREADY_EXISTS, $reason);
        }
    }

    /**
     * Two templates with the same name and course leave no single one to replace.
     */
    public function test_refuses_when_several_templates_share_the_name(): void {
        global $DB;
        $course = $this->course_with_one_guide();
        $first = course_template_creator::create($course->id);
        $copy = $DB->get_record('local_coursegen_template', ['id' => $first['templateid']]);
        unset($copy->id);
        $DB->insert_record('local_coursegen_template', $copy);

        $this->assert_refused((int) $course->id, template_creation_exception::AMBIGUOUS);
    }

    /**
     * A course that does not exist and the site home cannot give a template.
     */
    public function test_refuses_a_course_that_does_not_exist_and_the_site_home(): void {
        $this->assert_refused(9999999, template_creation_exception::COURSE_NOT_FOUND);
        $this->assert_refused(SITEID, template_creation_exception::COURSE_NOT_FOUND);
    }

    /**
     * A course without activities and a course without placeholders are refused.
     */
    public function test_refuses_a_course_without_activities_or_without_placeholders(): void {
        $empty = $this->empty_course();
        $plain = $this->course_with_pages(['Rules' => '<p>Fixed</p>']);

        $this->assert_refused((int) $empty->id, template_creation_exception::NO_ACTIVITIES);
        $this->assert_refused((int) $plain->id, template_creation_exception::NO_PLACEHOLDERS);
    }

    /**
     * With an empty template allowed, a course without placeholders is saved with everything kept.
     */
    public function test_allows_an_empty_template_when_asked(): void {
        $plain = $this->course_with_pages(['Rules' => '<p>Fixed</p>']);

        $result = course_template_creator::create($plain->id, ['allowempty' => true]);

        $this->assertSame(0, $result['instances']);
        $this->assertSame(1, $result['kept']);
    }

    /**
     * The name of the course is turned into plain text: tags are dropped and entities are decoded.
     */
    public function test_the_name_is_plain_text(): void {
        $options = ['fullname' => '<b>Curso</b> "A" &amp; B 📚'];
        $course = $this->course_with_one_guide($options);

        $result = course_template_creator::create($course->id);

        $this->assertSame('Curso "A" & B 📚', $result['name']);
    }

    /**
     * A name longer than the column is cut and the cut is reported.
     */
    public function test_a_very_long_name_is_cut_and_reported(): void {
        $longname = str_repeat('N', 300);
        $options = ['fullname' => $longname];
        $course = $this->course_with_one_guide($options);

        $result = course_template_creator::create($course->id);

        $length = \core_text::strlen($result['name']);
        $warnings = implode(' ', $result['warnings']);
        $this->assertSame(255, $length);
        $this->assertStringContainsString('cut', $warnings);
    }

    /**
     * The name asked for wins over the name of the course.
     */
    public function test_the_name_option_overrides_the_course_name(): void {
        $course = $this->course_with_one_guide();

        $result = course_template_creator::create($course->id, ['name' => 'Custom name']);

        $this->assertSame('Custom name', $result['name']);
    }

    /**
     * A name that is empty once the tags are gone is refused.
     */
    public function test_an_empty_name_is_refused(): void {
        $course = $this->course_with_one_guide();
        $message = 'The empty name should have been refused.';

        try {
            course_template_creator::create($course->id, ['name' => '<i> </i>']);
            $this->fail($message);
        } catch (template_creation_exception $error) {
            $reason = $error->reason();
            $this->assertSame(template_creation_exception::NAME_EMPTY, $reason);
        }
    }

    /**
     * The plan writes nothing.
     */
    public function test_the_plan_writes_nothing(): void {
        $course = $this->course_with_one_guide();

        $plan = course_template_creator::plan($course->id);

        $templates = $this->row_count('local_coursegen_template');
        $this->assertSame(1, $plan['instances']);
        $this->assertSame(0, $templates);
    }

    /**
     * A failure while saving leaves the database as it was.
     */
    public function test_a_failed_save_leaves_nothing_behind(): void {
        $course = $this->course_with_one_guide();
        $user = $this->plain_user();
        $this->setUser($user);
        $message = 'A user without the capability should not save a template.';

        try {
            course_template_creator::create($course->id);
            $this->fail($message);
        } catch (\required_capability_exception $error) {
            $this->assertInstanceOf(\required_capability_exception::class, $error);
        }

        $templates = $this->row_count('local_coursegen_template');
        $sections = $this->row_count('local_coursegen_tpl_section');
        $this->assertSame(0, $templates);
        $this->assertSame(0, $sections);
    }
}
