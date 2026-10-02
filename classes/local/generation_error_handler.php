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

namespace local_coursegen\local;

use core\context;
use core\exception\moodle_exception;
use core\exception\required_capability_exception;
use local_coursegen\event\generation_denied;
use local_coursegen\event\generation_failed;

/**
 * Shared error handling for the activity generation endpoints.
 *
 * create_mod and create_mod_stream answer errors in-band ({ok: false, message})
 * instead of letting the exception propagate. Both handlers audit the outcome
 * with the same events and keep the technical detail out of the response:
 * a permission error is already localized and travels verbatim, anything
 * else is logged (developer debugging) and replaced by a generic message.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class generation_error_handler {
    /** @var int Number of stack frames written to the error log for unexpected failures. */
    private const TRACE_LINES = 5;

    /**
     * Handle a capability rejection: audit it and build the in-band error response.
     *
     * @param required_capability_exception $e The rejection.
     * @param context $context Context of the generation (course when known, system otherwise).
     * @param array $other Extra event data merged into 'other' (never overrides 'capability').
     * @param string $operation Short description of what was attempted, for the debugging line.
     * @return array Response with 'ok' => false and the localized permission message.
     */
    public static function handle_denied(
        required_capability_exception $e,
        context $context,
        array $other = [],
        string $operation = 'AI generation'
    ): array {
        // Permission errors are already localized and safe to show verbatim.
        debugging('Permission error while ' . $operation . ': ' . $e->getMessage());

        // The exception carries the localized capability name in ->a; the
        // raw capability string is not stored on it.
        generation_denied::create([
            'context' => $context,
            'other' => ['capability' => is_string($e->a ?? null) ? $e->a : ''] + $other,
        ])->trigger();

        return [
            'ok' => false,
            'message' => $e->getMessage(),
        ];
    }

    /**
     * Handle any other failure: audit its class and build the generic in-band error response.
     *
     * @param \Throwable $e The failure (a TypeError is handled like an exception).
     * @param context $context Context of the generation (course when known, system otherwise).
     * @param array $other Extra event data merged into 'other' (never overrides 'reason').
     * @param string $operation Short description of what was attempted, for the debugging line.
     * @return array Response with 'ok' => false and the localized generic message.
     */
    public static function handle_failure(
        \Throwable $e,
        context $context,
        array $other = [],
        string $operation = 'AI generation'
    ): array {
        // Keep the technical detail in developer debugging only: the client
        // receives a localized message without internal information.
        debugging('Unexpected error while ' . $operation . ': ' . $e->getMessage());

        // A plain PHP error or a non-Moodle exception never goes through
        // Moodle's exception handler, so it would leave no trace in production
        // once answered in-band: write it to the PHP error log as well.
        if (!($e instanceof moodle_exception)) {
            self::log_unexpected($e, $operation);
        }

        // Only the sanitized reason (the class name) and the origin (file name
        // and line, no path) are audited; the message may embed prompts or
        // service content.
        generation_failed::create([
            'context' => $context,
            'other' => ['reason' => get_class($e), 'origin' => self::origin($e)] + $other,
        ])->trigger();

        return [
            'ok' => false,
            'message' => get_string('error_generating_resource', 'local_coursegen'),
        ];
    }

    /**
     * Where the failure was raised, as "file:line" without the directory.
     *
     * @param \Throwable $e The failure.
     * @return string
     */
    public static function origin(\Throwable $e): string {
        return basename($e->getFile()) . ':' . $e->getLine();
    }

    /**
     * Write an unexpected failure (class, message, origin, first trace frames) to the PHP error log.
     *
     * @param \Throwable $e The failure.
     * @param string $operation Short description of what was attempted.
     * @return void
     */
    private static function log_unexpected(\Throwable $e, string $operation): void {
        $trace = array_slice(explode("\n", $e->getTraceAsString()), 0, self::TRACE_LINES);
        error_log(
            'local_coursegen: unexpected ' . get_class($e) . ' while ' . $operation . ': ' . $e->getMessage()
            . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n" . implode("\n", $trace)
        );
    }
}
