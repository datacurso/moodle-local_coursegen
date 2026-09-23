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

namespace local_coursegen\local\preview;

/**
 * A ported module's description, formatted the way core's own
 * format_module_intro() formats it. Kept apart from ported_preview.php only
 * because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait ported_module_intro {
    /**
     * The module's description formatted the way format_module_intro() formats it.
     *
     * Copied from lib/weblib.php format_module_intro() (Moodle 4.5), with the
     * context handed in rather than looked up from a course module id.
     *
     * @param stdClass $activity The module's row.
     * @param bool $filter
     * @return string
     */
    protected function module_intro(stdClass $activity, bool $filter = true): string {
        $context = $this->context();
        $options = ['noclean' => true, 'para' => false, 'filter' => $filter, 'context' => $context, 'overflowdiv' => true];
        $intro = file_rewrite_pluginfile_urls(
            $activity->intro,
            'pluginfile.php',
            $context->id,
            'mod_' . $this->modname(),
            'intro',
            null
        );
        return trim(format_text($intro, $activity->introformat, $options, null));
    }
}
