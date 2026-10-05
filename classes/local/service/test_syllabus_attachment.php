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

/**
 * Reads the known test syllabus only when the exported test page links to it.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class test_syllabus_attachment {
    /** @var int Test page course-module id. */
    private const PAGE_CMID = 11342;

    /** @var int Test syllabus resource course-module id. */
    private const RESOURCE_CMID = 11340;

    /**
     * Return the stored PDF referenced by the test page, without exposing a URL or mutating Moodle data.
     *
     * @param array $payload Exported template payload.
     * @return \stored_file|null
     */
    public static function referenced_pdf(array $payload): ?\stored_file {
        $page = self::activity($payload, self::PAGE_CMID);
        $resource = self::activity($payload, self::RESOURCE_CMID);
        if ($page === null || $resource === null) {
            return null;
        }
        if (($page['resource_type'] ?? '') !== 'page' || ($resource['resource_type'] ?? '') !== 'resource') {
            return null;
        }

        $pagejson = json_encode($page['parameters'] ?? [], JSON_UNESCAPED_SLASHES);
        if (!is_string($pagejson)) {
            return null;
        }

        foreach ($resource['parameters']['files'] ?? [] as $entry) {
            if (!is_array($entry) || !empty($entry['isdir'])) {
                continue;
            }
            if (($entry['mimetype'] ?? '') !== 'application/pdf' || empty($entry['url'])) {
                continue;
            }
            if (!str_contains($pagejson, (string) $entry['url'])) {
                continue;
            }
            $file = self::stored_file($entry);
            if ($file !== null) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Find one exported activity by its source cmid.
     *
     * @param array $payload
     * @param int $cmid
     * @return array|null
     */
    private static function activity(array $payload, int $cmid): ?array {
        foreach ($payload['activities'] ?? [] as $activity) {
            if (!is_array($activity) || (int) ($activity['cmid'] ?? 0) !== $cmid) {
                continue;
            }
            return $activity;
        }
        return null;
    }

    /**
     * Resolve the exported metadata to Moodle's stored file.
     *
     * @param array $entry
     * @return \stored_file|null
     */
    private static function stored_file(array $entry): ?\stored_file {
        $storage = get_file_storage();
        return $storage->get_file(
            (int) ($entry['contextid'] ?? 0),
            (string) ($entry['component'] ?? ''),
            (string) ($entry['filearea'] ?? ''),
            (int) ($entry['itemid'] ?? 0),
            (string) ($entry['filepath'] ?? '/'),
            (string) ($entry['filename'] ?? '')
        ) ?: null;
    }
}
