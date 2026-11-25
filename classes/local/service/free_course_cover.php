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

namespace local_coursegen\local\service;

use local_coursegen\local\api_client_factory;

/**
 * Puts the cover the AI service made for a freely generated course in the course image.
 *
 * The service adds a course_image entry to the result when images were requested and the cover was made.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class free_course_cover {
    /**
     * Apply the cover of the result, if it has one.
     *
     * A cover is decoration: one that cannot be downloaded leaves the course without it and never stops the course.
     *
     * @param int $courseid The new course.
     * @param array $resultdata The generation result.
     * @param callable|null $downloader Replaces the download from the AI service; tests pass their own.
     *                                  Called with (endpoint, filename) and returns a stored_file or null.
     * @return bool Whether a cover was applied.
     */
    public static function apply(int $courseid, array $resultdata, ?callable $downloader = null): bool {
        $image = $resultdata['course_image'] ?? null;
        if (!is_array($image)) {
            return false;
        }
        $filepath = trim((string) ($image['file_path'] ?? ''));
        $filename = clean_param(basename((string) ($image['file_name'] ?? '')), PARAM_FILE);
        if ($filepath === '' || $filename === '') {
            return false;
        }

        $downloader = $downloader ?? [self::class, 'download'];
        try {
            $downloaded = $downloader('/files/download?path=' . rawurlencode($filepath), $filename);
            if (!$downloaded instanceof \stored_file) {
                return false;
            }
            $context = \context_course::instance($courseid);
            $fs = get_file_storage();
            $fs->delete_area_files($context->id, 'course', 'overviewfiles');
            $fs->create_file_from_storedfile([
                'contextid' => $context->id,
                'component' => 'course',
                'filearea' => 'overviewfiles',
                'itemid' => 0,
                'filepath' => '/',
                'filename' => $filename,
            ], $downloaded);
            $downloaded->delete();
            rebuild_course_cache($courseid, true);
            return true;
        } catch (\Throwable $exception) {
            debugging('local_coursegen: the course cover could not be applied: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Download a generated file from the AI service into a draft area of the current user.
     *
     * @param string $endpoint Endpoint of the file in the service.
     * @param string $filename Name the file gets.
     * @return \stored_file|null
     */
    private static function download(string $endpoint, string $filename): ?\stored_file {
        $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
        $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;
        return api_client_factory::ai_course_api($baseurl, $baseurleu)->download_file($endpoint, $filename);
    }
}
