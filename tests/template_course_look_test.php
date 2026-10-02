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

use local_coursegen\local\models\course_session;
use local_coursegen\local\models\template;
use local_coursegen\local\service\create_course_service;
use local_coursegen\local\service\template_export_service;

/**
 * A course made from a template is the template's course with other content
 * in it: its format, the way the format is set up, its language and course
 * settings, and what each section looks like all carry over.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\create_course_service
 * @covers     \local_coursegen\local\service\template_course_settings
 * @covers     \local_coursegen\local\service\template_section_look
 * @covers     \local_coursegen\local\service\template_area_files_copier
 * @covers     \local_coursegen\local\service\template_grid_section_image
 * @covers     \local_coursegen\local\service\template_export_service
 *
 * @runTestsInSeparateProcesses
 */
final class template_course_look_test extends \advanced_testcase {
    /**
     * A course set up the way a template's course is.
     *
     * @return \stdClass
     */
    private function create_template_course(): \stdClass {
        return $this->getDataGenerator()->create_course([
            'format' => 'topics',
            'numsections' => 2,
            'lang' => 'es',
            'newsitems' => 0,
            'showreports' => 1,
            'showgrades' => 0,
            'showactivitydates' => 1,
            'maxbytes' => 1048576,
            'enablecompletion' => 1,
            'hiddensections' => 0,
            'coursedisplay' => 1,
            'visible' => 0,
        ]);
    }

    /**
     * Register a template over a course.
     *
     * @param int $courseid
     * @return template
     */
    private function create_template(int $courseid): template {
        $template = new template(0, (object) ['name' => 'Look template', 'courseid' => $courseid]);
        $template->create();
        return $template;
    }

    /**
     * A session started from a template, or from none.
     *
     * @param int $templateid 0 for a free session.
     * @return course_session
     */
    private function create_session(int $templateid): course_session {
        global $USER;
        $session = new course_session(0, (object) [
            'userid' => $USER->id,
            'session_id' => 'sess-look-' . $templateid,
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode(['templateid' => $templateid]),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $session->create();
        return $session;
    }

    /**
     * The result the AI service hands back for a template: the payload it was
     * given, with the name the teacher chose.
     *
     * @param int $templateid
     * @return array
     */
    private function result_of(int $templateid): array {
        $payload = template_export_service::build_init_payload($templateid);
        $configuration = $payload['course_configuration'];
        $configuration['fullname'] = 'Made from a template';
        $configuration['shortname'] = 'made-from-template';
        return [
            'course_configuration' => $configuration,
            'sections_info' => $payload['sections_info'],
        ];
    }

    /**
     * Give a section of a course a summary and the files it shows.
     *
     * @param \stdClass $course
     * @param int $number Section number.
     * @param string $summary
     * @param array $files Filename => content.
     */
    private function set_section_summary(\stdClass $course, int $number, string $summary, array $files): void {
        global $DB;
        $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => $number], '*', MUST_EXIST);
        $DB->update_record('course_sections', (object) [
            'id' => $section->id,
            'summary' => $summary,
            'summaryformat' => FORMAT_MARKDOWN,
        ]);
        $fs = get_file_storage();
        $contextid = \context_course::instance($course->id)->id;
        foreach ($files as $name => $content) {
            $fs->create_file_from_string([
                'contextid' => $contextid,
                'component' => 'course',
                'filearea' => 'section',
                'itemid' => $section->id,
                'filepath' => '/',
                'filename' => $name,
            ], $content);
        }
    }

    /**
     * The new course takes the template's format and the way it is set up.
     */
    public function test_new_course_takes_the_format_and_its_options(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $template = $this->create_template($this->create_template_course()->id);

        $result = create_course_service::create_course(
            $this->create_session($template->get('id')),
            $this->result_of($template->get('id'))
        );

        $this->assertTrue($result['success'], $result['message']);
        $course = get_course($result['courseid']);
        $this->assertSame('topics', $course->format);
        $options = course_get_format($course)->get_format_options();
        $this->assertEquals(0, $options['hiddensections']);
        $this->assertEquals(1, $options['coursedisplay']);
    }

