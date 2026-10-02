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

use local_coursegen\local\warning_collector;

/**
 * Tests for the request-scoped generation warning collector.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\warning_collector
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\local\warning_collector::class)]
final class warning_collector_test extends \advanced_testcase {
    /**
     * Start every test with an empty collector and no injected failure.
     */
    protected function setUp(): void {
        parent::setUp();
        warning_collector::reset();
        warning_collector::clear_test_failures();
    }

    /**
     * Leave nothing behind for the next test.
     */
    protected function tearDown(): void {
        warning_collector::reset();
        warning_collector::clear_test_failures();
        parent::tearDown();
    }

    /**
     * A successful step records nothing and reports true.
     */
    public function test_attempt_success_records_nothing(): void {
        $ran = false;
        $this->assertTrue(warning_collector::attempt(static function () use (&$ran): void {
            $ran = true;
        }, warning_collector::STEP_FOLDER_FILE, 'notes.pdf'));

        $this->assertTrue($ran);
        $this->assertSame([], warning_collector::drain());
        $this->assertDebuggingNotCalled();
    }

    /**
     * A failing step is recorded with its step key, cleaned subject and one-line reason, logged at
     * the normal debugging level.
     */
    public function test_attempt_failure_records_warning_and_debugs(): void {
        $result = warning_collector::attempt(static function (): void {
            throw new \RuntimeException("Connection\n   refused");
        }, warning_collector::STEP_FOLDER_FILE, 'notes.pdf');

        $this->assertFalse($result);
        $this->assertDebuggingCalled(
            'local_coursegen: generation warning [folder_file] "notes.pdf": Connection refused',
            DEBUG_NORMAL
        );

        $warnings = warning_collector::drain();
        $this->assertSame(
            [['step' => 'folder_file', 'subject' => 'notes.pdf', 'reason' => 'Connection refused']],
            $warnings
        );
        // Draining empties the collector.
        $this->assertSame([], warning_collector::drain());
    }

    /**
     * run() describes the failure without storing it, so holders of their own list can keep it.
     */
    public function test_run_describes_without_storing(): void {
        $this->assertNull(warning_collector::run(static function (): void {
        }, warning_collector::STEP_RUBRIC));

        $failure = warning_collector::run(static function (): void {
            throw new \LogicException('');
        }, warning_collector::STEP_RUBRIC);
        $this->assertDebuggingCalled();

        // An exception without message is described by its class.
        $this->assertSame(['step' => 'rubric', 'subject' => '', 'reason' => 'LogicException'], $failure);
        $this->assertSame([], warning_collector::drain());
    }

    /**
     * Long reasons are truncated to REASON_MAX_LENGTH; long subjects to SUBJECT_MAX_LENGTH.
     */
    public function test_reason_and_subject_are_truncated(): void {
        warning_collector::add(warning_collector::STEP_DATA_FIELD, str_repeat('s', 150), str_repeat('x', 300));
        $this->assertDebuggingCalled();

        $warnings = warning_collector::drain();
        $this->assertSame(warning_collector::REASON_MAX_LENGTH, \core_text::strlen($warnings[0]['reason']));
        $this->assertSame(warning_collector::SUBJECT_MAX_LENGTH, \core_text::strlen($warnings[0]['subject']));
    }

    /**
     * Client messages are localized from the step key and subject only: the raw reason (which may
     * carry URLs, paths or service text) and any markup in the subject never reach them.
     */
    public function test_to_messages_never_discloses_reason_or_markup(): void {
        $reason = 'curl error 500 at https://internal-api.invalid/files/download?path=/var/data/secret.pdf';
        warning_collector::add(warning_collector::STEP_FOLDER_FILE, '<b>x</b>notes.pdf', $reason);
        warning_collector::add(warning_collector::STEP_QUIZ_QUESTION, 'What is "<script>"?', $reason);
        warning_collector::add(warning_collector::STEP_H5P_VERSION, '', $reason);
        $this->assertDebuggingCalledCount(3);

        $messages = warning_collector::to_messages(warning_collector::drain());

        $this->assertSame([
            get_string('generationwarning_folder_file', 'local_coursegen', 'xnotes.pdf'),
            get_string('generationwarning_quiz_question', 'local_coursegen', s('What is ""?')),
            get_string('generationwarning_h5p_version', 'local_coursegen'),
        ], $messages);

        $all = implode("\n", $messages);
        $this->assertStringNotContainsString('<', $all);
        $this->assertStringNotContainsString('internal-api.invalid', $all);
        $this->assertStringNotContainsString('/var/data', $all);
        $this->assertStringNotContainsString('curl error', $all);
        $this->assertStringNotContainsString('<b>', $all);
    }

    /**
     * Every known step has a localized message; an unknown step falls back to the generic one.
     */
    public function test_every_step_is_localized_and_unknown_steps_fall_back(): void {
        $manager = get_string_manager();
        foreach (warning_collector::STEPS as $step) {
            $this->assertTrue(
                $manager->string_exists('generationwarning_' . $step, 'local_coursegen'),
                "The step '{$step}' needs a generationwarning_{$step} string."
            );
        }

        $messages = warning_collector::to_messages([['step' => 'not_a_step', 'subject' => 'a', 'reason' => 'b']]);
        $this->assertSame([get_string('generationwarning_generic', 'local_coursegen', 'not_a_step')], $messages);
    }

    /**
     * The subject cleaner strips tags, collapses whitespace and drops angle brackets; messages
     * escape the result.
     */
    public function test_clean_subject(): void {
        $this->assertSame('xnotes.pdf', warning_collector::clean_subject('<b>x</b>notes.pdf'));
        $this->assertSame('a b', warning_collector::clean_subject("  a \n\t b  "));
        $this->assertSame('1 2 1', warning_collector::clean_subject('1 < 2 > 1'));
        $this->assertSame('Tom & "Jerry"', warning_collector::clean_subject('Tom & "Jerry"'));

        $messages = warning_collector::to_messages([
            ['step' => warning_collector::STEP_DATA_FIELD, 'subject' => 'Tom & "Jerry"', 'reason' => ''],
        ]);
        $this->assertSame(
            [get_string('generationwarning_data_field', 'local_coursegen', s('Tom & "Jerry"'))],
            $messages
        );
    }

    /**
     * An injected failure (PHPUnit seam) makes attempt() record the warning without running the step.
     */
    public function test_injected_failure_skips_the_step(): void {
        warning_collector::set_test_failure(warning_collector::STEP_H5P_VERSION, new \RuntimeException('forced'));

        $ran = false;
        $ok = warning_collector::attempt(static function () use (&$ran): void {
            $ran = true;
        }, warning_collector::STEP_H5P_VERSION);
        $this->assertDebuggingCalled();

        $this->assertFalse($ok);
        $this->assertFalse($ran);
        $this->assertSame('forced', warning_collector::drain()[0]['reason']);

        // Other steps are unaffected, and clearing removes the injection.
        $this->assertTrue(warning_collector::attempt(static function (): void {
        }, warning_collector::STEP_FILETYPE_CATALOG));
        warning_collector::clear_test_failures();
        $this->assertTrue(warning_collector::attempt(static function (): void {
        }, warning_collector::STEP_H5P_VERSION));
        $this->assertDebuggingNotCalled();
    }

    /**
     * reset() discards pending warnings.
     */
    public function test_reset_discards_pending_warnings(): void {
        warning_collector::add(warning_collector::STEP_DATA_ENTRY, '', 'y');
        $this->assertDebuggingCalledCount(1);

        warning_collector::reset();
        $this->assertSame([], warning_collector::drain());
    }
}
