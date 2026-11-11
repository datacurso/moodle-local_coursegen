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

namespace local_coursegen\local\preview\lesson;

/**
 * A bag of properties with magic accessors, as mod_lesson keeps its objects.
 *
 * Copied from mod/lesson/locallib.php (class lesson_base, Moodle 4.5) with
 * nothing changed. It is here so the rest of the port can be read against the
 * original line for line.
 *
 * @package    local_coursegen
 * @copyright  2009 Sam Hemelryk, 2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class lesson_base {
    /** @var \stdClass An object containing properties. */
    protected $properties;

    /**
     * The constructor.
     *
     * @param \stdClass|array $properties
     */
    public function __construct($properties) {
        $this->properties = (object) $properties;
    }

    /**
     * Magic set: a set_$key method if there is one, then the property itself.
     *
     * @param string $key
     * @param mixed $value
     */
    public function __set($key, $value) {
        if (method_exists($this, 'set_' . $key)) {
            $this->{'set_' . $key}($value);
        }
        $this->properties->{$key} = $value;
    }

    /**
     * Magic get: a get_$key method if there is one, else the raw property.
     *
     * @param string $key
     * @return mixed
     */
    public function __get($key) {
        if (method_exists($this, 'get_' . $key)) {
            return $this->{'get_' . $key}();
        }
        return $this->properties->{$key};
    }

    /**
     * Magic isset, so empty() keeps working with the magic get.
     *
     * @param string $key
     * @return bool
     */
    public function __isset($key) {
        if (method_exists($this, 'get_' . $key)) {
            $val = $this->{'get_' . $key}();
            return !empty($val);
        }
        return !empty($this->properties->{$key});
    }

    /**
     * Every property.
     *
     * @return \stdClass
     */
    public function properties() {
        return $this->properties;
    }
}
