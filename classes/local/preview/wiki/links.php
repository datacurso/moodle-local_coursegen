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

/**
 * The wiki parser's link callback, for a wiki drawn from the payload.
 *
 * mod_wiki's parser takes its link callback as "file:function" and requires
 * that the function exist by name, so the one the preview gives it has to be
 * a function in a file. It hands the link on to the view being drawn.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * mod/wiki/locallib.php wiki_parser_link(), resolved against the payload.
 *
 * @param string|stdClass $link The page title written between brackets, or a page.
 * @param array|null $options The parser's link_callback_args.
 * @return array
 */
function local_coursegen_wiki_preview_link($link, $options = null) {
    return \local_coursegen\local\preview\wiki\view::wiki_parser_link($link, $options);
}
