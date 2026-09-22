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

namespace local_coursegen\local\preview\resource;

/**
 * The two links mod_resource's workaround page offers - open the file, or
 * download it - kept apart from view.php only because together they crossed
 * the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait resource_links {
    /**
     * mod/resource/locallib.php resource_get_clicktoopen().
     *
     * @param json_file $file
     * @param int $revision
     * @param string $extra
     * @return string
     */
    protected function resource_get_clicktoopen($file, $revision, $extra='') {
        global $CFG;

        $filename = $file->get_filename();
        $path = '/'.$file->get_contextid().'/mod_resource/content/'.$revision.$file->get_filepath().$file->get_filename();
        $fullurl = file_encode_url($CFG->wwwroot.'/pluginfile.php', $path, false);

        $string = get_string('clicktoopen2', 'resource', "<a href=\"$fullurl\" $extra>$filename</a>");

        return $string;
    }

    /**
     * mod/resource/locallib.php resource_get_clicktodownload().
     *
     * @param json_file $file
     * @param int $revision
     * @return string
     */
    protected function resource_get_clicktodownload($file, $revision) {
        global $CFG;

        $filename = $file->get_filename();
        $path = '/'.$file->get_contextid().'/mod_resource/content/'.$revision.$file->get_filepath().$file->get_filename();
        $fullurl = file_encode_url($CFG->wwwroot.'/pluginfile.php', $path, true);

        $string = get_string('clicktodownload', 'resource', "<a href=\"$fullurl\">$filename</a>");

        return $string;
    }
}
