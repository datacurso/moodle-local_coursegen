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

namespace local_coursegen\local\backup;

use base_processor;

/**
 * A backup processor that answers the values the sources of a structure ask for.
 *
 * A structure is written as "the rows of this table whose column matches the
 * activity being backed up", and the activity is named by backup::VAR_*
 * values rather than hardcoded, which is what lets one declaration serve every
 * instance. Whatever reads a structure has to be given them first.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class backup_vars_processor extends base_processor {
    /** @var array backup::VAR_* => value, read by the sources of the structure. */
    protected array $vars = [];

    /**
     * Give one of the values a structure's sources are allowed to ask for.
     *
     * @param int $key One of the backup::VAR_* constants.
     * @param mixed $value
     */
    public function set_var($key, $value) {
        $this->vars[$key] = $value;
    }

    /**
     * Read one of those values back.
     *
     * @param int $key
     * @return mixed
     */
    public function get_var($key) {
        return $this->vars[$key] ?? null;
    }
}
