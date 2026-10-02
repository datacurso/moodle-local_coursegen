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

namespace local_coursegen\local\files;

use aiprovider_datacurso\httpclient\ai_course_api;

/**
 * The images the AI service made, named by the path it holds them at.
 *
 * Each one is fetched from the service once and kept in the plugin's own file
 * area, so the activity that uses it receives a copy like any other file.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class generated_image_source implements file_source {
    /** @var string The file area the fetched images are kept in, under the system context. */
    public const FILEAREA = 'generatedimages';

    /** @var callable|null Fetches one image: (path, filerecord) => stored_file|null. */
    private $downloader;

    /**
     * Constructor.
     *
     * @param callable|null $downloader Replaces the request to the AI service; tests pass their own.
     */
    public function __construct(?callable $downloader = null) {
        $this->downloader = $downloader;
    }

    /**
     * Whether a path is one of the AI service's images, as opposed to an address or a path of this site.
     *
     * Only a strict set of characters is accepted and no segment may climb
     * (".."): the path is forwarded to the service's download endpoint verbatim.
     *
     * @param string $path
     * @return bool
     */
    public static function is_image_path(string $path): bool {
        $path = trim($path);
        if ($path === '' || str_starts_with($path, '@@PLUGINFILE@@/')) {
            return false;
        }
        $lower = \core_text::strtolower($path);
        if (str_starts_with($lower, 'http://') || str_starts_with($lower, 'https://') || str_starts_with($lower, 'data:')) {
            return false;
        }
        if (preg_match('#(^|/)\.\.(/|$)#', $path)) {
            return false;
        }
        if (preg_match('#^(/[A-Za-z0-9._-]+)*/generated_images/[A-Za-z0-9._/-]+$#', $path)) {
            return true;
        }
        return (bool) preg_match('#^/(tmp|var|home|data)/[A-Za-z0-9._/-]+$#', $path);
    }

    #[\Override]
    public function find(file_reference $reference): ?\stored_file {
        if ($reference->kind !== file_reference::KIND_IMAGE_PATH) {
            return null;
        }
        $record = $this->file_record($reference->value);
        $fs = get_file_storage();
        $stored = $fs->get_file(
            $record['contextid'],
            $record['component'],
            $record['filearea'],
            $record['itemid'],
            $record['filepath'],
            $record['filename']
        );
        if ($stored) {
            return $stored;
        }
        $downloader = $this->downloader;
        if ($downloader === null) {
            $downloader = [self::class, 'download_from_service'];
        }
        return $downloader($reference->value, $record);
    }

    /**
     * Fetch an image from the AI service into the given file record.
     *
     * @param string $path
     * @param array $record
     * @return \stored_file|null
     */
    public static function download_from_service(string $path, array $record): ?\stored_file {
        $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
        $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;
        $client = new ai_course_api(null, $baseurl, $baseurleu);
        $endpoint = '/files/download?path=' . urlencode($path);
        return $client->download_file($endpoint, $record['filename'], $record);
    }

    /**
     * Where an image is kept once fetched.
     *
     * @param string $path
     * @return array
     */
    private function file_record(string $path): array {
        $pathhash = sha1($path);
        return [
            'contextid' => \context_system::instance()->id,
            'component' => 'local_coursegen',
            'filearea' => self::FILEAREA,
            'itemid' => 0,
            'filepath' => '/' . $pathhash . '/',
            'filename' => $this->filename_of($path),
        ];
    }

    /**
     * A safe file name for an image path.
     *
     * @param string $path
     * @return string
     */
    private function filename_of(string $path): string {
        $urlpath = parse_url($path, PHP_URL_PATH);
        if (!$urlpath) {
            $urlpath = $path;
        }
        $base = basename((string) $urlpath);
        $filename = clean_param($base, PARAM_FILE);
        if ($filename === '' || $filename === '.') {
            $filename = 'generated-image-' . sha1($path) . '.png';
        }
        if (!preg_match('/\.[a-z0-9]{2,5}$/i', $filename)) {
            $filename .= '.png';
        }
        return $filename;
    }
}
