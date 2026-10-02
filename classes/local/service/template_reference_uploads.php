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

use stored_file;

/**
 * Sends the AI service the files that reference markers point at, as files.
 *
 * Only the files the service has to look at are sent: the ones of reference markers whose slot the teacher gave
 * no file for, and whose type the service can make a new file from. The others are decided without the file
 * (the teacher's file is used, or the element is removed). The files travel as multipart uploads between /init and
 * the stream, the same way the syllabus does; the payload carries no bytes.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_reference_uploads {
    /** @var string[] The file types the service makes new files from (an image handler: PNG, JPEG, GIF, WebP). */
    private const UPLOADED_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /**
     * The files to send for a payload: each template source activity's uid with the stored file.
     *
     * Reads every marker, so a marker with no usable target is refused before anything is sent.
     *
     * @param array $payload The init payload.
     * @return array[] Each: uid (string), file (stored_file).
     * @throws \moodle_exception A marker has no usable target, or a file it points at is not stored.
     */
    public static function plan(array $payload): array {
        $teacher = array_map('strval', (array) ($payload['reference_files'] ?? []));
        $plan = [];
        foreach ($payload['activities'] ?? [] as $activity) {
            $action = $activity['template_behavior']['action'] ?? '';
            if ($action !== 'template') {
                continue;
            }
            array_push($plan, ...self::plan_for($activity, $teacher));
        }
        return $plan;
    }

    /**
     * Send the planned files to a session.
     *
     * @param template_ai_api_service $api
     * @param string $threadid
     * @param array[] $plan What plan() returned.
     * @throws \moodle_exception A file could not be sent.
     */
    public static function send(template_ai_api_service $api, string $threadid, array $plan): void {
        foreach ($plan as $item) {
            try {
                $api->upload_template_reference_file($threadid, $item['uid'], $item['file']);
            } catch (\Exception $exception) {
                throw new \moodle_exception(
                    'template_reference_upload_failed',
                    'local_coursegen',
                    '',
                    $item['file']->get_filename(),
                    $exception->getMessage()
                );
            }
        }
    }

    /**
     * The files to send for one template source activity.
     *
     * @param array $activity The payload's entry of the activity.
     * @param string[] $teacher The slot keys the teacher gave a file for.
     * @return array[]
     */
    private static function plan_for(array $activity, array $teacher): array {
        $uid = (string) ($activity['uid'] ?? '');
        $parameters = (array) ($activity['parameters'] ?? []);
        $name = (string) ($parameters['name'] ?? '');
        $slots = template_reference_scanner::slots($parameters, $uid, $name);

        $files = [];
        foreach ($slots as $slot) {
            $needed = !in_array($slot['key'], $teacher, true) && in_array($slot['mimetype'], self::UPLOADED_TYPES, true);
            if ($needed && !isset($files[$slot['filename']])) {
                $files[$slot['filename']] = ['uid' => $uid, 'file' => self::stored_file($parameters, $slot['filename'])];
            }
        }
        return array_values($files);
    }

    /**
     * The stored file of an activity's export that has this name.
     *
     * @param array $parameters The activity's export parameters.
     * @param string $filename
     * @return stored_file
     * @throws \moodle_exception The export lists no such file, or it is not stored.
     */
    private static function stored_file(array $parameters, string $filename): stored_file {
        $fs = get_file_storage();
        foreach ($parameters['files'] ?? [] as $entry) {
            if (!empty($entry['isdir']) || ($entry['filename'] ?? '') !== $filename) {
                continue;
            }
            $file = $fs->get_file(
                (int) $entry['contextid'],
                (string) $entry['component'],
                (string) $entry['filearea'],
                (int) $entry['itemid'],
                (string) $entry['filepath'],
                $filename
            );
            if ($file) {
                return $file;
            }
        }
        throw new \moodle_exception('template_reference_no_stored_file', 'local_coursegen', '', $filename);
    }
}
