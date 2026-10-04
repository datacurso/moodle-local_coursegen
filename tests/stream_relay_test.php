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

use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\streaming\stream_authorizer;
use local_coursegen\local\streaming\stream_type;
use local_coursegen\local\streaming\stream_relay;
use local_coursegen\tests\fixtures\recording_browser_output;
use local_coursegen\tests\fixtures\scripted_stream_client;

/**
 * Unit tests for the stream relay.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\streaming\stream_relay
 */
final class stream_relay_test extends \advanced_testcase {
    /** @var recording_browser_output Output the relay writes to. */
    private recording_browser_output $output;

    /** @var scripted_stream_client Client that replays a script. */
    private scripted_stream_client $client;

    /** @var \PHPUnit\Framework\MockObject\MockObject Authorizer double. */
    private $authorizer;

    /**
     * Load the fixtures.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        require_once(__DIR__ . '/fixtures/recording_browser_output.php');
        require_once(__DIR__ . '/fixtures/scripted_stream_client.php');
    }

    /**
     * Build the collaborators with an authorizer that allows everything.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->output = new recording_browser_output();
        $this->client = new scripted_stream_client();
        $this->authorizer = $this->createMock(stream_authorizer::class);
    }

    /**
     * Build the relay under test.
     *
     * @return stream_relay
     */
    private function relay(): stream_relay {
        $service = $this->createMock(ai_course_api_service::class);
        $service->method('get_upstream_stream_url')->willReturnCallback([$this, 'upstream_url']);
        $service->method('get_license_key')->willReturn('license-123');

        return new stream_relay($this->authorizer, $service, $this->client, $this->output);
    }

    /**
     * Service double that builds a recognizable upstream URL.
     *
     * @param string $streamtype Stream type.
     * @param string $threadid Thread identifier.
     * @return string
     */
    public function upstream_url(string $streamtype, string $threadid): string {
        return 'https://ai.example.com/' . $streamtype . '/stream/' . $threadid;
    }

    /**
     * Events of the service reach the browser in the same shape and order.
     */
    public function test_events_are_forwarded_in_order(): void {
        $this->client->script = [
            ['event' => 'message', 'data' => '{"type":"status","text":"a"}'],
            ['event' => 'message', 'data' => '{"type":"review_needed"}'],
            ['event' => 'done', 'data' => ''],
        ];

        $this->relay()->run(stream_type::COURSE, 'thread-1');

        $this->assertSame([
            "event: message\ndata: {\"type\":\"status\",\"text\":\"a\"}\n\n",
            "event: message\ndata: {\"type\":\"review_needed\"}\n\n",
            "event: done\ndata: \n\n",
        ], $this->output->written);
    }

    /**
     * The service URL and license key of the stream type reach the client.
     */
    public function test_client_gets_the_url_and_license_key_of_the_stream_type(): void {
        $this->client->script = [['event' => 'done', 'data' => '']];

        $this->relay()->run(stream_type::ACTIVITY, 'job-7');

        $this->assertSame([
            ['url' => 'https://ai.example.com/activity/stream/job-7', 'licensekey' => 'license-123'],
        ], $this->client->calls);
    }

    /**
     * The authorizer decides first, with the stream type and thread asked for.
     */
    public function test_authorizer_is_asked_with_the_stream_type_and_thread(): void {
        $this->client->script = [['event' => 'done', 'data' => '']];
        $this->authorizer->expects($this->once())->method('authorize')->with(stream_type::COURSE, 'thread-1');

        $this->relay()->run(stream_type::COURSE, 'thread-1');
    }

    /**
     * A refused user gets an ordinary error, and nothing is written or requested.
     */
    public function test_refused_user_gets_an_error_and_no_stream(): void {
        $this->authorizer->method('authorize')->willThrowException(
            new \moodle_exception('error_no_session_found', 'local_coursegen')
        );

        try {
            $this->relay()->run(stream_type::COURSE, 'thread-1');
            $this->fail('The refusal must reach the caller.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_no_session_found', $e->errorcode);
        }

        $this->assertSame([], $this->output->written);
        $this->assertSame([], $this->client->calls);
    }

