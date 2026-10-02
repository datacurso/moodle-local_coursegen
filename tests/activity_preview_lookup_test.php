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

use local_coursegen\local\preview\activity_preview_lookup;

/**
 * Unit tests for activity_preview_lookup.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\activity_preview_lookup
 */
final class activity_preview_lookup_test extends \basic_testcase {
    /**
     * The payload's kept label and a mould, as the lookup reads them.
     *
     * @return array
     */
    private function payload(): array {
        return [
            'activities' => [
                [
                    'resource_type' => 'label',
                    'uid' => 'kept-uid',
                    'cmid' => 11,
                    'template_behavior' => ['action' => 'keep'],
                    'parameters' => [
                        'name' => 'Welcome',
                        'structure' => ['label' => [['intro' => '<p>Real text</p>']]],
                    ],
                ],
                [
                    'resource_type' => 'page',
                    'uid' => 'written-uid',
                    'cmid' => 12,
                    'template_behavior' => ['action' => 'template'],
                    'parameters' => ['name' => 'Mould', 'structure' => ['page' => [['content' => 'Mould text']]]],
                ],
                [
                    'resource_type' => 'bigbluebuttonbn',
                    'uid' => 'other-uid',
                    'cmid' => 13,
                    'template_behavior' => ['action' => 'keep'],
                    'parameters' => [
                        'name' => 'Meeting',
                        'structure' => ['bigbluebuttonbn' => [['intro' => 'Join the meeting']]],
                    ],
                ],
            ],
        ];
    }

    /**
     * A finished page, with the tree its own result carries.
     *
     * @param array $parameters Replaces the parameters of the page.
     * @return array
     */
    private function finished_page(array $parameters): array {
        return [
            'uid' => 'written-uid',
            'resource_type' => 'page',
            'cmid' => -4,
            'template_behavior' => ['action' => 'instance', 'template_source_cmid' => 12],
            'parameters' => $parameters,
        ];
    }

    /**
     * A kept activity the result echoes is not checked for records: the AI wrote none.
     */
    public function test_a_kept_activity_of_the_answer_is_drawn_without_record_ids(): void {
        $answer = ['generated_activities' => [[
            'uid' => 'kept-uid',
            'resource_type' => 'label',
            'cmid' => 11,
            'template_behavior' => ['action' => 'keep', 'template_source_cmid' => null],
            'parameters' => ['name' => 'Welcome', 'structure' => ['label' => [['intro' => 'x']]]],
        ]]];

        $found = activity_preview_lookup::from_answer($answer, 'kept-uid');

        $this->assertSame('label', $found['modname']);
        $this->assertSame(11, $found['cmid']);
    }

    /**
     * A written activity is drawn from its own result, and from nothing else.
     */
    public function test_a_written_activity_is_taken_from_the_answer_alone(): void {
        $parameters = [
            'name' => 'Drafted',
            'structure' => ['page' => [['id' => '5', 'content' => 'Drafted text']]],
            'structure_tables' => ['page' => 'page'],
        ];
        $answer = ['generated_activities' => [$this->finished_page($parameters)]];

        $found = activity_preview_lookup::from_answer($answer, 'written-uid');

        $this->assertSame('page', $found['modname']);
        $this->assertSame($parameters, $found['parameters']);
    }

    /**
     * The page chrome is the template's course module the result names, not the fake id of the new one.
     */
    public function test_the_page_is_built_on_the_template_course_module_the_result_names(): void {
        $parameters = ['name' => 'Drafted', 'structure' => ['page' => [['id' => '5']]]];
        $answer = ['generated_activities' => [$this->finished_page($parameters)]];

        $found = activity_preview_lookup::from_answer($answer, 'written-uid');

        $this->assertSame(12, $found['cmid']);
    }

    /**
     * An activity the answer does not list is not found there.
     */
    public function test_an_unlisted_activity_is_not_found_in_the_answer(): void {
        $found = activity_preview_lookup::from_answer(['generated_activities' => []], 'kept-uid');

        $this->assertNull($found);
    }

    /**
     * A result written before the tree travelled with it is refused, never completed from the payload.
     */
    public function test_a_result_without_its_tree_is_refused_not_completed(): void {
        $answer = ['generated_activities' => [$this->finished_page(['name' => 'Drafted', 'content' => 'text'])]];

        try {
            activity_preview_lookup::from_answer($answer, 'written-uid');
            $this->fail('A result without its tree must be refused');
        } catch (\moodle_exception $exception) {
            $this->assertSame('courseai_preview_result_outdated', $exception->errorcode);
        }
    }

    /**
     * A record that names a row the tree does not hold is refused with its activity and record.
     */
    public function test_a_record_with_an_unknown_id_is_refused(): void {
        $parameters = [
            'name' => 'Drafted',
            'structure' => ['page' => [['id' => '5']]],
            'mod_settings' => ['sections' => [['source_id' => '404']]],
        ];
        $answer = ['generated_activities' => [$this->finished_page($parameters)]];

        try {
            activity_preview_lookup::from_answer($answer, 'written-uid');
            $this->fail('An unknown id must be refused');
        } catch (\moodle_exception $exception) {
            $this->assertSame('courseai_preview_record_unknown', $exception->errorcode);
        }
    }

    /**
     * An activity of a run that has no result yet is the payload's own copy, as it was sent.
     */
    public function test_a_payload_activity_is_drawn_from_its_own_parameters(): void {
        $found = activity_preview_lookup::from_payload($this->payload(), 'kept-uid');

        $this->assertSame('label', $found['modname']);
        $this->assertSame(11, $found['cmid']);
        $this->assertSame('Welcome', $found['parameters']['name']);
        $this->assertSame('<p>Real text</p>', $found['parameters']['structure']['label'][0]['intro']);
    }

    /**
     * A mould is previewed as it is, built on its own course module.
     */
    public function test_a_mould_in_the_payload_is_built_on_its_own_course_module(): void {
        $found = activity_preview_lookup::from_payload($this->payload(), 'written-uid');

        $this->assertSame(12, $found['cmid']);
    }

    /**
     * A type with no preview of its own shows its description, which the payload copy carries in its tree.
     */
    public function test_a_type_without_its_own_preview_gets_its_description_from_the_tree(): void {
        $found = activity_preview_lookup::from_payload($this->payload(), 'other-uid');

        $this->assertSame('Join the meeting', $found['parameters']['introeditor']['text']);
    }

    /**
     * A uid the payload does not hold is not found.
     */
    public function test_an_unknown_uid_is_not_found_in_the_payload(): void {
        $found = activity_preview_lookup::from_payload($this->payload(), 'nothing');

        $this->assertNull($found);
    }
}
