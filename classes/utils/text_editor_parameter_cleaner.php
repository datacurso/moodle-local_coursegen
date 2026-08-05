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

/**
 * Utility class for cleaning text editor parameters from API responses.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\utils;

use aiprovider_datacurso\httpclient\ai_course_api;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Text editor parameter cleaner utility class.
 *
 * This class provides utilities to clean and normalize text editor parameters
 * received from API responses, ensuring they conform to Moodle's expected
 * format and structure for text editor objects.
 */
class text_editor_parameter_cleaner {
    /**
     * Clean text editor objects in activity parameters.
     *
     * This function processes activity parameters and cleans text editor objects
     * by preserving only the 'text' field as received from the API, setting
     * 'format' to 1, and assigning a new unused draft itemid.
     *
     * @param array $parameters Activity parameters to clean
     * @return array Cleaned parameters
     */
    public static function clean_text_editor_objects($parameters) {
        if (!is_array($parameters)) {
            return $parameters;
        }

        foreach ($parameters as $key => $value) {
            if (is_array($value)) {
                // Check if this is a text editor object (has 'text' key).
                if (self::is_text_editor_object($value)) {
                    $parameters[$key] = self::normalize_text_editor_object($value);
                } else if (self::is_array_of_text_editor_objects($value)) {
                    // Handle arrays of text editor objects (like feedbacktext).
                    $parameters[$key] = self::clean_text_editor_array($value);
                } else {
                    // Recursively clean nested arrays.
                    $parameters[$key] = self::clean_text_editor_objects($value);
                }
            }
        }

        return $parameters;
    }

    /**
     * Check if an array represents a text editor object.
     *
     * @param array $data Array to check
     * @return bool True if it's a text editor object
     */
    private static function is_text_editor_object($data) {
        return is_array($data) &&
               array_key_exists('text', $data) &&
               (array_key_exists('format', $data) || array_key_exists('itemid', $data));
    }

    /**
     * Check if an array contains text editor objects.
     *
     * @param array $data Array to check
     * @return bool True if it's an array of text editor objects
     */
    private static function is_array_of_text_editor_objects($data) {
        if (!is_array($data) || empty($data)) {
            return false;
        }

        // Check if it's a numeric array (not associative).
        if (!array_is_list($data)) {
            return false;
        }

        // Check if the first element is a text editor object.
        return self::is_text_editor_object($data[0]);
    }

    /**
     * Normalize a single text editor object.
     *
     * @param array $editorobject Text editor object to normalize
     * @return array Normalized text editor object
     */
    private static function normalize_text_editor_object($editorobject) {
        $itemid = (int)($editorobject['itemid'] ?? 0);
        if ($itemid <= 0) {
            $itemid = file_get_unused_draft_itemid();
        }

        $text = (string)($editorobject['text'] ?? '');
        $text = self::normalize_escaped_html_quotes($text);
        $text = self::replace_generated_images_in_text($text, $itemid);

        return [
            'text' => $text,
            'format' => 1,
            'itemid' => $itemid,
        ];
    }

    /**
     * Normalize escaped quote sequences produced by JSON serialization.
     *
     * @param string $text Source text.
     * @return string
     */
    private static function normalize_escaped_html_quotes(string $text): string {
        return str_replace(['\\"', "\\'"], ['"', "'"], $text);
    }

    /**
     * Replace generated-image references with @@PLUGINFILE@@ URLs in draft area.
     *
     * @param string $text Editor text.
     * @param int $itemid Draft itemid where files will be stored.
     * @return string
     */
    private static function replace_generated_images_in_text(string $text, int $itemid): string {
        if ($text === '') {
            return $text;
        }

        $text = self::replace_html_images($text, $itemid);
        $text = self::replace_markdown_images($text, $itemid);

        // Remove unresolved placeholders to avoid showing raw template markers in the course content.
        $text = preg_replace('/^\s*\{\{image:\s*.*?\s*\}\}\s*$/imu', '', $text);
        $text = preg_replace('/\{\{image:\s*.*?\s*\}\}/iu', '', $text);

        return $text;
    }

