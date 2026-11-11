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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Text editor parameter cleaner utility class.
 *
 * This class provides utilities to clean and normalize text editor parameters
 * received from API responses, ensuring they conform to Moodle's expected
 * format and structure for text editor objects. The files a text references
 * are not handled here: once the activity exists, activity_file_pass gives it
 * every file its texts reference, whatever field they are in.
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

        $text = self::normalize_escaped_html_quotes((string)($editorobject['text'] ?? ''));

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
}
