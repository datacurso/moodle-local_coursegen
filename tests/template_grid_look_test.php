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
 * A course made from a template in the grid format shows each section with the
 * picture and the options the template gives it.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_section_look
 * @covers     \local_coursegen\local\service\template_grid_section_image
 *
 * @runTestsInSeparateProcesses
 */
final class template_grid_look_test extends \advanced_testcase {
    /**
     * Skip when the grid format is not part of the site.
     */
    protected function setUp(): void {
        parent::setUp();
        if (\core_component::get_plugin_directory('format', 'grid') === null) {
            $this->markTestSkipped('The grid course format is not installed.');
        }
    }

    /**
     * A grid course whose second section has a picture and options.
     *
     * @return \stdClass
     */
    private function create_grid_course(): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['format' => 'grid', 'numsections' => 2]);
        $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 1], '*', MUST_EXIST);

        course_get_format($course)->update_section_format_options([
            'id' => $section->id,
            'sectionimagealttext' => 'A picture',
            'sectionbreak' => 2,
        ]);

        $fs = get_file_storage();
        $contextid = \context_course::instance($course->id)->id;
        $original = $fs->create_file_from_string([
            'contextid' => $contextid,
            'component' => 'format_grid',
            'filearea' => 'sectionimage',
            'itemid' => $section->id,
            'filepath' => '/',
            'filename' => 'pic.png',
        ], 'original-bytes');
        $fs->create_file_from_string([
            'contextid' => $contextid,
            'component' => 'format_grid',
            'filearea' => 'displayedsectionimage',
            'itemid' => $section->id,
            'filepath' => '/1/',
            'filename' => 'pic.png',
        ], 'displayed-bytes');
        $DB->insert_record('format_grid_image', (object) [
            'image' => 'pic.png',
            'contenthash' => $original->get_contenthash(),
            'displayedimagestate' => 1,
            'sectionid' => $section->id,
            'courseid' => $course->id,
        ]);
        return $course;
    }

    /**
     * Build a course from a template over the given course.
     *
     * @param \stdClass $source
     * @return int The new course id.
     */
    private function create_course_from(\stdClass $source): int {
        global $USER;
        $template = new template(0, (object) ['name' => 'Grid template', 'courseid' => $source->id]);
        $template->create();
        $session = new course_session(0, (object) [
            'userid' => $USER->id,
            'session_id' => 'sess-grid',
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode(['templateid' => $template->get('id')]),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $session->create();

        $payload = template_export_service::build_init_payload($template->get('id'));
        $configuration = $payload['course_configuration'];
        $configuration['fullname'] = 'Grid copy';
        $configuration['shortname'] = 'grid-copy';
        $result = create_course_service::create_course($session, [
            'course_configuration' => $configuration,
            'sections_info' => $payload['sections_info'],
        ]);
        $this->assertTrue($result['success'], $result['message']);
        return (int) $result['courseid'];
    }

    /**
     * The course-wide options of the grid format carry over.
     */
    public function test_grid_format_and_its_options_carry_over(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->create_grid_course();
        course_get_format($source)->update_course_format_options(['popup' => 1, 'sectiontitleingridbox' => 1]);

        $courseid = $this->create_course_from($source);

        $course = get_course($courseid);
        $this->assertSame('grid', $course->format);
        $options = course_get_format($course)->get_format_options();
        $this->assertEquals(1, $options['popup']);
        $this->assertEquals(1, $options['sectiontitleingridbox']);
    }

    /**
     * The options a section keeps in its format carry over to the same section.
     */
    public function test_section_format_options_carry_over(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $courseid = $this->create_course_from($this->create_grid_course());

        $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => 1], '*', MUST_EXIST);
        $options = course_get_format(get_course($courseid))->get_format_options($section->id);
        $this->assertSame('A picture', $options['sectionimagealttext']);
        $this->assertEquals(2, $options['sectionbreak']);
    }

    /**
     * The picture of a section, original and displayed, belongs to the same section of the new course.
     */
    public function test_section_picture_is_copied_to_the_new_section(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $courseid = $this->create_course_from($this->create_grid_course());

        $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => 1], '*', MUST_EXIST);
        $image = $DB->get_record('format_grid_image', ['sectionid' => $section->id], '*', MUST_EXIST);
        $this->assertSame('pic.png', $image->image);
        $this->assertEquals(1, $image->displayedimagestate);
        $this->assertEquals($courseid, $image->courseid);
        $contextid = \context_course::instance($courseid)->id;
        $fs = get_file_storage();
        $original = $fs->get_file($contextid, 'format_grid', 'sectionimage', $section->id, '/', 'pic.png');
        $displayed = $fs->get_file($contextid, 'format_grid', 'displayedsectionimage', $section->id, '/1/', 'pic.png');
        $this->assertNotFalse($original);
        $this->assertSame('original-bytes', $original->get_content());
        $this->assertNotFalse($displayed);
        $this->assertSame('displayed-bytes', $displayed->get_content());
    }

    /**
     * A section without a picture gets none.
     */
    public function test_section_without_picture_gets_none(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $courseid = $this->create_course_from($this->create_grid_course());

        $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => 2], '*', MUST_EXIST);
        $this->assertFalse($DB->record_exists('format_grid_image', ['sectionid' => $section->id]));
    }
}
