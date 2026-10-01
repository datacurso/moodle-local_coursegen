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

use aiprovider_datacurso\httpclient\ai_course_api;

/**
 * Brings the images the AI service generated into the draft area of an editor field.
 *
 * Images referenced by a local generated path, as HTML or markdown, are
 * downloaded and rewritten to @@PLUGINFILE@@ urls. Image placeholders the
 * service left unresolved are removed so no raw marker shows in the content.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generated_image_localizer {
    /** @var int Draft itemid where the downloaded files are stored. */
    private int $itemid;

    /**
     * Constructor.
     *
     * @param int $itemid Draft itemid of the field's editor.
     */
    public function __construct(int $itemid) {
        $this->itemid = $itemid;
    }

    /**
     * Replace generated-image references with @@PLUGINFILE@@ urls and drop unresolved image placeholders.
     *
     * @param string $text Editor text.
     * @return string
     */
    public function localize(string $text): string {
        if ($text === '') {
            return $text;
        }

        $text = $this->localize_html_images($text);
        $text = $this->localize_markdown_images($text);
        $text = self::replace_or_keep('/^\s*\{\{image:\s*.*?\s*\}\}\s*$/imu', $text);
        return self::replace_or_keep('/\{\{image:\s*.*?\s*\}\}/iu', $text);
    }

    /**
     * Rewrite one HTML <img> tag that references a generated file by local path.
     *
     * Public only because it is the callback of the pattern replacement.
     *
     * @param array $matches Pattern matches; the whole tag is item 0.
     * @return string
     */
    public function localize_html_image(array $matches): string {
        $imgtag = $matches[0];
        if (!preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/iu', $imgtag, $srcmatches)) {
            return $imgtag;
        }

        $rawsource = (string)$srcmatches[2];
        $trimmed = trim($rawsource);
        $source = html_entity_decode($trimmed, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $filename = $this->download_to_draft($source);
        if ($filename === null) {
            return $imgtag;
        }

        $replacement = 'src="@@PLUGINFILE@@/' . $filename . '"';
        $replaced = preg_replace('/\bsrc\s*=\s*(["\']).*?\1/iu', $replacement, $imgtag, 1);
        if (empty($replaced)) {
            return $imgtag;
        }
        return $replaced;
    }

    /**
     * Rewrite one markdown image that references a generated file by local path.
     *
     * Public only because it is the callback of the pattern replacement.
     *
     * @param array $matches Pattern matches; the whole image is item 0, alt is 1 and source is 2.
     * @return string
     */
    public function localize_markdown_image(array $matches): string {
        $alt = trim((string)$matches[1]);
        $source = trim((string)$matches[2], '<>');

        $filename = $this->download_to_draft($source);
        if ($filename === null) {
            return $matches[0];
        }

        $escapedalt = htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<img src="@@PLUGINFILE@@/' . $filename
            . '" alt="' . $escapedalt . '" style="max-width:100%;height:auto;" />';
    }

    /**
     * Replace HTML <img> tags that reference generated files by local path.
     *
     * @param string $text Editor text.
     * @return string
     */
    private function localize_html_images(string $text): string {
        $callback = [$this, 'localize_html_image'];
        $result = preg_replace_callback('/<img\b[^>]*>/iu', $callback, $text);
        if ($result === null) {
            return $text;
        }
        return $result;
    }

    /**
     * Replace markdown images with HTML tags that point to @@PLUGINFILE@@ files.
     *
     * @param string $text Editor text.
     * @return string
     */
    private function localize_markdown_images(string $text): string {
        $callback = [$this, 'localize_markdown_image'];
        $result = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/u', $callback, $text);
        if ($result === null) {
            return $text;
        }
        return $result;
    }

    /**
     * Remove what a pattern matches, keeping the text if the pattern could not run.
     *
     * @param string $pattern Pattern of the text to remove.
     * @param string $text Editor text.
     * @return string
     */
    private static function replace_or_keep(string $pattern, string $text): string {
        $result = preg_replace($pattern, '', $text);
        if ($result === null) {
            return $text;
        }
        return $result;
    }

    /**
     * Download a generated image to the current draft item area.
     *
     * @param string $source Source path from the AI payload.
     * @return string|null Stored filename when available.
     */
    private function download_to_draft(string $source): ?string {
        static $downloadcache = [];

        $source = trim($source);
        if ($source === '' || !self::is_local_generated_image_source($source)) {
            return null;
        }

        $cachekey = $this->itemid . '|' . $source;
        if (array_key_exists($cachekey, $downloadcache)) {
            return $downloadcache[$cachekey];
        }

        $downloadcache[$cachekey] = $this->fetch_to_draft($source);
        return $downloadcache[$cachekey];
    }

    /**
     * Ask the AI service for a generated file and store it in the draft area.
     *
     * @param string $source Local generated image path.
     * @return string|null Stored filename, or null when it could not be fetched.
     */
    private function fetch_to_draft(string $source): ?string {
        $client = self::get_ai_course_client();
        if ($client === null) {
            return null;
        }

        $filename = self::extract_filename_from_source($source);
        $encodedsource = urlencode($source);
        $endpoint = '/files/download?path=' . $encodedsource;

        try {
            $file = $client->download_file($endpoint, $filename, ['itemid' => $this->itemid]);
            if (!$file) {
                return null;
            }
            return $file->get_filename();
        } catch (\Throwable $exception) {
            debugging('Could not download generated image: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Return whether the source refers to a local generated image path.
     *
     * @param string $source Source path candidate.
     * @return bool
     */
    private static function is_local_generated_image_source(string $source): bool {
        if ($source === '' || str_starts_with($source, '@@PLUGINFILE@@/')) {
            return false;
        }

        $lower = \core_text::strtolower($source);
        if (str_starts_with($lower, 'http://') || str_starts_with($lower, 'https://') || str_starts_with($lower, 'data:')) {
            return false;
        }

        // Typical generated image paths are absolute filesystem paths under
        // resource files. Only a strict character class is accepted and no
        // path segment may climb (".."): the path is forwarded to the
        // service's download endpoint verbatim.
        if (preg_match('#(^|/)\.\.(/|$)#', $source)) {
            return false;
        }
        if (preg_match('#^(/[A-Za-z0-9._-]+)*/generated_images/[A-Za-z0-9._/-]+$#', $source)) {
            return true;
        }

        $isknownroot = preg_match('#^/(tmp|var|home|data)/[A-Za-z0-9._/-]+$#', $source);
        return $isknownroot === 1;
    }

    /**
     * Build a safe filename from an image source path.
     *
     * @param string $source Source path.
     * @return string
     */
    private static function extract_filename_from_source(string $source): string {
        $path = parse_url($source, PHP_URL_PATH);
        if (empty($path)) {
            $path = $source;
        }
        $basename = basename((string)$path);
        $filename = clean_param($basename, PARAM_FILE);
        if ($filename === '' || $filename === '.') {
            $now = time();
            $filename = 'generated-image-' . $now . '.png';
        }
        if (!preg_match('/\.[a-z0-9]{2,5}$/i', $filename)) {
            $filename .= '.png';
        }
        return $filename;
    }

    /**
     * Build an AI client instance used to fetch generated files.
     *
     * @return ai_course_api|null
     */
    private static function get_ai_course_client(): ?ai_course_api {
        static $client = null;
        static $initialized = false;

        if ($initialized) {
            return $client;
        }

        $initialized = true;
        $baseurl = get_config('local_coursegen', 'datacurso_service_url');
        $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu');
        if (empty($baseurl)) {
            $baseurl = null;
        }
        if (empty($baseurleu)) {
            $baseurleu = null;
        }

        try {
            $client = new ai_course_api(null, $baseurl, $baseurleu);
        } catch (\Throwable $exception) {
            debugging('Could not initialize AI file client: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            $client = null;
        }

        return $client;
    }
}
