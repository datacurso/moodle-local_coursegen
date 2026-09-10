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

use local_coursegen\external\create_course_from_template;

/**
 * Parameter contract of the create-course-from-template external function.
 *
 * The template-mode activity chooser now sends a per-activity prompt, the
 * generate-images flag and an uploaded file's draftitemid alongside each
 * newactivities entry. Those keys must be accepted by execute_parameters()
 * and default to ''/0/0 when absent, so older clients that only send
 * sectionid/modname keep working unchanged.
 *
 * Loading the external class pulls in lib/externallib.php, so each test must
 * run in an isolated process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\create_course_from_template
 *
 * @runTestsInSeparateProcesses
 */
final class create_course_from_template_params_test extends \advanced_testcase {
    /**
     * Load the external API base class in the isolated process.
     */
    protected function setUp(): void {
        parent::setUp();
        global $CFG;
        require_once($CFG->libdir . '/externallib.php');
    }

    /**
     * A newactivities entry carrying prompt/generateimages/draftitemid passes validation verbatim.
     */
    public function test_newactivities_accepts_prompt_generateimages_draftitemid(): void {
        $this->resetAfterTest();

        $params = \external_api::validate_parameters(create_course_from_template::execute_parameters(), [
            'templateid' => 1,
            'newsections' => [],
            'newactivities' => [
                [
                    'sectionid' => 42,
                    'modname' => 'page',
                    'prompt' => '  Explain photosynthesis  ',
                    'generateimages' => 1,
                    'draftitemid' => 4242,
                ],
            ],
        ]);

        $entry = $params['newactivities'][0];
        $this->assertSame(42, $entry['sectionid']);
        $this->assertSame('page', $entry['modname']);
        $this->assertSame('  Explain photosynthesis  ', $entry['prompt']);
        $this->assertSame(1, $entry['generateimages']);
        $this->assertSame(4242, $entry['draftitemid']);
    }

    /**
     * A newactivities entry without the new keys still validates, defaulting them to ''/0/0.
     */
    public function test_newactivities_defaults_prompt_generateimages_draftitemid(): void {
        $this->resetAfterTest();

        $params = \external_api::validate_parameters(create_course_from_template::execute_parameters(), [
            'templateid' => 1,
            'newsections' => [],
            'newactivities' => [
                [
                    'sectionid' => 42,
                    'modname' => 'page',
                ],
            ],
        ]);

        $entry = $params['newactivities'][0];
        $this->assertSame('', $entry['prompt']);
        $this->assertSame(0, $entry['generateimages']);
        $this->assertSame(0, $entry['draftitemid']);
    }
}
