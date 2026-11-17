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

namespace local_coursegen\local\space;

use local_coursegen\local\models\template;
use local_coursegen\local\models\template_space;
use local_coursegen\local\service\template_course_order;

/**
 * The spaces of a template the teacher fills with one of their own activities.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\space\activity_spaces
 * @covers     \local_coursegen\local\service\template_course_order
 *
 * @runTestsInSeparateProcesses
 */
final class activity_spaces_test extends \advanced_testcase {
    /**
     * A template of a course with one section, and a space for a quiz after one of its activities.
     *
     * @param bool $required
     * @return array [template id, base course, anchor cm, space uid]
     */
    private function template_with_space(bool $required = true): array {
        $generator = $this->getDataGenerator();
        $base = $generator->create_course(['numsections' => 1]);
        $anchor = $generator->create_module('page', ['course' => $base->id, 'section' => 1]);
        $template = new template(0, (object) ['name' => 'Spaces', 'courseid' => $base->id]);
        $template->create();
        $templateid = (int) $template->get('id');
        $space = new template_space(0, (object) [
            'uid' => 'space-quiz',
            'templateid' => $templateid,
            'sectionid' => (int) get_fast_modinfo($base)->get_section_info(1)->id,
            'modname' => 'quiz',
            'required' => $required ? 1 : 0,
            'instruction' => 'Your own final quiz',
            'aftercmid' => (int) $anchor->cmid,
            'sortorder' => 0,
        ]);
        $space->create();
        return [$templateid, $base, $anchor, 'space-quiz'];
    }

    /**
     * A teacher of a course of their own, with a quiz and a page in it.
     *
     * @return array [user, course, quiz, page]
     */
    private function teacher_with_activities(): array {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $course = $generator->create_course(['fullname' => 'Teacher course']);
        $generator->enrol_user($user->id, $course->id, 'editingteacher');
        $quiz = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'My quiz']);
        $page = $generator->create_module('page', ['course' => $course->id]);
        return [$user, $course, $quiz, $page];
    }

    /**
     * The teacher is offered the activities of the asked type of their own courses, and only those.
     */
    public function test_the_candidates_are_the_activities_of_the_type_in_the_courses_of_the_teacher(): void {
        $this->resetAfterTest();
        [$user, , $quiz] = $this->teacher_with_activities();
        $other = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('quiz', ['course' => $other->id]);

        $candidates = activity_spaces::candidates((int) $user->id, 'quiz');

        $this->assertSame([(int) $quiz->cmid], array_column($candidates, 'cmid'));
        $this->assertSame('My quiz', $candidates[0]['name']);
        $this->assertSame('Teacher course', $candidates[0]['coursename']);
    }

    /**
     * A student of a course cannot bring its activities: copying them is backing them up.
     */
    public function test_a_student_is_offered_nothing(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $student = $generator->create_user();
        $course = $generator->create_course();
        $generator->enrol_user($student->id, $course->id, 'student');
        $generator->create_module('quiz', ['course' => $course->id]);

        $this->assertSame([], activity_spaces::candidates((int) $student->id, 'quiz'));
    }

    /**
     * A quiz picked for the quiz space is kept under the uid of the space.
     */
    public function test_a_fitting_activity_is_accepted(): void {
        $this->resetAfterTest();
        [$templateid, , , $uid] = $this->template_with_space();
        [$user, , $quiz] = $this->teacher_with_activities();

        $picked = [['uid' => $uid, 'cmid' => (int) $quiz->cmid]];
        $chosen = activity_spaces::validated($templateid, (int) $user->id, $picked);

        $this->assertSame([$uid => (int) $quiz->cmid], $chosen);
    }

    /**
     * An activity of another type than the space asks for is refused.
     */
    public function test_an_activity_of_another_type_is_refused(): void {
        $this->resetAfterTest();
        [$templateid, , , $uid] = $this->template_with_space();
        [$user, , , $page] = $this->teacher_with_activities();

        $this->expectException(\moodle_exception::class);
        activity_spaces::validated($templateid, (int) $user->id, [['uid' => $uid, 'cmid' => (int) $page->cmid]]);
    }

    /**
     * A space that is not one of the template is refused.
     */
    public function test_a_space_of_another_template_is_refused(): void {
        $this->resetAfterTest();
        [$templateid] = $this->template_with_space(false);
        [$user, , $quiz] = $this->teacher_with_activities();

        $this->expectException(\moodle_exception::class);
        activity_spaces::validated($templateid, (int) $user->id, [['uid' => 'nope', 'cmid' => (int) $quiz->cmid]]);
    }

    /**
     * An activity of a course where the teacher cannot copy activities is refused.
     */
    public function test_an_activity_the_teacher_cannot_copy_is_refused(): void {
        $this->resetAfterTest();
        [$templateid, , , $uid] = $this->template_with_space();
        [$user] = $this->teacher_with_activities();
        $foreign = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $foreign->id]);

        $this->expectException(\moodle_exception::class);
        activity_spaces::validated($templateid, (int) $user->id, [['uid' => $uid, 'cmid' => (int) $quiz->cmid]]);
    }

    /**
     * A required space without its activity is refused; an optional one is not.
     */
    public function test_a_required_space_must_have_its_activity_and_an_optional_one_need_not(): void {
        $this->resetAfterTest();
        [$optional] = $this->template_with_space(false);
        [$user] = $this->teacher_with_activities();
        $this->assertSame([], activity_spaces::validated($optional, (int) $user->id, []));

        [$required] = $this->template_with_space(true);
        $this->expectException(\moodle_exception::class);
        activity_spaces::validated($required, (int) $user->id, []);
    }

    /**
     * The activity picked is copied into the new course, right after the activity the space follows.
     */
    public function test_the_activity_is_copied_into_the_place_of_its_space(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$templateid, , $anchor, $uid] = $this->template_with_space();
        [, , $quiz] = $this->teacher_with_activities();
        $generator = $this->getDataGenerator();
        $target = $generator->create_course(['numsections' => 1]);
        $last = $generator->create_module('label', ['course' => $target->id, 'section' => 1]);
        $keptanchor = $generator->create_module('page', ['course' => $target->id, 'section' => 1]);

        $placed = activity_spaces::place($templateid, (int) $target->id, [$uid => (int) $quiz->cmid]);
        $kept = [(int) $anchor->cmid => (int) $keptanchor->cmid];
        template_course_order::apply($templateid, (int) $target->id, [], [], $kept, $placed);

        $this->assertArrayHasKey($uid, $placed);
        \course_modinfo::clear_instance_cache($target->id);
        $modinfo = get_fast_modinfo($target->id);
        $copy = $modinfo->get_cm($placed[$uid]);
        $this->assertSame('quiz', $copy->modname);
        $this->assertSame('My quiz', $copy->name);
        $sequence = array_map('intval', $modinfo->sections[1]);
        $this->assertSame([(int) $keptanchor->cmid, $placed[$uid], (int) $last->cmid], $sequence);
    }
}
