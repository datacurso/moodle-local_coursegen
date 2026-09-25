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

namespace local_coursegen\mod_parameters;

use local_coursegen\local\api_client_factory;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Class h5pactivity_parameters
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class h5pactivity_parameters extends base_parameters {
    /** @var string The mold key that names the activity the package is rebuilt from. */
    private const MOLD_SOURCE_KEY = 'mold_source_cmid';

    /**
     * Returns the adjusted parameters for the module h5pactivity.
     *
     * Two package flows end here, and mod_settings says which one:
     *
     * - A MOLD carries mold_source_cmid plus the two filled texts and no
     *   file_path/file_name. Its package is rebuilt from the mold's own .h5p.
     * - Everything else is the model-driven flow, whose package the service
     *   writes and this plugin downloads. That path is untouched.
     *
     * @return object Adjusted parameters for the module h5pactivity.
     */
    public function get_parameters() {
        $modsettings = (array) ($this->parameters->mod_settings ?? []);
        if (array_key_exists(self::MOLD_SOURCE_KEY, $modsettings)) {
            $file = $this->rebuild_mold_package($modsettings);
            $this->validate_package($file, $file->get_filename());
            $this->parameters->packagefile = $file->get_itemid();
            return $this->parameters;
        }

        $downloadinfo = $this->get_package_download_info();
        $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
        $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;

        $client = api_client_factory::ai_course_api($baseurl, $baseurleu);
        $file = $client->download_file($downloadinfo['endpoint'], $downloadinfo['filename']);
        $this->validate_package($file, $downloadinfo['filename']);
        $this->parameters->packagefile = $file->get_itemid();
        return $this->parameters;
    }

    /**
     * Rebuild the mold's package with the two filled texts in place.
     *
     * An H5P mold cannot be reproduced the way scorm/imscp/resource molds are.
     * Its content is content/content.json INSIDE its own .h5p, next to the
     * library folders, the images and the coordinates those images are
     * calibrated against. So the service only fills the marked strings of the
     * two text entries, and the package the generated activity gets is a copy
     * of the MOLD's package with exactly those two entries replaced. The
     * library versions, the images and every coordinate therefore survive byte
     * for byte, and the generated activity runs on the mold's own libraries -
     * which is also what removes the site-H5P-version compatibility problem.
     *
     * The mold's .h5p is extracted and re-archived from the full entry map
     * because zip_packer::archive_to_pathname() does not recurse into a
     * directory value (the precedent is core's code_manager::zip_plugin_folder()).
     * The two rewritten entries go in as inline content arrays, which is the
     * third source shape the packer accepts, so they need no temp file of their
     * own. Directory entries are not re-added: a .h5p written by the H5P editor
     * carries none, and inventing them would change the archive the mold is.
     *
     * @param array $modsettings The generated activity's mod_settings.
     * @return \stored_file The rebuilt package, in the current user's draft area.
     * @throws \moodle_exception When the payload, the mold or the rebuild is not usable.
     */
    private function rebuild_mold_package(array $modsettings): \stored_file {
        global $USER;

        $contentjson = $modsettings['content_json'] ?? null;
        $h5pjson = $modsettings['h5p_json'] ?? null;
        if (
            !is_string($contentjson) || trim($contentjson) === ''
            || !is_string($h5pjson) || trim($h5pjson) === ''
        ) {
            throw new \moodle_exception('error_missing_mold_package_info', 'local_coursegen');
        }

        $moldfile = $this->get_mold_package_file((int) ($modsettings[self::MOLD_SOURCE_KEY] ?? 0));
        $filename = $moldfile->get_filename();

        $workdir = make_request_directory();
        $extracted = $workdir . '/mold';
        $packer = get_file_packer('application/zip');
        if ($packer->extract_to_pathname($moldfile, $extracted) === false) {
            throw new \moodle_exception('error_invalid_package', 'local_coursegen', '', $filename);
        }

        $entries = self::package_entries($extracted);
        $entries['content/content.json'] = [$contentjson];
        $entries['h5p.json'] = [$h5pjson];

        $rebuiltpath = $workdir . '/rebuilt.h5p';
        if ($packer->archive_to_pathname($entries, $rebuiltpath, false) === false) {
            throw new \moodle_exception('error_invalid_package', 'local_coursegen', '', $filename);
        }

        // The archive_to_storage() helper would hardcode the mimetype to application/zip,
        // while Moodle's own type table maps .h5p to application/zip.h5p; going
        // through a path costs nothing and lets the canonical type be set.
        return get_file_storage()->create_file_from_pathname([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => file_get_unused_draft_itemid(),
            'filepath' => '/',
            'filename' => $filename,
            'mimetype' => 'application/zip.h5p',
        ], $rebuiltpath);
    }

    /**
     * The package file of the mold the service asked to rebuild from.
     *
     * mold_source_cmid comes back from an external service, so it is untrusted
     * input: it is resolved as an h5pactivity course module and the current
     * user must hold the capability mod/h5pactivity/view.php requires to see
     * that module's content, in the module's OWN context. Without that check a
     * generated activity could be made to carry the package of any H5P activity
     * on the site. A cmid that resolves to nothing, or to another module type,
     * is refused outright rather than falling back to some other package.
     *
     * @param int $cmid The mold's course module id, as the service reported it.
     * @return \stored_file
     * @throws \moodle_exception When the module does not exist, is not an H5P
     *     activity, or carries no package.
     * @throws \required_capability_exception When the user cannot see that module.
     */
    private function get_mold_package_file(int $cmid): \stored_file {
        // IGNORE_MISSING rather than MUST_EXIST: the resolution has to fail on
        // a user-facing plugin string, not on the dml_missing_record_exception
        // MUST_EXIST raises, which would show the user a database table name.
        // The refusal is the same either way - the module type is part of the
        // lookup, so another type's cmid resolves to nothing here.
        $cm = get_coursemodule_from_id('h5pactivity', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            throw new \moodle_exception('error_mold_source_not_found', 'local_coursegen', '', $cmid);
        }

        $context = \context_module::instance($cm->id);
        require_capability('mod/h5pactivity:view', $context);

        $files = get_file_storage()->get_area_files(
            $context->id,
            'mod_h5pactivity',
            'package',
            0,
            'id',
            false
        );
        $file = reset($files);
        if (!$file) {
            throw new \moodle_exception('error_mold_package_missing', 'local_coursegen', '', $cmid);
        }

        return $file;
    }

    /**
     * Every file of an extracted package, as the entry map the packer reads.
     *
     * @param string $root Full path to the extracted package.
     * @return array<string, string> Archive path => full path on disk.
     */
    private static function package_entries(string $root): array {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $strip = strlen($root) + 1;
        $entries = [];
        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isFile()) {
                continue;
            }
            $path = $fileinfo->getPathname();
            $entries[str_replace(DIRECTORY_SEPARATOR, '/', substr($path, $strip))] = $path;
        }

        return $entries;
    }

    /**
     * Validate the package before it is attached to the activity.
     *
     * The module form validation is bypassed in this flow, so an invalid
     * package would otherwise only fail when a student opens the activity.
     * The check is deliberately cheap: extension, non-empty content and a
     * readable zip containing the h5p.json manifest. No core_h5p deployment
     * is attempted here. Both flows run it: a malformed rebuild has to fail
     * loudly rather than produce a broken activity.
     *
     * @param \stored_file $file Downloaded or rebuilt package file.
     * @param string $filename Clean package file name.
     * @return void
     * @throws \moodle_exception When the package is not a valid .h5p file.
     */
    private function validate_package(\stored_file $file, string $filename): void {
        if (\core_text::strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'h5p') {
            throw new \moodle_exception('error_invalid_package', 'local_coursegen', '', $filename);
        }

        if ((int) $file->get_filesize() === 0) {
            throw new \moodle_exception('error_invalid_package', 'local_coursegen', '', $filename);
        }

        $temppath = $file->copy_content_to_temp();
        try {
            $zip = new \ZipArchive();
            $opened = $zip->open($temppath);
            $hasmanifest = false;
            if ($opened === true) {
                $hasmanifest = $zip->locateName('h5p.json') !== false;
                $zip->close();
            }
            if ($opened !== true || !$hasmanifest) {
                throw new \moodle_exception('error_invalid_package', 'local_coursegen', '', $filename);
            }
        } finally {
            @unlink($temppath);
        }
    }
}
