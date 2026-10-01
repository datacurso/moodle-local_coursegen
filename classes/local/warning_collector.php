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

use core\exception\coding_exception;
use core_text;

/**
 * Request-scoped collector of non-fatal generation warnings.
 *
 * A generation step that fails without aborting the activity (a file that could
 * not be downloaded, a quiz question that could not be created, a lookup that
 * fell back to a default) used to vanish into developer debugging. Steps now
 * record a warning here. Every warning has a machine step key (one of the
 * STEP_* constants), an optional cleaned subject (the file or question name)
 * and the raw reason. The raw reason is logged at the normal debugging level
 * and audited in the generation_warning event only; the client receives a
 * localized message built from the step key and the subject, never the reason.
 *
 * Steps that run before a module exists (parameter handlers, the editor
 * cleaner, the payload builders) use the static collector; the settings
 * handlers keep their own list through base_settings::attempt() and
 * create_mod_service merges both.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class warning_collector {
    /** @var int Maximum length of a stored reason (also the event field limit). */
    public const REASON_MAX_LENGTH = 255;

    /** @var int Maximum length of a warning subject. */
    public const SUBJECT_MAX_LENGTH = 100;

    /** @var string A folder document could not be downloaded (subject: file name). */
    public const STEP_FOLDER_FILE = 'folder_file';

    /** @var string A generated image referenced by editor text could not be downloaded (subject: file name). */
    public const STEP_IMAGE_DOWNLOAD = 'image_download';

    /** @var string The AI file client could not be initialised for the editor cleaner. */
    public const STEP_CLIENT_INIT = 'client_init';

    /** @var string The assignment rubric could not be created. */
    public const STEP_RUBRIC = 'rubric';

    /** @var string The assignment grading method could not be reset after a rubric failure. */
    public const STEP_GRADING_METHOD = 'grading_method';

    /** @var string A database field could not be created (subject: field name). */
    public const STEP_DATA_FIELD = 'data_field';

    /** @var string A database example entry could not be created. */
    public const STEP_DATA_ENTRY = 'data_entry';

    /** @var string The workshop could not be switched to the requested phase (subject: phase token). */
    public const STEP_WORKSHOP_PHASE = 'workshop_phase';

    /** @var string A quiz question could not be created (subject: question name). */
    public const STEP_QUIZ_QUESTION = 'quiz_question';

    /** @var string The H5P framework version could not be resolved. */
    public const STEP_H5P_VERSION = 'h5p_version';

    /** @var string The site file-type groups could not be resolved. */
    public const STEP_FILETYPE_CATALOG = 'filetype_catalog';

    /** @var string[] Every known step key. */
    public const STEPS = [
        self::STEP_FOLDER_FILE,
        self::STEP_IMAGE_DOWNLOAD,
        self::STEP_CLIENT_INIT,
        self::STEP_RUBRIC,
        self::STEP_GRADING_METHOD,
        self::STEP_DATA_FIELD,
        self::STEP_DATA_ENTRY,
        self::STEP_WORKSHOP_PHASE,
        self::STEP_QUIZ_QUESTION,
        self::STEP_H5P_VERSION,
        self::STEP_FILETYPE_CATALOG,
    ];

    /** @var array<int, array{step: string, subject: string, reason: string}> Warnings collected since the last reset. */
    private static array $warnings = [];

    /** @var array<string, \Throwable> Failures injected per step by PHPUnit tests. */
    private static array $testfailures = [];

    /**
     * Run a step and record a warning instead of propagating its failure.
     *
     * @param callable $step The step to run.
     * @param string $stepkey One of the STEP_* constants.
     * @param string $subject Optional human label of the item (file name, question name, ...).
     * @return bool True when the step completed, false when its failure was recorded.
     */
    public static function attempt(callable $step, string $stepkey, string $subject = ''): bool {
        $failure = self::run($step, $stepkey, $subject);
        if ($failure !== null) {
            self::$warnings[] = $failure;
            return false;
        }

        return true;
    }

    /**
     * Run a step and describe its failure, without storing it in the collector.
     *
     * Used by holders of their own warning list (base_settings::attempt()).
     *
     * @param callable $step The step to run.
     * @param string $stepkey One of the STEP_* constants.
     * @param string $subject Optional human label of the item.
     * @return array{step: string, subject: string, reason: string}|null The logged warning entry, or null on success.
     */
    public static function run(callable $step, string $stepkey, string $subject = ''): ?array {
        try {
            self::throw_injected_failure($stepkey);
            $step();
            return null;
        } catch (\Throwable $e) {
            return self::describe($stepkey, $e, $subject);
        }
    }

    /**
     * Record a warning for a step that failed without an exception.
     *
     * @param string $stepkey One of the STEP_* constants.
     * @param string $subject Optional human label of the item.
     * @param string $reason Why it failed (truncated to REASON_MAX_LENGTH, never shown to the client).
     * @return void
     */
    public static function add(string $stepkey, string $subject, string $reason): void {
        self::$warnings[] = self::entry($stepkey, $subject, $reason);
    }

    /**
     * Build the warning entry for a failed step and log it at the normal debugging level.
     *
     * @param string $stepkey One of the STEP_* constants.
     * @param \Throwable $e The failure.
     * @param string $subject Optional human label of the item.
     * @return array{step: string, subject: string, reason: string}
     */
    public static function describe(string $stepkey, \Throwable $e, string $subject = ''): array {
        return self::entry($stepkey, $subject, self::reason($e));
    }

    /**
     * Return the collected warnings and empty the collector.
     *
     * @return array<int, array{step: string, subject: string, reason: string}>
     */
    public static function drain(): array {
        $warnings = self::$warnings;
        self::$warnings = [];

        return $warnings;
    }

    /**
     * Discard any warning collected so far (called when a new generation request starts).
     *
     * @return void
     */
    public static function reset(): void {
        self::$warnings = [];
    }

    /**
     * Format warning entries as the localized strings returned to the client.
     *
     * Only the step key and the cleaned subject reach the string: the raw
     * reason never leaves the logs and the audit event.
     *
     * @param array<int, array{step: string, subject?: string, reason?: string}> $warnings Warning entries.
     * @return string[]
     */
    public static function to_messages(array $warnings): array {
        $stringmanager = get_string_manager();

        return array_map(static function (array $warning) use ($stringmanager): string {
            $step = (string) ($warning['step'] ?? '');
            $identifier = 'generationwarning_' . $step;
            if ($step === '' || !$stringmanager->string_exists($identifier, 'local_coursegen')) {
                return get_string('generationwarning_generic', 'local_coursegen', $step);
            }

            // The subject is cleaned when recorded; it is escaped here for the message.
            $subject = s(self::clean_subject((string) ($warning['subject'] ?? '')));

            return get_string($identifier, 'local_coursegen', $subject);
        }, $warnings);
    }

    /**
     * Reduce a subject to a short plain-text label safe for messages and events.
     *
     * Tags are stripped, the text is cleaned as PARAM_TEXT, angle brackets are
     * dropped and the result is shortened to SUBJECT_MAX_LENGTH characters, so
     * no markup can survive; to_messages() escapes it with s() on output.
     *
     * @param string $subject Raw label (file name, question name, phase token, ...).
     * @return string
     */
    public static function clean_subject(string $subject): string {
        $subject = clean_param(strip_tags($subject), PARAM_TEXT);
        $subject = str_replace(['<', '>'], '', $subject);
        $subject = trim((string) preg_replace('/\s+/u', ' ', $subject));

        return core_text::substr($subject, 0, self::SUBJECT_MAX_LENGTH);
    }

    /**
     * Inject a failure for a step: attempt() throws it instead of running the step. PHPUnit only.
     *
     * @param string $stepkey One of the STEP_* constants.
     * @param \Throwable|null $failure The failure to raise, or null to remove the injection.
     * @return void
     * @throws coding_exception When called outside a PHPUnit run.
     */
    public static function set_test_failure(string $stepkey, ?\Throwable $failure): void {
        if (!(defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            throw new coding_exception('warning_collector::set_test_failure() can only be used in PHPUnit tests.');
        }

        if ($failure === null) {
            unset(self::$testfailures[$stepkey]);
            return;
        }
        self::$testfailures[$stepkey] = $failure;
    }

    /**
     * Remove every injected failure. PHPUnit only.
     *
     * @return void
     * @throws coding_exception When called outside a PHPUnit run.
     */
    public static function clear_test_failures(): void {
        if (!(defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            throw new coding_exception('warning_collector::clear_test_failures() can only be used in PHPUnit tests.');
        }

        self::$testfailures = [];
    }

    /**
     * Raise the failure injected for a step, if any (PHPUnit runs only).
     *
     * @param string $stepkey One of the STEP_* constants.
     * @return void
     */
    private static function throw_injected_failure(string $stepkey): void {
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST && isset(self::$testfailures[$stepkey])) {
            throw self::$testfailures[$stepkey];
        }
    }

    /**
     * Reduce a throwable to a one-line reason (its message, or its class when the message is empty).
     *
     * @param \Throwable $e The failure.
     * @return string
     */
    private static function reason(\Throwable $e): string {
        $message = trim((string) preg_replace('/\s+/u', ' ', $e->getMessage()));

        return $message !== '' ? $message : get_class($e);
    }

    /**
     * Build a warning entry and log it at the normal debugging level (visible in production logs).
     *
     * @param string $stepkey One of the STEP_* constants.
     * @param string $subject Optional human label of the item.
     * @param string $reason Why it failed.
     * @return array{step: string, subject: string, reason: string}
     */
    private static function entry(string $stepkey, string $subject, string $reason): array {
        $subject = self::clean_subject($subject);
        $reason = core_text::substr($reason, 0, self::REASON_MAX_LENGTH);
        debugging('local_coursegen: generation warning [' . $stepkey . ']'
            . ($subject !== '' ? ' "' . $subject . '"' : '') . ': ' . $reason);

        return ['step' => $stepkey, 'subject' => $subject, 'reason' => $reason];
    }
}