    /**
     * Replace HTML <img> tags that reference generated files by local path.
     *
     * @param string $text Editor text.
     * @param int $itemid Draft itemid.
     * @return string
     */
    private static function replace_html_images(string $text, int $itemid): string {
        return preg_replace_callback(
            '/<img\b[^>]*>/iu',
            static function (array $matches) use ($itemid): string {
                $imgtag = $matches[0] ?? '';
                if ($imgtag === '' || !preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/iu', $imgtag, $srcmatches)) {
                    return $imgtag;
                }

                $source = html_entity_decode(trim((string)$srcmatches[2]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $filename = self::download_generated_image_to_draft($source, $itemid);
                if ($filename === null) {
                    return $imgtag;
                }

                $pluginsrc = '@@PLUGINFILE@@/' . $filename;
                return preg_replace(
                    '/\bsrc\s*=\s*(["\']).*?\1/iu',
                    'src="' . $pluginsrc . '"',
                    $imgtag,
                    1
                ) ?: $imgtag;
            },
            $text
        ) ?? $text;
    }

    /**
     * Replace markdown images with HTML tags that point to @@PLUGINFILE@@ files.
     *
     * @param string $text Editor text.
     * @param int $itemid Draft itemid.
     * @return string
     */
    private static function replace_markdown_images(string $text, int $itemid): string {
        return preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/u',
            static function (array $matches) use ($itemid): string {
                $alt = trim((string)($matches[1] ?? ''));
                $source = trim((string)($matches[2] ?? ''), '<>');

                $filename = self::download_generated_image_to_draft($source, $itemid);
                if ($filename === null) {
                    return $matches[0] ?? '';
                }

                $escapedalt = htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return '<img src="@@PLUGINFILE@@/' . $filename
                    . '" alt="' . $escapedalt . '" style="max-width:100%;height:auto;" />';
            },
            $text
        ) ?? $text;
    }

    /**
     * Download a generated image to the current draft item area.
     *
     * @param string $source Source path from the AI payload.
     * @param int $itemid Draft itemid.
     * @return string|null Stored filename when available.
     */
    private static function download_generated_image_to_draft(string $source, int $itemid): ?string {
        static $downloadcache = [];

        $source = trim($source);
        if ($source === '' || !self::is_local_generated_image_source($source)) {
            return null;
        }

        $cachekey = $itemid . '|' . $source;
        if (array_key_exists($cachekey, $downloadcache)) {
            return $downloadcache[$cachekey];
        }

        $client = self::get_ai_course_client();
        if ($client === null) {
            $downloadcache[$cachekey] = null;
            return null;
        }

        $filename = self::extract_filename_from_source($source);
        $endpoint = '/files/download?path=' . urlencode($source);

        try {
            $file = $client->download_file($endpoint, $filename, ['itemid' => $itemid]);
            if (!$file) {
                $downloadcache[$cachekey] = null;
                return null;
            }
            $downloadcache[$cachekey] = $file->get_filename();
            return $downloadcache[$cachekey];
        } catch (\Throwable $exception) {
            debugging('Could not download generated image: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            $downloadcache[$cachekey] = null;
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

        // Typical generated image paths are absolute filesystem paths under resource files.
        if (str_contains($source, '/generated_images/')) {
            return true;
        }

        return (bool)preg_match('#^/(tmp|var|home|data)/#', $source);
    }

    /**
     * Build a safe filename from an image source path.
     *
     * @param string $source Source path.
     * @return string
     */
    private static function extract_filename_from_source(string $source): string {
        $path = parse_url($source, PHP_URL_PATH) ?: $source;
        $filename = clean_param((string)basename((string)$path), PARAM_FILE);
        if ($filename === '' || $filename === '.') {
            $filename = 'generated-image-' . time() . '.png';
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
        try {
            $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
            $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;
            $client = new ai_course_api(null, $baseurl, $baseurleu);
        } catch (\Throwable $exception) {
            debugging('Could not initialize AI file client: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            $client = null;
        }

        return $client;
    }

    /**
     * Clean an array of text editor objects.
     *
     * @param array $editorarray Array of text editor objects
     * @return array Cleaned array of text editor objects
     */
    private static function clean_text_editor_array($editorarray) {
        $cleaned = [];

        foreach ($editorarray as $editorobject) {
            if (self::is_text_editor_object($editorobject)) {
                $cleaned[] = self::normalize_text_editor_object($editorobject);
            } else {
                // If it's not a text editor object, keep it as is but recursively clean.
                $cleaned[] = self::clean_text_editor_objects($editorobject);
            }
        }

        return $cleaned;
    }

    /**
     * Clean text editor parameters for a list of activities.
     *
     * @param array $activities Array of activities with parameters to clean
     * @return array Activities with cleaned text editor parameters
     */
    public static function clean_editor_parameters($activities) {
        if (!is_array($activities)) {
            return $activities;
        }

        foreach ($activities as $index => $activity) {
            if (isset($activity['parameters']) && is_array($activity['parameters'])) {
                $activities[$index]['parameters'] = self::clean_text_editor_objects($activity['parameters']);
            }
        }

        return $activities;
    }
}
