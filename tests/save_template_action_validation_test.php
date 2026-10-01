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

defined('MOODLE_INTERNAL') || die();

// The shared fixture trait sits in tests/ root, outside the tests/classes
// autoload scope, so it must be required explicitly.
require_once(__DIR__ . '/sections_config_fixture_trait.php');

use core\invalid_persistent_exception;
use local_coursegen\external\save_template;
use local_coursegen\local\models\template_activity;

/**
 * save_template refuses an activity action the template editor does not
 * offer, instead of storing it.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\save_template
 *
 * @runTestsInSeparateProcesses
 */
final class save_template_action_validation_test extends \advanced_testcase {
    use sections_config_fixture_trait;

    /**
     * Saving an activity with the removed action fails and stores no row
     * for that activity.
     */
    public function test_removed_action_is_rejected_on_save(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();
        $modinfo = get_fast_modinfo($course);
        $section1 = $modinfo->get_section_info(1);

        $rejected = false;
        try {
            $this->save_with_action($course, $section1, $page, 'modify');
        } catch (invalid_persistent_exception $exception) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
        $stored = template_activity::get_records(['cmid' => (int) $page->cmid]);
        $this->assertSame([], $stored);
    }

    /**
     * Saving an activity with an action of the editor still works.
     */
    public function test_editor_action_is_saved(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();
        $modinfo = get_fast_modinfo($course);
        $section1 = $modinfo->get_section_info(1);

        $this->save_with_action($course, $section1, $page, 'reference');

        $record = template_activity::get_record(['cmid' => (int) $page->cmid]);
        $this->assertNotFalse($record);
        $this->assertSame('reference', $record->get('action'));
    }

    /**
     * Save a template whose only configured activity has the given action.
     *
     * @param \stdClass $course
     * @param \section_info $section
     * @param \stdClass $page
     * @param string $action
     */
    private function save_with_action($course, $section, $page, string $action): void {
        save_template::execute(0, 'Action template', '', (int) $course->id, 0, false, '', 1, [
            [
                'sectionid' => (int) $section->id,
                'sectionnum' => 1,
                'behavior' => 'keep',
                'activities' => [
                    [
                        'cmid' => (int) $page->cmid,
                        'action' => $action,
                        'useasreference' => true,
                        'prompt' => '',
                    ],
                ],
            ],
        ]);
    }
}
