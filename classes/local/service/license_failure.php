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
 * A request the AI service refused because of the license: no credits left, a license that is not valid or not
 * allowed here, or a consumption limit reached.
 *
 * The person is told what happened and what to do, in plain text, instead of a generic "it could not be done":
 * waiting does not mend it, credits or a valid license do.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class license_failure {
    /** The component whose client raises the refusals of the service. */
    private const PROVIDER = 'aiprovider_datacurso';

    /** No credits left on the license. */
    public const NO_CREDITS = 'notenoughtokens';

    /** The codes the provider gives to a refusal because of the license. */
    public const CODES = [
        self::NO_CREDITS,
        'license_not_allowed',
        'invalidlicensekey',
        'forbidden',
        'error_ratelimit_exceeded',
    ];

    /**
     * Whether an error is a refusal because of the license.
     *
     * @param \Throwable $error
     * @return bool
     */
    public static function is(\Throwable $error): bool {
        return $error instanceof \moodle_exception
            && $error->module === self::PROVIDER
            && in_array($error->errorcode, self::CODES, true);
    }

    /**
     * What the person is told about a refusal because of the license, in plain text; null for any other error.
     *
     * @param \Throwable $error
     * @return string|null
     */
    public static function message(\Throwable $error): ?string {
        if (!self::is($error)) {
            return null;
        }
        if ($error->errorcode === self::NO_CREDITS) {
            return get_string('courseai_license_no_credits', 'local_coursegen');
        }
        // The provider says it best (a limit gives the time it ends); its text carries a link, kept out of plain text.
        return trim(html_entity_decode(strip_tags($error->getMessage()), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
