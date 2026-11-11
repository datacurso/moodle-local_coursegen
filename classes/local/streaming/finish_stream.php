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

namespace local_coursegen\local\streaming;

use local_coursegen\external\finish_template_generation;

/**
 * The creation of the course of a finished generation, told to the browser as it goes.
 *
 * Creating the course is the longest step that runs inside a request of the site. The proxies in front of a site cut
 * a request that stays silent for two minutes, and a course of a hundred activities takes longer than that on a busy
 * site. Here the answer is a stream: something is written after each activity, so the connection is never silent, and
 * the creation goes on even if the browser leaves, because the course is built once and the page asks again.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finish_stream {
    /** @var int Seconds the creation may take, at most. */
    private const MAX_SECONDS = 7200;

    /** @var browser_output Writer to the browser. */
    private $output;

    /** @var callable|null Builds the course: (sessionid, fullname, shortname, category, progress) => array. */
    private $builder;

    /**
     * Constructor. The writer and the builder can be replaced, which is how tests run without a browser.
     *
     * @param browser_output|null $output
     * @param callable|null $builder
     */
    public function __construct(?browser_output $output = null, ?callable $builder = null) {
        $this->output = $output ?? new browser_output();
        $this->builder = $builder ?? [finish_template_generation::class, 'build_course'];
    }

    /**
     * Create the course and tell the browser how it goes.
     *
     * @param int $sessionid Local session id.
     * @param string $fullname Course name chosen at the review.
     * @param string $shortname Course shortname chosen at the review.
     * @param int $category Course category chosen at the review.
     */
    public function run(int $sessionid, string $fullname, string $shortname, int $category): void {
        $this->prepare_output();
        $this->output->send(sse_formatter::comment('open'));

        try {
            $result = ($this->builder)($sessionid, $fullname, $shortname, $category, function (string $step, int $done = 0, int $total = 0) {
                $this->send_progress($step, $done, $total);
            });
            $this->output->send(sse_formatter::event('result', json_encode($result)));
        } catch (\Throwable $e) {
            error_log('local_coursegen: creating the course of session ' . $sessionid . ' failed: ' . get_class($e) . ': '
                . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            $code = $e instanceof \moodle_exception ? (string) $e->errorcode : 'failed';
            $this->output->send(sse_formatter::event('failed', json_encode([
                'errorcode' => $code,
                'message' => get_string('error_course_creation_failed', 'local_coursegen'),
            ])));
        }
        $this->output->send(sse_formatter::event('done', '{}'));
    }

    /**
     * Tell the browser what is being done.
     *
     * @param string $step What the creation is doing.
     * @param int $done Activities made so far.
     * @param int $total Activities to make.
     */
    private function send_progress(string $step, int $done, int $total): void {
        $this->output->send(sse_formatter::event('progress', json_encode([
            'step' => $step,
            'done' => $done,
            'total' => $total,
            'message' => $this->message_of($step, $done, $total),
        ])));
    }

    /**
     * The words for a step, in the language of the person.
     *
     * @param string $step
     * @param int $done
     * @param int $total
     * @return string
     */
    private function message_of(string $step, int $done, int $total): string {
        $key = 'courseai_template_creating_' . $step;
        if (!get_string_manager()->string_exists($key, 'local_coursegen')) {
            return '';
        }
        return get_string($key, 'local_coursegen', (object) ['done' => $done, 'total' => $total]);
    }

    /**
     * Set up the response: release the session lock, outlive the browser and open the stream.
     */
    private function prepare_output(): void {
        \core\session\manager::write_close();
        ignore_user_abort(true);
        \core_php_time_limit::raise(self::MAX_SECONDS);

        if (headers_sent()) {
            return;
        }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
    }
}
