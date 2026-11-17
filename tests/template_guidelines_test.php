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

use local_coursegen\external\start_template_generation;
use local_coursegen\local\models\template;
use local_coursegen\local\service\system_instruction_service;
use local_coursegen\local\service\template_export_service;

/**
 * The institutional guideline a professor picks for a template run, from the id on the request to the payload.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\start_template_generation
 * @covers     \local_coursegen\local\service\template_export_service
 *
 * @runTestsInSeparateProcesses
 */
final class template_guidelines_test extends \advanced_testcase {
    /**
     * Save a template record over a fresh course.
     *
     * @return int The template id.
     */
    private function template_id(): int {
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $template = new template(0, (object) ['name' => 'Guideline fixture', 'courseid' => $course->id]);
        $template->create();
        return (int) $template->get('id');
    }

    /**
     * A payload without the random uid each section gets on every export.
     *
     * @param array $payload
     * @return string
     */
    private function stable(array $payload): string {
        foreach ($payload['sections_info'] as &$entry) {
            unset($entry['uid']);
        }
        foreach ($payload['activities'] as &$entry) {
            unset($entry['uid']);
        }
        return json_encode($payload);
    }

    /**
     * The request has the optional guideline id, null by default, and no parameter for a text.
     */
    public function test_start_parameters_declare_only_the_optional_guideline_id(): void {
        $keys = start_template_generation::execute_parameters()->keys;
        $this->assertArrayHasKey('systeminstructionid', $keys);
        $this->assertSame(PARAM_INT, $keys['systeminstructionid']->type);
        $this->assertSame(VALUE_DEFAULT, $keys['systeminstructionid']->required);
        $this->assertNull($keys['systeminstructionid']->default);
        $this->assertArrayNotHasKey('instructions', $keys);
    }

    /**
     * A valid id puts the stored text of the guideline in the payload.
     */
    public function test_payload_carries_the_text_stored_for_the_id(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $templateid = $this->template_id();
        $instruction = system_instruction_service::create('Tone', 'Always be formal.');

        $payload = start_template_generation::payload_for(
            $templateid, '', '', false, null, (int) $instruction->get('id')
        );
        $this->assertSame('Always be formal.', $payload['instructions']);
    }

    /**
     * With no guideline chosen, or one that does not resolve, the payload has no such key at all.
     */
    public function test_payload_has_no_key_when_nothing_resolves(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $templateid = $this->template_id();
        $deleted = system_instruction_service::create('Gone', 'Old text.');
        system_instruction_service::delete((int) $deleted->get('id'));
        $empty = system_instruction_service::create('Empty', '0');
        $plain = start_template_generation::payload_for($templateid, '', '', false, null);

        $ids = [null, 0, -4, 99999, (int) $deleted->get('id'), (int) $empty->get('id')];
        foreach ($ids as $id) {
            $payload = start_template_generation::payload_for($templateid, '', '', false, null, $id);
            $this->assertArrayNotHasKey('instructions', $payload, 'id ' . var_export($id, true));
            $this->assertSame($this->stable($plain), $this->stable($payload));
        }
    }

    /**
     * The export takes the resolved text as a trailing optional argument, and only then adds the key.
     */
    public function test_export_adds_the_key_only_for_a_text(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $templateid = $this->template_id();

        $old = template_export_service::build_init_payload($templateid, '', '', false, null);
        $blank = template_export_service::build_init_payload($templateid, '', '', false, null, '');
        $given = template_export_service::build_init_payload($templateid, 'adapt it', '', false, null, 'Rule one.');

        $this->assertArrayNotHasKey('instructions', $old);
        $this->assertSame($this->stable($old), $this->stable($blank));
        $this->assertSame('Rule one.', $given['instructions']);
        $this->assertSame('adapt it', $given['general_instruction']);
    }

    /**
     * The session keeps the id under the keys the free mode uses, only when one was chosen.
     */
    public function test_coursedata_keeps_the_guideline_id_like_free_mode(): void {
        $payload = ['lang' => 'en'];
        $plain = start_template_generation::coursedata_for(7, $payload, '', 0);
        $this->assertSame(['templateid', 'payload', 'syllabus'], array_keys($plain));

        $chosen = start_template_generation::coursedata_for(7, $payload, 'a.pdf', 12);
        $this->assertSame(1, $chosen['local_coursegen_use_system_instruction']);
        $this->assertSame(12, $chosen['local_coursegen_select_system_instruction']);
        $this->assertSame('a.pdf', $chosen['syllabus']);
    }

    /**
     * The run takes the guideline id once and gives that same id to the payload and to the session data.
     */
    public function test_prepare_wires_one_id_into_payload_and_coursedata(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $templateid = $this->template_id();
        $instruction = system_instruction_service::create('Tone', 'Always be formal.');
        $id = (int) $instruction->get('id');

        [$payload, $coursedata] = start_template_generation::prepare($templateid, 'adapt', '', false, null, $id);

        $this->assertSame('Always be formal.', $payload['instructions']);
        $this->assertSame($payload, $coursedata['payload']);
        $this->assertSame($templateid, $coursedata['templateid']);
        $this->assertSame(1, $coursedata['local_coursegen_use_system_instruction']);
        $this->assertSame($id, $coursedata['local_coursegen_select_system_instruction']);
    }

    /**
     * No id, zero or a deleted guideline: no text in the payload and no guideline keys in the session data.
     */
    public function test_prepare_adds_nothing_when_no_guideline_resolves(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $templateid = $this->template_id();
        $deleted = system_instruction_service::create('Gone', 'Old text.');
        system_instruction_service::delete((int) $deleted->get('id'));

        foreach ([null, 0, (int) $deleted->get('id')] as $id) {
            [$payload, $coursedata] = start_template_generation::prepare($templateid, '', '', false, null, $id);
            $label = 'id ' . var_export($id, true);
            $this->assertArrayNotHasKey('instructions', $payload, $label);
            $this->assertArrayNotHasKey('local_coursegen_use_system_instruction', $coursedata, $label);
            $this->assertArrayNotHasKey('local_coursegen_select_system_instruction', $coursedata, $label);
        }
    }
}
