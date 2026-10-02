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
use local_coursegen\local\service\template_ai_api_service;

/**
 * Unit tests for activity_preview_lookup.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\activity_preview_lookup
 */
final class activity_preview_lookup_test extends \advanced_testcase {
    /**
     * The payload's kept label and a generated page, as the lookup reads them.
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
                    'parameters' => ['name' => 'Mould'],
                ],
            ],
        ];
    }

    /**
     * Runs the lookup of the answer against a service that returns a fixed answer.
     *
     * @param array $answer
     * @param string $uid
     * @return array
     */
    private function from_result(array $answer, string $uid): array {
        $api = new class($answer) extends template_ai_api_service {
            /** @var array The answer handed back. */
            private array $answer;

            /**
             * @param array $answer
             */
            public function __construct(array $answer) {
                $this->answer = $answer;
            }

            #[\Override]
            public function get_result(string $threadid): array {
                return $this->answer;
            }
        };
        $method = new \ReflectionMethod(activity_preview_lookup::class, 'from_result');
        $method->setAccessible(true);
        return $method->invoke(null, $api, 'thread', $uid, $this->payload());
    }

    /**
     * Only the keep action marks an activity as kept.
     */
    public function test_is_kept_reads_the_keep_action(): void {
        $this->assertTrue(activity_preview_lookup::is_kept(['template_behavior' => ['action' => 'keep']]));
        $this->assertFalse(activity_preview_lookup::is_kept(['template_behavior' => ['action' => 'template']]));
        $this->assertFalse(activity_preview_lookup::is_kept(['template_behavior' => []]));
        $this->assertFalse(activity_preview_lookup::is_kept([]));
    }

    /**
     * The answer only echoes a kept activity, so the lookup leaves it to the payload.
     */
    public function test_a_kept_activity_is_not_taken_from_the_answer(): void {
        $answer = ['generated_activities' => [[
            'uid' => 'kept-uid',
            'resource_type' => 'label',
            'template_behavior' => ['action' => 'keep'],
            'parameters' => ['name' => 'Welcome', 'structure' => ['label' => [['intro' => 'x']]]],
        ]]];
        $found = $this->from_result($answer, 'kept-uid');
        $this->assertSame([], $found['parameters']);
        $this->assertSame([], $found['source']);
    }

    /**
     * A written activity still comes from the answer, with its mould as source.
     */
    public function test_a_written_activity_is_taken_from_the_answer(): void {
        $answer = ['generated_activities' => [[
            'uid' => 'written-uid',
            'resource_type' => 'page',
            'template_behavior' => ['action' => 'template', 'template_source_cmid' => 12],
            'parameters' => ['name' => 'Drafted'],
        ]]];
        $found = $this->from_result($answer, 'written-uid');
        $this->assertSame('page', $found['modname']);
        $this->assertSame('Drafted', $found['parameters']['name']);
        $this->assertSame(12, $found['source']['cmid']);
    }

    /**
     * An activity the answer does not list is not found there.
     */
    public function test_an_unlisted_activity_is_not_found_in_the_answer(): void {
        $found = $this->from_result(['generated_activities' => []], 'kept-uid');
        $this->assertSame('', $found['modname']);
    }
}