    /**
     * The language and the course settings travel; the name comes from the
     * teacher and the course stays visible, as a free one does.
     */
    public function test_new_course_takes_the_language_and_settings_but_not_the_name_or_visibility(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $template = $this->create_template($this->create_template_course()->id);

        $result = create_course_service::create_course(
            $this->create_session($template->get('id')),
            $this->result_of($template->get('id'))
        );

        $course = get_course($result['courseid']);
        $this->assertSame('es', $course->lang);
        $this->assertEquals(0, $course->newsitems);
        $this->assertEquals(1, $course->showreports);
        $this->assertEquals(0, $course->showgrades);
        $this->assertEquals(1, $course->showactivitydates);
        $this->assertEquals(1048576, $course->maxbytes);
        $this->assertEquals(1, $course->enablecompletion);
        $this->assertSame('Made from a template', $course->fullname);
        $this->assertEquals(1, $course->visible);
    }

    /**
     * A template that leaves the language open leaves it open in the new course.
     */
    public function test_new_course_keeps_the_language_open_when_the_template_does(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['lang' => '']);
        $template = $this->create_template($course->id);

        $result = create_course_service::create_course(
            $this->create_session($template->get('id')),
            $this->result_of($template->get('id'))
        );

        $this->assertSame('', get_course($result['courseid'])->lang);
    }

    /**
     * A free generation owns those settings: the same configuration leaves
     * its course with the site's defaults.
     */
    public function test_free_course_does_not_take_the_format_or_settings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $template = $this->create_template($this->create_template_course()->id);

        $result = create_course_service::create_course(
            $this->create_session(0),
            $this->result_of($template->get('id'))
        );

        $this->assertTrue($result['success'], $result['message']);
        $course = get_course($result['courseid']);
        $this->assertSame(get_config('moodlecourse', 'format'), $course->format);
        $this->assertNotSame('topics', $course->format);
        $this->assertSame('', $course->lang);
        $this->assertEquals(get_config('moodlecourse', 'newsitems'), $course->newsitems);
    }

    /**
     * The summary of each section, its format and its files carry over, and
     * the files belong to the new section.
     */
    public function test_section_summary_and_files_are_copied(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->create_template_course();
        $this->set_section_summary(
            $source,
            1,
            '<p><img src="@@PLUGINFILE@@/banner.png" alt=""></p>',
            ['banner.png' => 'banner-bytes', 'unused.png' => 'unused-bytes']
        );
        $template = $this->create_template($source->id);

        $result = create_course_service::create_course(
            $this->create_session($template->get('id')),
            $this->result_of($template->get('id'))
        );

        $section = $DB->get_record('course_sections', ['course' => $result['courseid'], 'section' => 1], '*', MUST_EXIST);
        $this->assertSame('<p><img src="@@PLUGINFILE@@/banner.png" alt=""></p>', $section->summary);
        $this->assertEquals(FORMAT_MARKDOWN, $section->summaryformat);
        $contextid = \context_course::instance($result['courseid'])->id;
        $fs = get_file_storage();
        $banner = $fs->get_file($contextid, 'course', 'section', $section->id, '/', 'banner.png');
        $unused = $fs->get_file($contextid, 'course', 'section', $section->id, '/', 'unused.png');
        $this->assertNotFalse($banner);
        $this->assertSame('banner-bytes', $banner->get_content());
        $this->assertNotFalse($unused);
    }

    /**
     * The template's own files stay where they are.
     */
    public function test_the_templates_section_files_are_left_in_place(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->create_template_course();
        $this->set_section_summary($source, 1, '<img src="@@PLUGINFILE@@/banner.png">', ['banner.png' => 'bytes']);
        $template = $this->create_template($source->id);

        create_course_service::create_course(
            $this->create_session($template->get('id')),
            $this->result_of($template->get('id'))
        );

        $section = $DB->get_record('course_sections', ['course' => $source->id, 'section' => 1], '*', MUST_EXIST);
        $this->assertStringContainsString('@@PLUGINFILE@@/banner.png', $section->summary);
        $contextid = \context_course::instance($source->id)->id;
        $file = get_file_storage()->get_file($contextid, 'course', 'section', $section->id, '/', 'banner.png');
        $this->assertNotFalse($file);
    }

    /**
     * A section with no summary stays without one.
     */
    public function test_section_without_summary_stays_empty(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $template = $this->create_template($this->create_template_course()->id);

        $result = create_course_service::create_course(
            $this->create_session($template->get('id')),
            $this->result_of($template->get('id'))
        );

        $section = $DB->get_record('course_sections', ['course' => $result['courseid'], 'section' => 2], '*', MUST_EXIST);
        $this->assertSame('', (string) $section->summary);
    }
}