    /**
     * A stream that ends without the done event is closed with a retryable failure for the browser.
     */
    public function test_stream_ending_without_done_is_reported_as_retryable(): void {
        $this->client->script = [['event' => 'message', 'data' => '{"type":"status"}']];

        $this->relay()->run(stream_type::COURSE, 'thread-1');

        $this->assertCount(3, $this->output->written);
        $failure = $this->decode_failure($this->output->written[1]);
        $this->assertSame('failed', $failure['type']);
        $this->assertTrue($failure['retryable']);
        $this->assertSame("event: done\ndata: \n\n", $this->output->written[2]);
    }

    /**
     * A failure of the client is reported to the browser as a failed event followed by done.
     */
    public function test_client_failure_is_reported_to_the_browser(): void {
        $this->client->failure = new \moodle_exception('error_stream_unreachable', 'local_coursegen');

        $this->relay()->run(stream_type::COURSE, 'thread-1');

        $this->assertDebuggingCalled();
        $this->assertCount(2, $this->output->written);
        $failure = $this->decode_failure($this->output->written[0]);
        $this->assertSame('failed', $failure['type']);
        $this->assertSame(get_string('error_stream_unreachable', 'local_coursegen'), $failure['message']);
        $this->assertSame("event: done\ndata: \n\n", $this->output->written[1]);
    }

    /**
     * The failure message never carries the internal error text.
     */
    public function test_failure_message_hides_internal_errors(): void {
        $this->client->failure = new \RuntimeException('cURL error 7 at http://internal-host:8000');

        $this->relay()->run(stream_type::COURSE, 'thread-1');

        $this->assertDebuggingCalled();
        $this->assertStringNotContainsString('internal-host', implode('', $this->output->written));
    }

    /**
     * Nothing is written to a browser that already left.
     */
    public function test_nothing_is_written_after_the_browser_left(): void {
        $this->output->aborted = true;
        $this->client->script = ['tick'];

        $this->relay()->run(stream_type::COURSE, 'thread-1');

        $this->assertSame([], $this->output->written);
    }

    /**
     * A failure after the browser left is logged, not written.
     */
    public function test_failure_after_the_browser_left_is_not_written(): void {
        $this->output->aborted = true;
        $this->client->failure = new \RuntimeException('connection reset');

        $this->relay()->run(stream_type::COURSE, 'thread-1');

        $this->assertDebuggingCalled();
        $this->assertSame([], $this->output->written);
    }

    /**
     * The tick answers true once the browser left, so the client can stop the transfer.
     */
    public function test_tick_reports_that_the_browser_left(): void {
        $relay = $this->relay();

        $this->assertFalse($relay->tick());

        $this->output->aborted = true;
        $this->assertTrue($relay->tick());
    }

    /**
     * The relay writes only what the service sent, even when it stays silent between events.
     */
    public function test_a_silent_service_writes_nothing_to_the_browser(): void {
        $this->client->script = [
            ['event' => 'message', 'data' => '{"type":"status"}'],
            'tick',
            'tick',
            ['event' => 'done', 'data' => ''],
        ];

        $this->relay()->run(stream_type::COURSE, 'thread-1');

        $this->assertSame([
            "event: message\ndata: {\"type\":\"status\"}\n\n",
            "event: done\ndata: \n\n",
        ], $this->output->written);
    }

    /**
     * A failed event of the service passes through like any other event, whatever its code.
     */
    public function test_a_failed_event_of_the_service_is_forwarded_like_any_other(): void {
        $failed = '{"type":"failed","retryable":true,"code":"stream_error","message":"x","result":[]}';
        $this->client->script = [
            ['event' => 'message', 'data' => $failed],
            ['event' => 'done', 'data' => ''],
        ];

        $this->relay()->run(stream_type::COURSE, 'thread-1');

        $this->assertSame([
            "event: message\ndata: " . $failed . "\n\n",
            "event: done\ndata: \n\n",
        ], $this->output->written);
    }

    /**
     * The same relay can carry one stream after another.
     */
    public function test_the_relay_can_run_again_after_a_stream_ended(): void {
        $this->client->script = [['event' => 'done', 'data' => '']];
        $relay = $this->relay();

        $relay->run(stream_type::COURSE, 'thread-1');
        $relay->run(stream_type::COURSE, 'thread-1');

        $this->assertCount(2, $this->client->calls);
    }

    /**
     * Decode the payload of a written failed event.
     *
     * @param string $block Block as written to the browser.
     * @return array
     */
    private function decode_failure(string $block): array {
        $this->assertStringStartsWith("event: message\ndata: ", $block);
        $json = trim(substr($block, strlen("event: message\ndata: ")));
        return json_decode($json, true);
    }
}
