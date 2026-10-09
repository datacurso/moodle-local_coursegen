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

namespace local_coursegen\local\service;

/**
 * What a reloaded page receives of the state of a template run.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_agent_state_view
 */
final class template_agent_state_view_test extends \basic_testcase {
    /**
     * A waiting run carries its question and its events as JSON.
     */
    public function test_a_waiting_run_carries_its_question_and_events(): void {
        $view = template_agent_state_view::export([
            'status' => 'waiting_user',
            'pending_question' => ['type' => 'question', 'call_id' => 'c4', 'question' => 'Which file? 🙂', 'ask_for_file' => true],
            'progress_events' => [['type' => 'status'], ['type' => 'tool_call', 'call_id' => 'c1']],
        ], 't-9', 'https://moodle.test/stream');

        $this->assertSame('WAITING_USER', $view['status']);
        $this->assertSame('t-9', $view['threadid']);
        $this->assertSame('https://moodle.test/stream', $view['streamurl']);
        $this->assertSame('c4', json_decode($view['pendingquestion'], true)['call_id']);
        $this->assertStringContainsString('🙂', $view['pendingquestion']);
        $this->assertCount(2, json_decode($view['progressevents'], true));
    }

    /**
     * A run that waits for nothing has an empty question.
     */
    public function test_no_pending_question_is_an_empty_text(): void {
        foreach ([null, [], 'x', 0] as $question) {
            $view = template_agent_state_view::export(['status' => 'RUNNING', 'pending_question' => $question], 't', 'u');
            $this->assertSame('', $view['pendingquestion']);
        }
    }

    /**
     * A status the page does not know is shown as running.
     */
    public function test_an_unknown_status_is_running(): void {
        foreach ([null, '', 'DONE', 'completed ', 7] as $status) {
            $view = template_agent_state_view::export(['status' => $status], 't', 'u');
            $this->assertContains($view['status'], ['RUNNING', 'COMPLETED']);
        }
        $this->assertSame('RUNNING', template_agent_state_view::export([], 't', 'u')['status']);
        $this->assertSame('COMPLETED', template_agent_state_view::export(['status' => 'Completed'], 't', 'u')['status']);
    }

    /**
     * Only the events that are objects with a type are kept, in order.
     */
    public function test_only_events_with_a_type_are_kept_in_order(): void {
        $view = template_agent_state_view::export(['progress_events' => [
            ['type' => 'a'], 'junk', null, ['no' => 'type'], ['type' => ''], ['type' => 7], ['type' => 'b'],
        ]], 't', 'u');

        $this->assertSame([['type' => 'a'], ['type' => 'b']], json_decode($view['progressevents'], true));
    }

    /**
     * Events that are not a list give an empty list.
     */
    public function test_events_that_are_not_a_list_give_an_empty_list(): void {
        foreach ([null, 'x', 4] as $events) {
            $view = template_agent_state_view::export(['progress_events' => $events], 't', 'u');
            $this->assertSame('[]', $view['progressevents']);
        }
    }

    /**
     * A very long list of events is kept whole.
     */
    public function test_a_long_list_is_kept_whole(): void {
        $events = [];
        for ($index = 0; $index < 3000; $index++) {
            $events[] = ['type' => 'tool_call', 'call_id' => 'c' . $index];
        }

        $view = template_agent_state_view::export(['progress_events' => $events], 't', 'u');

        $this->assertCount(3000, json_decode($view['progressevents'], true));
    }
}
