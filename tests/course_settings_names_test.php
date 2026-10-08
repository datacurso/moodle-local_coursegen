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
use local_coursegen\local\service\create_course_service;

/**
 * The name and short name the final review offers come from the course configuration of the result.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\create_course_service
 *
 * @runTestsInSeparateProcesses
 */
final class course_settings_names_test extends \advanced_testcase {
    /**
     * The settings the review offers for a result with this course configuration.
     *
     * @param array $configuration The course configuration of the result.
     * @return array
     */
    private function settings_for(array $configuration): array {
        $this->resetAfterTest();
        $session = new course_session();
        return create_course_service::get_course_settings($session, ['course_configuration' => $configuration]);
    }

    /**
     * The names the AI proposed are the ones the review offers.
     */
    public function test_the_review_offers_the_names_the_ai_proposed(): void {
        $settings = $this->settings_for(['fullname' => 'Introduction to AI', 'shortname' => 'AI-101']);

        $this->assertSame('Introduction to AI', $settings['fullname']);
        $this->assertSame('AI-101', $settings['shortname']);
    }

    /**
     * Surrounding spaces of the names are dropped.
     */
    public function test_the_names_are_trimmed(): void {
        $settings = $this->settings_for(['fullname' => '  Introduction to AI  ', 'shortname' => ' AI-101 ']);

        $this->assertSame('Introduction to AI', $settings['fullname']);
        $this->assertSame('AI-101', $settings['shortname']);
    }

    /**
     * A full name longer than the field is cut to what the field holds.
     */
    public function test_a_long_full_name_is_cut_to_the_size_of_the_field(): void {
        $settings = $this->settings_for(['fullname' => str_repeat('N', 300), 'shortname' => 'AI']);

        $this->assertSame(255, \core_text::strlen($settings['fullname']));
    }

    /**
     * Without a proposed short name the review gets a generated one and the full name is kept.
     */
    public function test_a_missing_short_name_gets_a_generated_one(): void {
        $settings = $this->settings_for(['fullname' => 'Introduction to AI']);

        $this->assertSame('Introduction to AI', $settings['fullname']);
        $this->assertStringStartsWith('courseai-', $settings['shortname']);
    }

    /**
     * Without a proposed name the review gets the generic name of the plugin.
     */
    public function test_a_missing_full_name_gets_the_generic_name(): void {
        $settings = $this->settings_for(['fullname' => '', 'shortname' => 'AI-101']);

        $this->assertSame(get_string('createwithai', 'local_coursegen'), $settings['fullname']);
        $this->assertSame('AI-101', $settings['shortname']);
    }
}
