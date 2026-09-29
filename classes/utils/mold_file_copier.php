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
use context_user;
use stored_file;

/**
 * Copies the files a mold's rich text references into an editor draft area.
 *
 * A mold exporter rewrites @@PLUGINFILE@@ placeholders to absolute
 * pluginfile.php URLs of the base course's files so the AI service can hand
 * the text back intact. Before that text reaches add_moduleinfo(), each such
 * URL is resolved to its stored_file, copied into the draft area of the
 * field's editor and rewritten back to @@PLUGINFILE@@/<filename>; the normal
 * draft-to-module save then carries the file into the new module's area.
 *
 * Only files of an allowed course are copied: the template's base course
 * when the flow knows it, otherwise any course the current user can access.
 * Every other pluginfile URL is left untouched.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mold_file_copier {
    /**
     * Copy every referenced pluginfile.php file of this site into the draft area and rewrite its URL.
     *
     * Matches src/href attributes pointing at $CFG->wwwroot/pluginfile.php/...
     * in the given HTML.
     *
     * @param string $text HTML text.
     * @param int $draftitemid Draft area of the field's editor (current user).
     * @param int|null $sourcecourseid The only course whose files may be copied; null
     *     allows any course the current user can access.
     * @return string The text with copied files rewritten to @@PLUGINFILE@@ URLs.
     */
    public static function copy_pluginfile_urls_to_draft(string $text, int $draftitemid, ?int $sourcecourseid = null): string {
        global $CFG;

        if ($text === '' || $draftitemid <= 0 || !str_contains($text, 'pluginfile.php/')) {
            return $text;
        }

        $prefix = preg_quote($CFG->wwwroot . '/pluginfile.php/', '#');
        $pattern = '#\b(src|href)\s*=\s*(["\'])(' . $prefix . '[^"\']+)\2#iu';

        return preg_replace_callback(
            $pattern,
            static function (array $matches) use ($draftitemid, $sourcecourseid): string {
                $url = html_entity_decode($matches[3], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $filename = self::copy_url_to_draft($url, $draftitemid, $sourcecourseid);
                if ($filename === null) {
                    return $matches[0];
                }
                return $matches[1] . '="@@PLUGINFILE@@/' . rawurlencode($filename) . '"';
            },
            $text
        ) ?? $text;
    }

    /**
     * Copy the file one pluginfile URL points at into the draft area.
     *
     * @param string $url Absolute pluginfile.php URL of this site.
     * @param int $draftitemid Draft area of the current user.
     * @param int|null $sourcecourseid Allowed source course, or null for any accessible course.
     * @return string|null The filename inside the draft area, or null when nothing was copied.
     */
    public static function copy_url_to_draft(string $url, int $draftitemid, ?int $sourcecourseid = null): ?string {
        $file = self::resolve_url($url);
        if ($file === null || !self::is_allowed_source($file, $sourcecourseid)) {
            return null;
        }
        return self::copy_to_draft($file, $draftitemid);
    }

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
     * File areas a mold legitimately references, as component => fileareas.
     *
     * Everything else (submissions, attempts, private or user files, ...)
     * is never copied, whatever URL the payload carries.
     *
     * @var array<string,string[]>
     */
    private const ALLOWED_AREAS = [
        'course' => ['section', 'summary'],
        'mod_page' => ['content'],
        'mod_lesson' => ['page_contents'],
        'mod_glossary' => ['entry'],
        'mod_assign' => ['introattachment', 'activityattachment'],
        'mod_workshop' => ['instructauthors', 'instructreviewers', 'conclusion'],
        'mod_folder' => ['content'],
        'mod_imscp' => ['content'],
        'mod_feedback' => ['page_after_submit'],
        'mod_book' => ['chapter'],
    ];

    /**
     * Whether a component/filearea pair is a legitimate mold asset area.
     *
     * Every module's intro area qualifies, plus the explicit list above.
     *
     * @param string $component
     * @param string $filearea
     * @return bool
     */
    public static function is_allowed_area(string $component, string $filearea): bool {
        if ($filearea === 'intro' && str_starts_with($component, 'mod_')) {
            return true;
        }
        return in_array($filearea, self::ALLOWED_AREAS[$component] ?? [], true);
    }

    /**
     * Whether the current user may copy this file.
     *
     * The file must sit in an allowed mold area of a course or module
     * context. With a source course given it must be that course; otherwise
     * the current user must be able to manage activities in the file's
     * course (a mere participant cannot lift its files).
     *
     * @param stored_file $file
     * @param int|null $sourcecourseid
     * @return bool
     */
    public static function is_allowed_source(stored_file $file, ?int $sourcecourseid = null): bool {
        if (!self::is_allowed_area($file->get_component(), $file->get_filearea())) {
            return false;
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

        if ($sourcecourseid !== null) {
            return $courseid === $sourcecourseid;
        }

        return has_capability('moodle/course:manageactivities', $coursecontext);
    }

    /**
     * Copy a stored file into the current user's draft area.
     *
     * A file already present under the same name with the same content is
     * reused; a different file with the same name gets a free name instead.
     *
     * @param stored_file $file
     * @param int $draftitemid
     * @return string|null The filename inside the draft area, or null on failure.
     */
    public static function copy_to_draft(stored_file $file, int $draftitemid): ?string {
        global $USER;

        $fs = get_file_storage();
        $usercontext = context_user::instance($USER->id);
        $filename = $file->get_filename();

        $existing = $fs->get_file($usercontext->id, 'user', 'draft', $draftitemid, '/', $filename);
        if ($existing) {
            if ($existing->get_contenthash() === $file->get_contenthash()) {
                return $filename;
            }
            $filename = $fs->get_unused_filename($usercontext->id, 'user', 'draft', $draftitemid, '/', $filename);
        }

        try {
            $fs->create_file_from_storedfile([
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => '/',
                'filename' => $filename,
            ], $file);
        } catch (\Throwable $exception) {
            debugging('local_coursegen: could not copy mold file: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
        return $filename;
    }

    /**
     * Remove image markers the AI service left unresolved.
     *
     * @param string $text
     * @return string
     */
    public static function strip_image_markers(string $text): string {
        return preg_replace('/⟦coursegen:image:[^⟧]*⟧/u', '', $text) ?? $text;
    }
}
