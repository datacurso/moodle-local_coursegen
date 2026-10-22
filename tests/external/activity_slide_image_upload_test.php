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

/**
 * The upload of the picture of a slide: who may send it and what it refuses.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\activity_slide_image_upload
 *
 * @runTestsInSeparateProcesses
 */
final class activity_slide_image_upload_test extends \advanced_testcase {
    /**
     * A slide number outside 1 to the maximum is refused before anything is looked up.
     *
     * @dataProvider invalid_slides
     * @param int $slide
     */
    public function test_a_slide_out_of_range_is_refused(int $slide): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_invalid_slide', 'local_coursegen'));

        activity_slide_image_upload::execute($course->id, 'job', 1, $slide);
    }

    /**
     * The numbers that are not a slide.
     *
     * @return array
     */
    public static function invalid_slides(): array {
        return [
            'zero' => [0],
            'negative' => [-1],
            'above the maximum' => [activity_slide_image_upload::MAX_SLIDE + 1],
        ];
    }

    /**
     * Someone who may not create activities with the AI cannot send a picture.
     */
    public function test_a_user_without_the_capability_is_refused(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);

        activity_slide_image_upload::execute($course->id, 'job', 1, 1);
    }

    /**
     * A job that does not exist for the user is refused.
     */
    public function test_an_unknown_job_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $this->expectException(\moodle_exception::class);

        activity_slide_image_upload::execute($course->id, 'no-such-job', 1, 1);
    }
}
