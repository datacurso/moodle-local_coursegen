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

use local_coursegen\local\service\template_source_form_values;

/**
 * Unit tests for template_source_form_values.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_source_form_values
 *
 * @runTestsInSeparateProcesses
 */
final class template_source_form_values_test extends \advanced_testcase {
    /**
     * Module names whose own edit form is read.
     *
     * @return array
     */
    public static function modules_provider(): array {
        return [
            'page' => ['page'],
            'label' => ['label'],
            'assign' => ['assign'],
            'url' => ['url'],
            'forum' => ['forum'],
        ];
    }

    /**
     * Every form value of a module is read under the name its form gives it.
     *
     * @dataProvider modules_provider
     * @param string $modname
     */
    public function test_values_use_the_names_of_the_module_form(string $modname): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $cm = $this->create_cm($modname);

        $values = template_source_form_values::read($cm);

        $this->assertArrayHasKey('name', $values);
        $this->assertArrayHasKey('introeditor', $values);
        $this->assertArrayHasKey('visible', $values);
        $this->assertArrayHasKey('cmidnumber', $values);
    }

    /**
     * The editors keep their text, format and draft item.
     */
    public function test_page_editors_are_text_format_and_itemid(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', [
            'course' => $course->id,
            'content' => '<p>Body [[title]]</p>',
            'intro' => '<p>Intro</p>',
        ]);
        $cm = get_fast_modinfo($course)->get_cm($page->cmid);

        $values = template_source_form_values::read($cm);

        $this->assertSame('<p>Body [[title]]</p>', $values['page']['text']);
        $this->assertArrayHasKey('format', $values['page']);
        $this->assertArrayHasKey('itemid', $values['page']);
        $this->assertSame('<p>Intro</p>', $values['introeditor']['text']);
        $this->assertArrayHasKey('printintro', $values);
        $this->assertArrayHasKey('printlastmodified', $values);
    }

    /**
     * The activity description editor of an assignment is one of its form values.
     */
    public function test_assign_keeps_its_own_editor_names(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $cm = $this->create_cm('assign');

        $values = template_source_form_values::read($cm);

        $this->assertArrayHasKey('activityeditor', $values);
        $this->assertArrayHasKey('text', $values['activityeditor']);
    }

    /**
     * The url of a URL resource and the type of a forum are form values.
     */
    public function test_url_and_forum_keep_their_own_settings(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $urlvalues = template_source_form_values::read($this->create_cm('url'));
        $forumvalues = template_source_form_values::read($this->create_cm('forum'));

        $this->assertArrayHasKey('externalurl', $urlvalues);
        $this->assertArrayHasKey('type', $forumvalues);
    }

    /**
     * Every element the form declares hidden is left out, and so is every
     * column that is not an element of the form.
     *
     * @dataProvider modules_provider
     * @param string $modname
     */
    public function test_hidden_elements_and_raw_columns_are_stripped(string $modname): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $cm = $this->create_cm($modname);

        $values = template_source_form_values::read($cm);

        $identity = ['id', 'course', 'coursemodule', 'instance', 'module', 'modulename', 'section', 'add', 'update',
            'return', 'sr', 'sesskey', '_qf__mod_' . $modname . '_mod_form', 'timemodified'];
        foreach ($identity as $key) {
            $this->assertArrayNotHasKey($key, $values, $key);
        }
    }

    /**
     * A hidden element the module declares itself is a setting, and stays.
     */
    public function test_hidden_settings_declared_by_the_module_are_kept(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $cm = $this->create_cm('page');

        $values = template_source_form_values::read($cm);

        $this->assertArrayHasKey('display', $values);
        $this->assertArrayHasKey('revision', $values);
    }

    /**
     * The state of the page the form keeps in hidden elements is not a setting.
     */
    public function test_state_of_the_page_is_not_exported(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $cm = $this->create_cm('assign');

        $values = template_source_form_values::read($cm);

        $keys = array_keys($values);
        $state = preg_grep('/^mform_(isexpanded|showmore)_/', $keys);
        $this->assertSame([], array_values($state));
    }

    /**
     * An unchecked checkbox and an empty setting are values of the form too.
     */
    public function test_unchecked_and_empty_elements_are_kept(): void {
        global $CFG;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $CFG->enablecompletion = 1;
        $CFG->enableavailability = 1;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $page = $generator->create_module('page', ['course' => $course->id]);
        $cm = get_fast_modinfo($course)->get_cm($page->cmid);

        $values = template_source_form_values::read($cm);

        $this->assertArrayHasKey('completionview', $values);
        $this->assertEmpty($values['completionview']);
        $this->assertArrayHasKey('availabilityconditionsjson', $values);
    }

    /**
     * The values can be sent as JSON.
     *
     * @dataProvider modules_provider
     * @param string $modname
     */
    public function test_values_are_json_encodable(string $modname): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $cm = $this->create_cm($modname);

        $values = template_source_form_values::read($cm);

        $json = json_encode($values);
        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertSame($values['introeditor']['text'], $decoded['introeditor']['text']);
    }

    /**
     * The draft areas the editors prepared are released, however often it is read.
     *
     * @dataProvider modules_provider
     * @param string $modname
     */
    public function test_draft_items_are_released(string $modname): void {
        global $DB, $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $cm = $this->create_cm($modname);
        $usercontext = \context_user::instance($USER->id);
        $conditions = ['contextid' => $usercontext->id, 'component' => 'user', 'filearea' => 'draft'];
        $before = $DB->count_records('files', $conditions);

        template_source_form_values::read($cm);
        template_source_form_values::read($cm);

        $after = $DB->count_records('files', $conditions);
        $this->assertSame($before, $after);
    }

    /**
     * A form that cannot be read fails the export and names the activity and the module.
     */
    public function test_unreadable_form_fails_naming_activity_and_module(): void {
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Glossary page']);
        $student = $generator->create_and_enrol($course, 'student');
        $this->setUser($student);
        $cm = get_fast_modinfo($course)->get_cm($page->cmid);

        try {
            template_source_form_values::read($cm);
            $this->fail('A form that cannot be read must fail the export.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('template_source_form_unreadable', $exception->errorcode);
            $this->assertStringContainsString('Glossary page', $exception->getMessage());
            $this->assertStringContainsString('page', $exception->getMessage());
        }
    }

    /**
     * Create an activity of a module and return its cm_info.
     *
     * @param string $modname
     * @return \cm_info
     */
    private function create_cm(string $modname): \cm_info {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $module = $generator->create_module($modname, ['course' => $course->id]);
        $modinfo = get_fast_modinfo($course);
        return $modinfo->get_cm($module->cmid);
    }
}
