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
 * Turns the snapshot the service returns for a run into what a reloaded page repaints from.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_agent_state_view {
    /** @var string Status used when the service sends none the page can read. */
    private const UNKNOWN_STATUS = 'RUNNING';

    /**
     * Build the view of a snapshot.
     *
     * @param array $state Snapshot of GET /state: status, pending_question and progress_events.
     * @param string $threadid Thread id of the run, for example "5c1e2a".
     * @param string $streamurl Relay URL of the stream of the run.
     * @return array status, threadid, streamurl, pendingquestion and progressevents.
     */
    public static function export(array $state, string $threadid, string $streamurl): array {
        return [
            'status' => self::status($state),
            'threadid' => $threadid,
            'streamurl' => $streamurl,
            'pendingquestion' => self::pending_question($state),
            'progressevents' => self::progress_events($state),
        ];
    }

    /**
     * The status, kept to the letters and underscores the page knows.
     *
     * @param array $state Snapshot of the run.
     * @return string RUNNING, WAITING_USER, COMPLETED or FAILED.
     */
    private static function status(array $state): string {
        $raw = $state['status'] ?? '';
        $text = (string) $raw;
        $status = strtoupper($text);
        $known = ['RUNNING', 'WAITING_USER', 'COMPLETED', 'FAILED'];
        if (!in_array($status, $known, true)) {
            return self::UNKNOWN_STATUS;
        }
        return $status;
    }

    /**
     * The pending question as JSON, or an empty string when the run waits for nothing.
     *
     * @param array $state Snapshot of the run.
     * @return string JSON of the question event.
     */
    private static function pending_question(array $state): string {
        $question = $state['pending_question'] ?? null;
        if (!is_array($question) || $question === []) {
            return '';
        }
        return json_encode($question, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The events of the run as a JSON list, keeping only the objects the page can replay.
     *
     * @param array $state Snapshot of the run.
     * @return string JSON list of events.
     */
    private static function progress_events(array $state): string {
        $events = $state['progress_events'] ?? [];
        if (!is_array($events)) {
            return '[]';
        }
        $replayable = array_filter($events, [self::class, 'is_replayable']);
        $ordered = array_values($replayable);
        return json_encode($ordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Whether an entry of the progress events is an event the page can replay.
     *
     * @param mixed $event Entry of the list.
     * @return bool True for an object that has a type.
     */
    public static function is_replayable($event): bool {
        if (!is_array($event)) {
            return false;
        }
        $type = $event['type'] ?? '';
        return is_string($type) && $type !== '';
    }
}
