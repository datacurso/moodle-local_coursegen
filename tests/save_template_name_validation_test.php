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

use local_coursegen\external\save_template;

/**
 * save_template rejects a blank template name — the wizard's own
 * template_name_form.php only ever adds a client-side "required" rule
 * (its mform is never actually submitted, so that rule never runs), so
 * this external function is the only real place a blank name is stopped.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\save_template
 *
 * @runTestsInSeparateProcesses
 */
final class save_template_name_validation_test extends \advanced_testcase {
    use sections_config_fixture_trait;

    /**
     * @dataProvider blank_name_provider
     * @param string $name
     */
    public function test_rejects_a_blank_name(string $name): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course] = $this->create_course_fixture();

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('template_name_required', 'local_coursegen'));

        save_template::execute(0, $name, '', (int) $course->id, 0, true, '[]', '', 1, []);
    }

    /**
     * @return array
     */
    public static function blank_name_provider(): array {
        return [
            'empty string' => [''],
            'only whitespace' => ['   '],
        ];
    }

    /**
     * A real name (once trimmed of incidental surrounding whitespace by the
     * form widget's own PARAM_TEXT cleaning) saves normally.
     */
    public function test_accepts_a_real_name(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course] = $this->create_course_fixture();

        $saved = save_template::execute(0, 'A real template name', '', (int) $course->id, 0, true, '[]', '', 1, []);

        $this->assertSame('A real template name', $saved['name']);
    }
}
