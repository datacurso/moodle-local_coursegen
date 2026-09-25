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
use local_coursegen\event\package_download_skipped;

/**
 * Class base_parameters
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_parameters {
    /**
     * @var object $parameters Default parameters for the module.
     */
    protected $parameters;

    /** @var ai_course_api|null Provider client shared by every download of this instance. */
    private ?ai_course_api $client = null;

    /** @var bool Whether building the provider client already failed for this instance. */
    private bool $clientfailed = false;

    /**
     * Constructor.
     *
     * @param object $parameters Default parameters for the module.
     */
    public function __construct($parameters) {
        $this->parameters = $parameters;
    }

    /**
     * Returns the adjusted parameters for the module.
     *
     * @return object Adjusted parameters for the module.
     */
    abstract public function get_parameters();

    /**
     * Download one AI generated package into a draft file area, never throwing.
     *
     * A provider failure (no enabled DataCurso instance with a license key, network
     * error, unreachable file...) must not abort module creation: the module is
     * created without its package. The skip is reported through the
     * package_download_skipped event (site logs) and a debugging notice.
     *
     * @param string $endpoint Provider download endpoint (path + query).
     * @param string $filename Target file name in the draft area.
     * @param array $filerecord Optional file record overrides (itemid, filepath...).
     * @return \stored_file|null The stored file, or null when the download was skipped.
     */
    protected function download_package(string $endpoint, string $filename, array $filerecord = []): ?\stored_file {
        try {
            $client = $this->get_client();
            $file = $client->download_file($endpoint, $filename, $filerecord);
            if (!$file) {
                throw new \moodle_exception('error_download_failed', 'local_coursegen');
            }
            return $file;
        } catch (\Throwable $e) {
            $this->report_skipped_package($filename, $e);
            return null;
        }
    }

    /**
     * Build (once) the provider client used to fetch generated files.
     *
     * @return ai_course_api
     * @throws \Throwable When the client cannot be built; the failure is remembered so
     *                    later downloads of the same instance do not retry it.
     */
    private function get_client(): ai_course_api {
        if ($this->client !== null) {
            return $this->client;
        }
        if ($this->clientfailed) {
            throw new \moodle_exception('error_provider_unavailable', 'local_coursegen');
        }
        try {
            $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
            $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;
            $this->client = new ai_course_api(null, $baseurl, $baseurleu);
        } catch (\Throwable $e) {
            $this->clientfailed = true;
            throw $e;
        }
        return $this->client;
    }

    /**
     * Report a skipped package: admin-visible event plus a debugging notice.
     *
     * @param string $filename File name that was not attached.
     * @param \Throwable $e The failure.
     * @return void
     */
    private function report_skipped_package(string $filename, \Throwable $e): void {
        $modname = $this->get_modname();
        $reason = \core_text::substr(trim($e->getMessage()), 0, package_download_skipped::REASON_MAX_LENGTH);

        package_download_skipped::create([
            'context' => $this->get_event_context(),
            'other' => ['modname' => $modname, 'filename' => $filename, 'reason' => $reason],
        ])->trigger();

        debugging(
            "local_coursegen: skipped {$modname} package \"{$filename}\": {$reason}",
            DEBUG_NORMAL
        );
    }

    /**
     * Module name handled by this parameters class (derived from the class name).
     *
     * @return string
     */
    private function get_modname(): string {
        $shortname = substr(strrchr(static::class, '\\'), 1);
        return preg_replace('/_parameters$/', '', $shortname);
    }

    /**
     * Context for skip events: the target course when known, the system otherwise.
     *
     * @return \context
     */
    private function get_event_context(): \context {
        $courseid = (int) ($this->parameters->course ?? 0);
        if ($courseid > 0) {
            try {
                return \context_course::instance($courseid);
            } catch (\Throwable $e) {
                // Fall through to the system context.
                unset($e);
            }
        }
        return \context_system::instance();
    }

    /**
     * Validate and normalize the package download info from mod_settings.
     *
     * Package-type results must carry the remote file_path and file_name in
     * mod_settings. The remote path is URL-encoded as a single query value
     * (mirroring the image download endpoints) and the file name is reduced
     * to a valid Moodle file name.
     *
     * @return array Array with 'endpoint' and 'filename' keys.
     * @throws \moodle_exception When file_path or file_name is missing, or the
     *                           file name cleans down to an empty string.
     */
    protected function get_package_download_info(): array {
        $modsettings = (array) ($this->parameters->mod_settings ?? []);

        $filepath = $modsettings['file_path'] ?? '';
        $filename = $modsettings['file_name'] ?? '';
        if (!is_string($filepath) || trim($filepath) === '' || !is_string($filename) || trim($filename) === '') {
            throw new \moodle_exception('error_missing_package_info', 'local_coursegen');
        }

        $cleanname = clean_param(basename($filename), PARAM_FILE);
        if ($cleanname === '') {
            throw new \moodle_exception('error_invalid_package', 'local_coursegen', '', $filename);
        }

        return [
            'endpoint' => '/files/download?path=' . rawurlencode($filepath),
            'filename' => $cleanname,
        ];
    }
}
