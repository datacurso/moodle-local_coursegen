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

namespace local_coursegen\local\link;

/**
 * The modules whose texts are searched for link tokens.
 *
 * Only the modules whose generators fill their texts under the strict link
 * rule are listed; every other module is not read.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class module_link_texts_registry {
    /** @var array<string,class-string<module_link_texts>> Module name => the class describing its texts. */
    private const MODULES = [
        'page' => page_link_texts::class,
        'lesson' => lesson_link_texts::class,
    ];

    /**
     * The texts of a module, or null when the module is not searched.
     *
     * @param string $modname
     * @return module_link_texts|null
     */
    public static function for_module(string $modname): ?module_link_texts {
        if (!isset(self::MODULES[$modname])) {
            return null;
        }
        $class = self::MODULES[$modname];
        return new $class();
    }
}
