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

namespace local_coursegen\local\files;

/**
 * The files of the template's course are found by their address.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\files\pluginfile_url_source
 * @covers     \local_coursegen\utils\mold_file_copier
 */
final class pluginfile_url_source_test extends \advanced_testcase {
    /**
     * A base course with a label whose intro area holds one image file.
     *
     * @return array{0: \stdClass, 1: string} The course and the image's absolute pluginfile URL.
     */
    private function base_course_with_label_image(): array {
        global $CFG;

        $course = $this->getDataGenerator()->create_course();
        $label = $this->getDataGenerator()->create_module('label', ['course' => $course->id]);
        $context = \context_module::instance($label->cmid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_label', 'filearea' => 'intro', 'itemid' => 0,
            'filepath' => '/', 'filename' => 'mold pic.png',
        ], 'PNGDATA');

        return [$course, $CFG->wwwroot . '/pluginfile.php/' . $context->id . '/mod_label/intro/mold%20pic.png'];
    }

    /**
     * What the source finds at an address.
     *
     * @param string $url
     * @param int|null $sourcecourseid
     * @return \stored_file|null
     */
    private function found(string $url, ?int $sourcecourseid = null): ?\stored_file {
        $source = new pluginfile_url_source($sourcecourseid);
        return $source->find(new file_reference(file_reference::KIND_URL, $url));
    }

    /**
     * A file of the allowed course is found.
     */
    public function test_finds_the_file_of_the_template_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $url] = $this->base_course_with_label_image();

        $file = $this->found($url, (int) $course->id);

        $this->assertSame('PNGDATA', $file->get_content());
    }

    /**
     * A reference that is not an address is not this source's.
     */
    public function test_a_placeholder_is_not_looked_up(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = new pluginfile_url_source(null);

        $this->assertNull($source->find(new file_reference(file_reference::KIND_PLACEHOLDER, '/mold pic.png')));
    }

    /**
     * A user who cannot manage the course's activities does not get its files, nor does anybody for another course's.
     */
    public function test_refuses_file_of_course_the_user_cannot_access(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $url] = $this->base_course_with_label_image();
        $other = $this->getDataGenerator()->create_course();

        $this->assertNull($this->found($url, (int) $other->id));
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertNull($this->found($url));
        $this->assertNotEquals($other->id, $course->id);
    }

    /**
     * Without an explicit source course, a teacher who manages the course's activities may copy from it.
     */
    public function test_teacher_with_manageactivities_may_copy_without_explicit_source_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $url] = $this->base_course_with_label_image();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        $this->assertNotNull($this->found($url));
    }

    /**
     * An enrolled student can access the course but may not lift its files, even naming the course as the source.
     */
    public function test_enrolled_student_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $url] = $this->base_course_with_label_image();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));
        $this->assertTrue(can_access_course($course));

        $this->assertNull($this->found($url));
        $this->assertNull($this->found($url, (int) $course->id));
    }

    /**
     * A private file of the user is not in a course or module context, so it is never found.
     */
    public function test_a_private_file_is_never_found(): void {
        global $CFG, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $usercontext = \context_user::instance($USER->id);
        get_file_storage()->create_file_from_string([
            'contextid' => $usercontext->id, 'component' => 'user', 'filearea' => 'private',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'secret.png',
        ], 'SECRET');
        $url = $CFG->wwwroot . '/pluginfile.php/' . $usercontext->id . '/user/private/secret.png';

        $this->assertNull($this->found($url));
    }

    /**
     * A file a teacher brought for a space is not found by its own address: the space source hands it out.
     */
    public function test_a_file_stored_for_a_space_is_not_found_by_address(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $context = \context_user::instance($user->id);
        $file = get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'local_coursegen', 'filearea' => 'spacefile', 'itemid' => 55,
            'filepath' => '/12/', 'filename' => 'guide.pdf',
        ], 'BROUGHT');
        $address = \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        );

        $this->assertNull($this->found($address->out(false)));
    }
}
