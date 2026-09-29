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

use aiprovider_datacurso\httpclient\ai_course_api;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Class folder_parameters
 *
 * Downloads the AI-generated documents into a single draft file area — each at its planned
 * subfolder path — and points the folder's 'files' parameter at it, so the standard module
 * creation places every file (and its nested folders) into the folder's content filearea.
 * Mirrors resource_parameters, extended from one file to many with a folder tree. When no files
 * were produced the parameters are returned unchanged (the folder is simply created empty).
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class folder_parameters extends base_parameters {
    /**
     * Download the generated files into a draft area and set it as the folder's files.
     *
     * @return object Adjusted parameters for the folder module.
     */
    public function get_parameters() {
        global $USER;

        $modsettings = $this->parameters->mod_settings ?? [];
        $files = is_array($modsettings) ? ($modsettings['files'] ?? []) : [];
        if (empty($files) || !is_array($files)) {
            return $this->parameters;
        }

        $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
        $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;
        $client = new ai_course_api(null, $baseurl, $baseurleu);

        $draftid = file_get_unused_draft_itemid();
        $fs = get_file_storage();
        $context = \context_user::instance($USER->id);

        foreach ($files as $file) {
            if (!is_array($file) || empty($file['file_path']) || empty($file['file_name'])) {
                continue;
            }
            $filepath = self::normalize_filepath($file['folder_path'] ?? '');
            // A single bad file must not abort the whole folder (this runs before creation).
            try {
                if ($filepath !== '/') {
                    $fs->create_directory($context->id, 'user', 'draft', $draftid, $filepath);
                }
                $endpoint = '/files/download?path=' . $file['file_path'];
                $client->download_file($endpoint, $file['file_name'], [
                    'itemid' => $draftid,
                    'filepath' => $filepath,
                ]);
            } catch (\Throwable $e) {
                debugging(
                    'local_coursegen: skipped folder file "' . $file['file_name'] . '": ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }

        $this->parameters->files = $draftid;
        return $this->parameters;
    }

    /**
     * Normalise an AI ``folder_path`` to a Moodle filearea filepath.
     *
     * '' -> '/', 'Anexos' -> '/Anexos/', 'Datos/Tablas' -> '/Datos/Tablas/'. Each segment is
     * cleaned to a safe path component; empty segments are dropped.
     *
     * @param string $folderpath The AI-provided subfolder path.
     * @return string A Moodle filepath beginning and ending with '/'.
     */
    public static function normalize_filepath(string $folderpath): string {
        $segments = [];
        foreach (explode('/', trim($folderpath)) as $segment) {
            $segment = trim(clean_param($segment, PARAM_FILE));
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }
        if (empty($segments)) {
            return '/';
        }
        return '/' . implode('/', $segments) . '/';
    }
}
