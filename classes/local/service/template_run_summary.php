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

use local_coursegen\local\models\template;

/**
 * What a course made from a template was generated with, as the first message of its thread.
 *
 * The run keeps the payload it was given and the name of the attached syllabus with its session, so a course reopened
 * later still says which template, language, syllabus and instruction it was made from.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_run_summary {
    /**
     * The context of the local_coursegen/template_run_summary template, read from a session's stored data.
     *
     * @param array $coursedata The session's coursedata, decoded.
     * @return array
     */
    public static function from_coursedata(array $coursedata): array {
        $payload = $coursedata['payload'] ?? [];
        $templateid = (int) ($coursedata['templateid'] ?? 0);
        $record = $templateid ? template::get_record(['id' => $templateid]) : false;
        // A session started before the syllabus name was kept does not know it, and says so.
        $syllabus = array_key_exists('syllabus', $coursedata) ? (string) $coursedata['syllabus'] : null;
        return self::context(
            $record ? format_string($record->get('name')) : '',
            self::language_name((string) ($payload['lang'] ?? '')),
            $syllabus,
            (string) ($payload['general_instruction'] ?? '')
        );
    }

    /**
     * The template context for the given values.
     *
     * @param string $template Template name.
     * @param string $language Language as the selector names it.
     * @param string|null $syllabus Attached syllabus file name, '' for none, null when unknown.
     * @param string $instruction General instruction typed by the professor.
     * @return array
     */
    public static function context(string $template, string $language, ?string $syllabus, string $instruction): array {
        return [
            'template' => $template,
            'language' => $language,
            'syllabusknown' => $syllabus !== null,
            'syllabus' => (string) $syllabus,
            'instruction' => trim($instruction),
        ];
    }

    /**
     * A language code as the language selector of the page shows it.
     *
     * @param string $code
     * @return string
     */
    private static function language_name(string $code): string {
        if ($code === '') {
            return '';
        }
        $languages = get_string_manager()->get_list_of_languages(null, 'iso6391');
        if (!isset($languages[$code])) {
            return $code;
        }
        return $languages[$code] . ' (' . strtoupper($code) . ')';
    }
}
