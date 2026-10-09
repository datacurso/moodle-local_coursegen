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
 * Sends the change request of a teacher to a template run that completed.
 *
 * It checks the request before the service sees it and turns the refusals of the service into words the teacher
 * can act on, so the review stays open with a reason instead of a generic error.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_adjuster {
    /** @var int Longest change request the page sends, in characters. */
    public const MAX_CHARS = 4000;

    /** @var template_ai_api_service Client of the template agent endpoints. */
    private template_ai_api_service $api;

    /**
     * Constructor.
     *
     * @param template_ai_api_service|null $api Optional pre-built service client; tests pass a mock.
     */
    public function __construct(?template_ai_api_service $api = null) {
        if ($api === null) {
            $api = new template_ai_api_service();
        }
        $this->api = $api;
    }

    /**
     * Ask the completed run to change its result.
     *
     * @param string $threadid Thread id of the run, for example "5c1e2a".
     * @param string $callid Id of this request, for example "adj1k3".
     * @param string $instruction What to change, for example "Make the guide shorter".
     * @param string $aid Draft id of the only activity to change, for example "t:11342"; empty for the whole result.
     * @return array What the service stored: round and aids.
     * @throws \moodle_exception When the request is not valid or the service refuses it.
     */
    public function adjust(string $threadid, string $callid, string $instruction, string $aid): array {
        $text = trim($instruction);
        if ($text === '') {
            throw new \moodle_exception('templateadjustblank', 'local_coursegen');
        }
        if (\core_text::strlen($text) > self::MAX_CHARS) {
            throw new \moodle_exception('templateadjusttoolong', 'local_coursegen', '', self::MAX_CHARS);
        }
        if ($aid !== '' && !preg_match('/^t:[0-9]+$/', $aid)) {
            throw new \moodle_exception('templateadjustrefused', 'local_coursegen');
        }
        try {
            return $this->api->adjust($threadid, $callid, $text, $aid);
        } catch (\moodle_exception $exception) {
            throw $this->explain($exception);
        }
    }

    /**
     * The exception to show for one the service raised.
     *
     * @param \moodle_exception $exception What the service client threw.
     * @return \moodle_exception The one that explains it, or the same when it is not a refusal.
     */
    private function explain(\moodle_exception $exception): \moodle_exception {
        if ($exception->errorcode !== 'httperror') {
            return $exception;
        }
        $strings = [
            404 => 'templateadjustgone',
            409 => 'templateadjustbusy',
            422 => 'templateadjustrefused',
        ];
        $code = (int) $exception->a;
        if (!isset($strings[$code])) {
            return $exception;
        }
        return new \moodle_exception($strings[$code], 'local_coursegen');
    }
}
