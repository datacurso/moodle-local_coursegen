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

namespace local_coursegen\mod_settings;

use local_coursegen\local\warning_collector;

/**
 * Class base_settings
 *
 * Base of the per-module settings handlers that complete a freshly created
 * module with the extra data the AI service returned in mod_settings. Steps
 * that may fail without invalidating the module run through attempt(), so
 * the failure is recorded as a warning (and later reported to the client and
 * audited) instead of silently disappearing.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_settings {
    /** @var object Course module object */
    protected object $cm;

    /** @var array Module settings object */
    protected array $modsettings;

    /** @var array<int, array{step: string, subject: string, reason: string}> Warnings recorded by attempt(). */
    private array $warnings = [];

    /**
     * Constructor.
     *
     * @param object $cm Course module
     * @param array $modsettings Module settings
     */
    public function __construct(object $cm, array $modsettings) {
        $this->cm = $cm;
        $this->modsettings = $modsettings;
    }

    /**
     * Add specific settings for module.
     */
    abstract public function add_settings();

    /**
     * Run a settings step and record a warning instead of propagating its failure.
     *
     * @param callable $step The step to run.
     * @param string $stepkey One of the warning_collector::STEP_* constants.
     * @param string $subject Optional human label of the item (question name, field name, ...).
     * @return bool True when the step completed, false when its failure was recorded.
     */
    protected function attempt(callable $step, string $stepkey, string $subject = ''): bool {
        $failure = warning_collector::run($step, $stepkey, $subject);
        if ($failure !== null) {
            $this->warnings[] = $failure;
            return false;
        }

        return true;
    }

    /**
     * Warnings recorded while applying the settings.
     *
     * @return array<int, array{step: string, subject: string, reason: string}>
     */
    public function get_warnings(): array {
        return $this->warnings;
    }
}
