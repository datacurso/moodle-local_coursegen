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

use local_coursegen\local\service\template_creation_report;

/**
 * Unit tests for template_creation_report.
 *
 * A course made from a template is created even when one activity, one file or some parts of an activity could not
 * be made, and the teacher is told what was left out, in plain words.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_creation_report
 */
final class template_creation_report_test extends \advanced_testcase {
    /**
     * A creation with nothing missing says nothing.
     */
    public function test_a_complete_creation_has_no_warnings(): void {
        $created = ['success' => true, 'partial' => false, 'activityerrors' => []];

        $text = template_creation_report::warnings($created, [], []);

        $this->assertSame('', $text);
    }

    /**
     * An activity that could not be created is named by its title.
     */
    public function test_a_failed_activity_is_named_by_its_title(): void {
        $created = ['activityerrors' => [['resource_type' => 'quiz', 'title' => 'Final exam']]];

        $text = template_creation_report::warnings($created, [], []);

        $this->assertStringContainsString('Final exam', $text);
    }

    /**
     * An activity with no title is named by its type.
     */
    public function test_a_failed_activity_without_a_title_is_named_by_its_type(): void {
        $created = ['activityerrors' => [['resource_type' => 'forum', 'title' => '  ']]];

        $text = template_creation_report::warnings($created, [], []);

        $this->assertStringContainsString('forum', $text);
    }

    /**
     * Every failed activity is named, and the course is not refused for it.
     */
    public function test_every_failed_activity_is_named(): void {
        $created = ['activityerrors' => [
            ['resource_type' => 'quiz', 'title' => 'Exam one'],
            ['resource_type' => 'book', 'title' => 'Handbook'],
        ]];

        $text = template_creation_report::warnings($created, [], []);

        $this->assertStringContainsString('Exam one', $text);
        $this->assertStringContainsString('Handbook', $text);
    }

    /**
     * The files that could not be placed are named.
     */
    public function test_the_files_that_could_not_be_placed_are_named(): void {
        $text = template_creation_report::warnings([], ['guide.pdf', 'syllabus.pdf'], []);

        $this->assertStringContainsString('guide.pdf, syllabus.pdf', $text);
    }

    /**
     * The parts a generator left out are counted under the name of their activity.
     *
     * @dataProvider skipped_settings_provider
     * @param string $key The setting that counts the parts left out.
     */
    public function test_the_parts_left_out_are_reported(string $key): void {
        $activities = [['parameters' => ['name' => 'Handbook', 'mod_settings' => [$key => 2]]]];

        $text = template_creation_report::warnings([], [], $activities);

        $this->assertStringContainsString('Handbook', $text);
        $this->assertStringContainsString('2', $text);
    }

    /**
     * The settings that count the parts a generator left out.
     *
     * @return array
     */
    public static function skipped_settings_provider(): array {
        return [
            'chapters of a book' => ['skipped_chapters'],
            'questions of a quiz' => ['skipped_questions'],
            'pages of a wiki' => ['skipped_pages'],
        ];
    }

    /**
     * A count of zero, a text that is not a number and any other setting say nothing.
     *
     * @dataProvider quiet_settings_provider
     * @param string $key
     * @param mixed $value
     */
    public function test_nothing_is_reported_when_nothing_was_left_out(string $key, $value): void {
        $activities = [['parameters' => ['name' => 'Handbook', 'mod_settings' => [$key => $value]]]];

        $text = template_creation_report::warnings([], [], $activities);

        $this->assertSame('', $text);
    }

    /**
     * Settings that must not produce a note.
     *
     * @return array
     */
    public static function quiet_settings_provider(): array {
        return [
            'zero skipped' => ['skipped_chapters', 0],
            'negative skipped' => ['skipped_chapters', -3],
            'not a number' => ['skipped_chapters', 'none'],
            'other setting' => ['chapters', 5],
            'prefix inside the name' => ['has_skipped_chapters', 4],
        ];
    }

    /**
     * An activity with no settings at all is quiet.
     */
    public function test_an_activity_without_settings_is_quiet(): void {
        $activities = [['parameters' => ['name' => 'Label']], ['uid' => 'bare']];

        $text = template_creation_report::warnings([], [], $activities);

        $this->assertSame('', $text);
    }

    /**
     * The notes come as one text: activities first, then files, then the parts left out.
     */
    public function test_the_notes_come_in_order(): void {
        $created = ['activityerrors' => [['resource_type' => 'quiz', 'title' => 'Exam']]];
        $activities = [['parameters' => ['name' => 'Handbook', 'mod_settings' => ['skipped_chapters' => 1]]]];

        $text = template_creation_report::warnings($created, ['guide.pdf'], $activities);

        $activityat = strpos($text, 'Exam');
        $fileat = strpos($text, 'guide.pdf');
        $skippedat = strpos($text, 'Handbook');

        $this->assertLessThan($fileat, $activityat);
        $this->assertLessThan($skippedat, $fileat);
    }
}
