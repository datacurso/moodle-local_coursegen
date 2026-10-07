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

use local_coursegen\local\service\template_apply_guard;

/**
 * Unit tests for template_apply_guard.
 *
 * A course made from a template is either complete or not made: an activity or a file that could not be put in it
 * fails the creation instead of leaving a course with something missing.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_apply_guard
 */
final class template_apply_guard_test extends \advanced_testcase {
    /**
     * A creation with no errors passes.
     */
    public function test_a_complete_creation_passes(): void {
        $this->resetAfterTest(true);

        template_apply_guard::ensure_complete(['success' => true, 'partial' => false, 'activityerrors' => []], []);

        $this->assertTrue(true);
    }

    /**
     * An activity that could not be created fails the creation and names the activity.
     */
    public function test_an_activity_that_could_not_be_created_fails_the_creation(): void {
        $this->resetAfterTest(true);
        $created = [
            'success' => true,
            'partial' => true,
            'activityerrors' => [['resource_type' => 'page', 'section' => 0, 'title' => 'Guia Didactica']],
        ];

        try {
            template_apply_guard::ensure_complete($created, []);
            $this->fail('The creation had to fail');
        } catch (\moodle_exception $exception) {
            $this->assertSame('error_template_creation_incomplete', $exception->errorcode);
            $this->assertStringContainsString('Guia Didactica', $exception->a);
        }
    }

    /**
     * A partial creation fails even when the list of errors is empty.
     */
    public function test_the_partial_flag_alone_fails_the_creation(): void {
        $this->resetAfterTest(true);

        $this->expectException(\moodle_exception::class);

        template_apply_guard::ensure_complete(['success' => true, 'partial' => true], []);
    }

    /**
     * A file that could not be put in its resource fails the creation and names the file.
     */
    public function test_a_file_that_could_not_be_applied_fails_the_creation(): void {
        $this->resetAfterTest(true);

        try {
            template_apply_guard::ensure_complete(['success' => true], ['guide.pdf']);
            $this->fail('The creation had to fail');
        } catch (\moodle_exception $exception) {
            $this->assertSame('error_template_creation_files', $exception->errorcode);
            $this->assertStringContainsString('guide.pdf', $exception->a);
        }
    }

    /**
     * An activity without a title is still reported, with its type.
     */
    public function test_an_activity_without_a_title_is_named_by_its_type(): void {
        $this->resetAfterTest(true);
        $created = ['partial' => true, 'activityerrors' => [['resource_type' => 'page', 'title' => '']]];

        try {
            template_apply_guard::ensure_complete($created, []);
            $this->fail('The creation had to fail');
        } catch (\moodle_exception $exception) {
            $this->assertStringContainsString('page', $exception->a);
        }
    }

    /**
     * Several failures are all named.
     */
    public function test_every_failed_activity_is_named(): void {
        $this->resetAfterTest(true);
        $created = [
            'partial' => true,
            'activityerrors' => [
                ['resource_type' => 'page', 'title' => 'Uno'],
                ['resource_type' => 'quiz', 'title' => 'Dos'],
            ],
        ];

        try {
            template_apply_guard::ensure_complete($created, []);
            $this->fail('The creation had to fail');
        } catch (\moodle_exception $exception) {
            $this->assertStringContainsString('Uno', $exception->a);
            $this->assertStringContainsString('Dos', $exception->a);
        }
    }
}
