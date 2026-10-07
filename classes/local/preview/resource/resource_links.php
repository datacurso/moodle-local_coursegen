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
     * Where a file of the resource is served from.
     *
     * A file the run attached is served from the draft area of the reviewer, at the address its row carries.
     * A file of the template is served from the module's own file area.
     *
     * @param json_file $file
     * @param int $revision
     * @param bool $forcedownload Whether the address makes the browser download the file.
     * @return string
     */
    protected function resource_file_address($file, $revision, bool $forcedownload): string {
        global $CFG;

        $draftaddress = $file->get_url();
        if ($draftaddress !== null) {
            $parameters = [];
            if ($forcedownload) {
                $parameters['forcedownload'] = 1;
            }
            $address = new \moodle_url($draftaddress, $parameters);
            return $address->out(false);
        }
        $path = '/' . $file->get_contextid() . '/mod_resource/content/' . $revision . $file->get_filepath() . $file->get_filename();
        return file_encode_url($CFG->wwwroot . '/pluginfile.php', $path, $forcedownload);
    }

    /**
     * mod/resource/locallib.php resource_get_clicktoopen().
     *
     * @param json_file $file
     * @param int $revision
     * @param string $onclick Inline JS for a link the real page opens as a popup or new tab.
     * @return string
     */
    protected function resource_get_clicktoopen($file, $revision, $onclick = '') {
        global $OUTPUT;

        $filename = $file->get_filename();
        $fullurl = $this->resource_file_address($file, $revision, false);

        $link = $OUTPUT->render_from_template('local_coursegen/preview_link', [
            'url' => $fullurl,
            'text' => $filename,
            'onclick' => $onclick,
        ]);
        return get_string('clicktoopen2', 'resource', $link);
    }

    /**
     * mod/resource/locallib.php resource_get_clicktodownload().
     *
     * @param json_file $file
     * @param int $revision
     * @return string
     */
    protected function resource_get_clicktodownload($file, $revision) {
        global $OUTPUT;

        $filename = $file->get_filename();
        $fullurl = $this->resource_file_address($file, $revision, true);

        $link = $OUTPUT->render_from_template('local_coursegen/preview_link', [
            'url' => $fullurl,
            'text' => $filename,
        ]);
        return get_string('clicktodownload', 'resource', $link);
    }
}
