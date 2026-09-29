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

namespace local_coursegen\mod_export;

/**
 * Class h5pactivity_export
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class h5pactivity_export extends base_export {
    /** @var string[] Every mod_h5pactivity instance setting worth reproducing on the generated activity. */
    private const H5PACTIVITY_SETTINGS_COLUMNS = [
        'displayoptions', 'enabletracking', 'grademethod', 'reviewmode', 'grade',
    ];

    /**
     * An H5P activity's raw description, its settings and its own package text.
     *
     * This type breaks the convention every other file-bearing one follows. A
     * scorm/imscp/resource mold only describes the document it wants BUILT, and
     * the service writes a new package the plugin then downloads. An H5P mold
     * cannot work that way: its content is content/content.json inside its own
     * .h5p, which also carries the library folders, the images and every
     * coordinate those images are calibrated against. So only the TEXT travels
     * - the two raw entries under moldh5p - and the plugin rebuilds the package
     * out of the MOLD's own .h5p, replacing just those two entries
     * (see \local_coursegen\mod_parameters\h5pactivity_parameters). The library
     * versions survive byte for byte, which is also what keeps the generated
     * activity compatible with whatever H5P version the site runs.
     *
     * Two traps of mod_h5pactivity's schema shape this branch:
     *
     * - gradepass is NOT an h5pactivity column. As in mod_quiz it lives in
     *   grade_items, so it travels through the grades API.
     * - displayoptions is a PACKED int whose bits are inverted (set = disabled)
     *   and which the site can partly force. add_moduleinfo() consumes it as
     *   such - unlike the quiz review bitmasks - so it is copied verbatim
     *   rather than decoded into checkboxes that would not round trip.
     *
     * There is deliberately no maxattempts: mod_h5pactivity owns no such
     * column. Its "attempt options" fieldset is enabletracking, grademethod
     * and reviewmode, and nothing else.
     *
     * @return array
     */
    public function parameters(): array {
        global $DB;

        $h5pactivity = $DB->get_record('h5pactivity', ['id' => $this->cm->instance]);
        if (!$h5pactivity) {
            return $this->minimal_parameters();
        }

        return array_merge(
            $this->settings_columns($h5pactivity),
            [
                'name' => $this->cm->name,
                'section' => (int) $this->cm->sectionnum,
                'intro' => $h5pactivity->intro ?? '',
                'gradepass' => $this->grade_pass(),
                // Always reported, null included: an absent key and a package
                // this activity does not have mean different things to the
                // service, and only one of them is true here.
                'moldh5p' => $this->mold_package(),
            ]
        );
    }

    /**
     * The mod_h5pactivity settings worth reproducing on the generated activity.
     *
     * Identity columns (id, course, name, timecreated, timemodified,
     * introformat) are left out on purpose - they describe THIS activity,
     * never the new one.
     *
     * @param \stdClass $h5pactivity
     * @return array
     */
    private function settings_columns($h5pactivity): array {
        return $this->whitelisted_settings($h5pactivity, self::H5PACTIVITY_SETTINGS_COLUMNS);
    }

    /**
     * The two text entries of the mold's own package, plus its identity.
     *
     * Both texts travel RAW: they are the marker-bearing strings the service
     * fills in, and they are also what the rebuild writes back into a copy of
     * this very package - so any re-encoding here would come back as a
     * different file. The cmid is the plugin's own, and the rebuild re-resolves
     * and re-authorises it: the service is never trusted with a file handle.
     *
     * A package that cannot be opened, or that is missing either entry, is not
     * a mold this design can reproduce; it reports no mold rather than throwing
     * and losing the whole template export over one broken activity.
     *
     * @return array|null
     */
    private function mold_package(): ?array {
        $file = $this->mold_package_file('mod_h5pactivity');
        if ($file === null) {
            return null;
        }

        $entries = $this->package_entries($file, ['h5p.json', 'content/content.json']);
        if ($entries === null) {
            return null;
        }

        $h5pjson = $entries['h5p.json'];
        $contentjson = $entries['content/content.json'];
        if ($h5pjson === false || $contentjson === false) {
            return null;
        }

        return array_merge(
            [
                'cmid' => (int) $this->cm->id,
                'filename' => $file->get_filename(),
                'h5pjson' => $h5pjson,
                'contentjson' => $contentjson,
            ],
            $this->main_library($h5pjson)
        );
    }

    /**
     * The manifest's main library, with the version the package really carries.
     *
     * h5p.json names the main library but holds no version of its own for it:
     * the version lives in the preloadedDependencies entry whose machineName
     * matches mainLibrary, which is where core_h5p reads it from too.
     *
     * @param string $h5pjson The raw h5p.json text.
     * @return array<string, mixed> mainlibrary, majorversion and minorversion.
     */
    private function main_library(string $h5pjson): array {
        $manifest = json_decode($h5pjson, true);
        $mainlibrary = is_array($manifest) ? (string) ($manifest['mainLibrary'] ?? '') : '';

        $major = 0;
        $minor = 0;
        $dependencies = (is_array($manifest) ? $manifest['preloadedDependencies'] ?? [] : []);
        foreach ((array) $dependencies as $dependency) {
            if ($mainlibrary === '' || !is_array($dependency)) {
                continue;
            }
            if ((string) ($dependency['machineName'] ?? '') === $mainlibrary) {
                $major = (int) ($dependency['majorVersion'] ?? 0);
                $minor = (int) ($dependency['minorVersion'] ?? 0);
                break;
            }
        }

        return ['mainlibrary' => $mainlibrary, 'majorversion' => $major, 'minorversion' => $minor];
    }
}
