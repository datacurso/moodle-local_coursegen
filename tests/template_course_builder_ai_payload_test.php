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

use local_coursegen\local\models\template;
use local_coursegen\local\models\template_section;
use local_coursegen\local\service\template_course_builder_service;

/**
 * AI payload contract of template_course_builder_service for new activities.
 *
 * The template-mode activity chooser lets the professor attach a prompt, a
 * generate-images choice and an uploaded file (draftitemid) to each new
 * activity. process_new_activities() must thread those per-activity values
 * into the generate() payload handed to the injected template_content_generator
 * — trimming the prompt and defaulting to ''/0/0 when absent.
 *
 * The build flow loads course/externallib.php (through create_mod_service),
 * which requires each test to run in an isolated process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_course_builder_service
 *
 * @runTestsInSeparateProcesses
 */
final class template_course_builder_ai_payload_test extends \advanced_testcase {
    /**
     * Load the capturing AI-service double in the isolated process.
     */
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/capturing_template_ai_service.php');

        // Note: the module edit form used by create_from_ai_result resolves
        // section info against the front page (see create_mod_permissions_test).
        // Give the front page the section rows a real site has.
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        course_create_sections_if_missing(get_site(), [0, 1]);
    }

    /**
     * Reset the injected double between tests.
     */
    protected function tearDown(): void {
        template_course_builder_service::set_ai_service(null);
        parent::tearDown();
    }

    /**
     * Create a base course + template fixture with one behavior=custom section.
     *
     * @return array{0:template,1:int} The template and the base course's section-1 id.
     */
    private function create_template_fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $sectionid = (int) $DB->get_field(
            'course_sections',
            'id',
            ['course' => $course->id, 'section' => 1],
            MUST_EXIST
        );

        $template = new template(0, (object) [
            'name' => 'Chooser prompt template',
            'courseid' => (int) $course->id,
            'nolimit' => 1,
            'allowedtypes' => json_encode(['page']),
        ]);
        $template->create();

        $section = new template_section(0, (object) [
            'templateid' => (int) $template->get('id'),
            'sectionid' => $sectionid,
            'sectionnum' => 1,
            'behavior' => 'custom',
        ]);
        $section->create();

        return [$template, $sectionid];
    }

    /**
     * The per-activity prompt/generateimages/draftitemid reach the generate() payload (prompt trimmed).
     */
    public function test_new_activity_payload_carries_prompt_generateimages_draftitemid(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        [$template, $sectionid] = $this->create_template_fixture();

        $fake = new capturing_template_ai_service();
        template_course_builder_service::set_ai_service($fake);

        $result = template_course_builder_service::create_course_from_template(
            $template,
            [],
            [
                [
                    'sectionid' => $sectionid,
                    'modname' => 'page',
                    'prompt' => '  Explain photosynthesis  ',
                    'generateimages' => 1,
                    'draftitemid' => 4242,
                ],
            ],
            (int) $USER->id
        );

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['activityerrors']);
        $this->assertCount(1, $fake->payloads);

        $payload = $fake->payloads[0];
        $this->assertSame('page', $payload['modname']);
        $this->assertSame('Explain photosynthesis', $payload['prompt']);
        $this->assertSame(1, $payload['generateimages']);
        $this->assertSame(4242, $payload['draftitemid']);
    }

    /**
     * A newactivities entry without the new keys still generates, with ''/0/0 in the payload.
     */
    public function test_new_activity_payload_defaults_when_not_provided(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        [$template, $sectionid] = $this->create_template_fixture();

        $fake = new capturing_template_ai_service();
        template_course_builder_service::set_ai_service($fake);

        $result = template_course_builder_service::create_course_from_template(
            $template,
            [],
            [
                [
                    'sectionid' => $sectionid,
                    'modname' => 'page',
                ],
            ],
            (int) $USER->id
        );

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['activityerrors']);
        $this->assertCount(1, $fake->payloads);

        $payload = $fake->payloads[0];
        $this->assertSame('', $payload['prompt']);
        $this->assertSame(0, $payload['generateimages']);
        $this->assertSame(0, $payload['draftitemid']);
    }
}
