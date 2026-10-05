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

namespace local_coursegen\local\models;

use core\persistent;

/**
 * Persistent model for table local_coursegen_tpl_space.
 *
 * A virtual space: it has no real course module of its own and never appears
 * in the base course. It marks a place in a section where the professor must
 * (or may) provide an activity of the given type when creating a course from
 * the template, with an instruction saying what to provide.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_space extends persistent {
    /** Table name for the persistent. */
    const TABLE = 'local_coursegen_tpl_space';

    /**
     * Return the definition of the properties of this model.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            // This space's own name: a space has no course module, so nothing
            // else can serve as its id.
            'uid' => [
                'type' => PARAM_ALPHANUMEXT,
                'null' => NULL_NOT_ALLOWED,
                'default' => '',
            ],
            'templateid' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
            ],
            'sectionid' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
            ],
            // The module type of the activity the professor provides.
            'modname' => [
                'type' => PARAM_PLUGIN,
                'null' => NULL_NOT_ALLOWED,
            ],
            // 1: the professor must provide it; 0: it is optional.
            'required' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 1,
            ],
            'instruction' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            // The real cmid this space renders immediately after within its
            // section; 0 means "the start of the section".
            'aftercmid' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 0,
            ],
            // Tiebreaker order among virtual rows sharing the same aftercmid.
            'sortorder' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'default' => 0,
            ],
        ];
    }
}
