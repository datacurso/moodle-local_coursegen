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

/**
 * Class base_settings
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
}
