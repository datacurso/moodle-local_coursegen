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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Shared helper to resolve @@PLUGINFILE@@ tokens in a rich text field against
 * a real images array (each entry a real, HTTP-served file, either resolved
 * from the .mbz test content or from the plugin's own base64 export), by
 * downloading the matched files into a fresh Moodle draft file area.
 *
 * This generalizes the mechanism lesson_settings::attach_generated_images()
 * already uses for the mbz test lesson's pages, so any activity type/field
 * can reuse the same "pre-populate a draft area, let the real Moodle create
 * flow move it into the final component/filearea/itemid" pattern.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generated_image_attacher {
    /**
     * Download every real image referenced by an @@PLUGINFILE@@ token in
     * $text into a new draft file area, so that whatever Moodle API later
     * moves that draft area into its real component/filearea/itemid (e.g.
     * add_moduleinfo()'s intro flattening, lesson_page::create(),
     * forum_add_discussion()'s inlineattachmentsid) resolves the token for
     * real.
     *
     * The caller MUST pass an already itemid-scoped image list (only images
     * belonging to the exact field/page/post being processed here), never a
     * whole-activity merged list: this method (and find_image_by_filename())
     * matches by filename only and has no itemid disambiguation of its own,
     * so a merged list risks resolving the wrong real file when two
     * itemid-scoped fields happen to share a filename.
     *
     * @param string $text Rich text field value to scan for @@PLUGINFILE@@ tokens.
     * @param array $images Real images available for this activity, each
     *     entry shaped {original_filename, url, ...} (mimetype/filename/id
     *     may also be present but are not required here).
     * @return int Draft itemid with the matched files, or 0 when $text has
     *     no token that matches any entry in $images.
     */
    public static function resolve_draft_itemid(string $text, array $images): int {
        if ($text === '' || empty($images)) {
            return 0;
        }
        if (!preg_match_all('/@@PLUGINFILE@@\/([^"\'\s]+)/', $text, $matches)) {
            return 0;
        }

        global $USER;
        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        $draftid = 0;

        foreach (array_unique($matches[1]) as $rawfilename) {
            $filename = rawurldecode($rawfilename);
            $image = self::find_image_by_filename($images, $filename);
            if ($image === null) {
                continue;
            }

            if ($draftid === 0) {
                $draftid = file_get_unused_draft_itemid();
            }

            try {
                $fs->create_file_from_url([
                    'contextid' => $usercontext->id,
                    'component' => 'user',
                    'filearea' => 'draft',
                    'itemid' => $draftid,
                    'filepath' => '/',
                    'filename' => $filename,
                ], $image['url'], null, true);
            } catch (\Throwable $exception) {
                debugging(
                    'local_coursegen: could not download generated image "' . $filename . '": '
                    . $exception->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }

        return $draftid;
    }

    /**
     * Find the real image entry matching an @@PLUGINFILE@@ filename.
     *
     * The caller MUST pass an already itemid-scoped image list (only images
     * belonging to the exact field/page/post being processed), never a
     * whole-activity merged list, because this lookup is filename-only and
     * has no itemid disambiguation of its own.
     *
     * @param array $images Real images available for this activity.
     * @param string $filename Filename referenced by the @@PLUGINFILE@@ token.
     * @return array|null
     */
    public static function find_image_by_filename(array $images, string $filename): ?array {
        foreach ($images as $image) {
            $entry = is_array($image) ? $image : (array)$image;
            if (($entry['original_filename'] ?? null) === $filename) {
                return $entry;
            }
        }
        return null;
    }
}
