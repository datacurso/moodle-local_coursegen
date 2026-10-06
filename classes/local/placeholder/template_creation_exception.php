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

namespace local_coursegen\local\placeholder;

/**
 * Why a template could not be made from a course. The code of the exception is the reason, and it is also the exit
 * code of the command line script.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_creation_exception extends \RuntimeException {
    /** @var int The course does not exist or is the site home. */
    public const COURSE_NOT_FOUND = 2;

    /** @var int The course has no activity. */
    public const NO_ACTIVITIES = 3;

    /** @var int No activity of the course carries a placeholder. */
    public const NO_PLACEHOLDERS = 4;

    /** @var int The tables of the template are not in the database. */
    public const TABLES_MISSING = 5;

    /** @var int A template with this name exists and replacing was not allowed. */
    public const ALREADY_EXISTS = 6;

    /** @var int Several templates share this name and course, so there is no single one to replace. */
    public const AMBIGUOUS = 7;

    /** @var int The template would have no name. */
    public const NAME_EMPTY = 8;

    /** @var int There is no administrator to act as. */
    public const NO_ADMIN = 9;

    /**
     * The reason of the failure.
     *
     * @return int
     */
    public function reason(): int {
        return $this->getCode();
    }
}
