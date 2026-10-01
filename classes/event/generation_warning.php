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

namespace local_coursegen\event;

use core\exception\coding_exception;
use core_text;
use local_coursegen\local\warning_collector;

/**
 * Event fired when a step of an AI generation failed without aborting it.
 *
 * One event per recorded warning (see \local_coursegen\local\warning_collector):
 * a file that could not be downloaded, a quiz question that could not be
 * created, a lookup that fell back to a default. The payload carries the
 * module name, the machine step key, the cleaned subject and the raw reason
 * (warning_collector::REASON_MAX_LENGTH characters at most); prompts and
 * generated content never travel here. The context is the course the activity
 * is generated in when it is known, the system context otherwise.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generation_warning extends \core\event\base {
    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Build the event for one collected warning.
     *
     * @param \core\context $context Course context when known, system context otherwise.
     * @param string $modname Module being generated ('' when not known yet).
     * @param array $warning Warning entry with 'step', 'subject' and 'reason' keys.
     * @return self
     */
    public static function create_from_warning(\core\context $context, string $modname, array $warning): self {
        return self::create([
            'context' => $context,
            'other' => [
                'modname' => $modname,
                'step' => (string) ($warning['step'] ?? ''),
                'subject' => warning_collector::clean_subject((string) ($warning['subject'] ?? '')),
                'reason' => core_text::substr(
                    (string) ($warning['reason'] ?? ''),
                    0,
                    warning_collector::REASON_MAX_LENGTH
                ),
            ],
        ]);
    }

    /**
     * Fire one event per collected warning.
     *
     * Auditing must never mask the outcome of the generation: a failure while
     * triggering an event is reported through debugging() and the loop goes on.
     *
     * @param \core\context $context Course context when known, system context otherwise.
     * @param string $modname Module being generated ('' when not known yet).
     * @param array $warnings Warning entries as collected by warning_collector.
     * @return void
     */
    public static function trigger_all(\core\context $context, string $modname, array $warnings): void {
        foreach ($warnings as $warning) {
            try {
                self::create_from_warning($context, $modname, (array) $warning)->trigger();
            } catch (\Throwable $e) {
                debugging('local_coursegen: could not audit a generation warning: ' . $e->getMessage());
            }
        }
    }

    /**
     * Returns localised general event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_generation_warning', 'local_coursegen');
    }

    /**
     * Returns non-localised description of what happened.
     *
     * @return string
     */
    public function get_description() {
        $modname = $this->other['modname'] ?? '';
        $step = $this->other['step'] ?? '';
        $subject = $this->other['subject'] ?? '';
        $reason = $this->other['reason'] ?? '';
        return "An AI generation ('$modname') started by the user with id '$this->userid' "
            . "completed with a warning in step '$step'" . ($subject !== '' ? " ('$subject')" : '') . ": $reason";
    }

    /**
     * Custom validation: every payload key is mandatory.
     *
     * @return void
     * @throws coding_exception When a key is missing from 'other'.
     */
    protected function validate_data() {
        parent::validate_data();

        foreach (['modname', 'step', 'subject', 'reason'] as $key) {
            if (!is_array($this->other) || !array_key_exists($key, $this->other)) {
                throw new coding_exception("The '$key' value must be set in other.");
            }
        }
    }
}
