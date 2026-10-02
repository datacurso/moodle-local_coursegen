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

namespace local_coursegen\utils;

use context;
use local_coursegen\local\reference\reference_file_storage;
use stored_file;

/**
 * Finds the files a mold's texts point at, and the markers the AI service left unresolved.
 *
 * A mold exporter rewrites @@PLUGINFILE@@ placeholders to absolute
 * pluginfile.php URLs of the base course's files so the AI service can hand
 * the text back intact. This resolves such an address to its stored_file and
 * says whether the current user may copy it; giving the file to the new
 * activity is the work of activity_file_pass.
 *
 * Only files of a course or module the current user can manage activities in
 * are copied: the template's base course when the flow knows it, otherwise any
 * course the current user can access. Every other pluginfile URL is refused.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mold_file_copier {
    /**
     * Locate the stored_file an absolute pluginfile URL of this site refers to.
     *
     * The path after pluginfile.php is /<contextid>/<component>/<filearea>/
     * [<itemid>/]<filepath><filename>; whether an item id is present depends
     * on the file area, so both readings are tried against the file storage.
     *
     * @param string $url
     * @return stored_file|null
     */
    public static function resolve_url(string $url): ?stored_file {
        global $CFG;

        $prefix = $CFG->wwwroot . '/pluginfile.php/';
        if (!str_starts_with($url, $prefix)) {
            return null;
        }
        $path = (string) parse_url(substr($url, strlen($prefix) - 1), PHP_URL_PATH);
        $segments = array_map('rawurldecode', explode('/', trim($path, '/')));
        if (count($segments) < 4) {
            return null;
        }

        $contextid = (int) array_shift($segments);
        $component = clean_param(array_shift($segments), PARAM_COMPONENT);
        $filearea = clean_param(array_shift($segments), PARAM_AREA);
        if ($contextid <= 0 || $component === '' || $filearea === '') {
            return null;
        }
        $filename = array_pop($segments);
        if ($filename === '' || $filename === null) {
            return null;
        }

        $fs = get_file_storage();
        // With an item id.
        if (!empty($segments) && ctype_digit($segments[0])) {
            $itemid = (int) $segments[0];
            $filepath = '/' . implode('/', array_slice($segments, 1));
            $filepath = rtrim($filepath, '/') . '/';
            $file = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);
            if ($file) {
                return $file;
            }
        }
        // Without an item id (intro-like areas).
        $filepath = '/' . implode('/', $segments);
        $filepath = rtrim($filepath, '/') . '/';
        $file = $fs->get_file($contextid, $component, $filearea, 0, $filepath, $filename);
        return $file ?: null;
    }

    /**
     * Whether the current user may copy this file.
     *
     * A file the current user brought is theirs to copy. Any other must sit in
     * a course or module context, in a course the current user can manage
     * activities in; with a source course given, it must additionally be that
     * exact course, so a template flow can never be pointed at a different
     * course's files by a crafted URL in the AI service's response.
     *
     * @param stored_file $file
     * @param int|null $sourcecourseid
     * @return bool
     */
    public static function can_copy(stored_file $file, ?int $sourcecourseid = null): bool {
        global $USER;

        if (reference_file_storage::is_session_file_of($file, (int) $USER->id)) {
            return true;
        }
        $context = context::instance_by_id($file->get_contextid(), IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        if ($context->contextlevel !== CONTEXT_COURSE && $context->contextlevel !== CONTEXT_MODULE) {
            return false;
        }
        $coursecontext = $context->get_course_context(false);
        if (!$coursecontext) {
            return false;
        }
        $courseid = (int) $coursecontext->instanceid;

        if ($sourcecourseid !== null && $courseid !== $sourcecourseid) {
            return false;
        }

        return has_capability('moodle/course:manageactivities', $coursecontext);
    }

    /**
     * Remove image markers the AI service left unresolved.
     *
     * Both dialects are removed: the mathematical brackets and their ASCII
     * double-bracket form. The ASCII form only counts when it opens with the
     * image prefix, so ordinary double-bracket text such as a wiki link stays.
     *
     * @param string $text
     * @return string
     */
    public static function strip_image_markers(string $text): string {
        $pattern = '/(?:⟦coursegen:image:[^⟧]*⟧|\[\[coursegen:image:.*?\]\])/su';
        return preg_replace($pattern, '', $text) ?? $text;
    }
}
