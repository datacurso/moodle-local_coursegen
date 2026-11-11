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

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * The pictures of a template's course that belong to no activity, and the instruction the author left for each.
 *
 * The author writes "[[coursegen:reference: instruction]]" in the rich text that sits next to the picture:
 *
 * - In the summary of a section, right above a picture of the text, it asks for a new picture in that place.
 * - In the summary of a section, with no picture of the text after it, it asks for a new picture of the section
 *   (the "Section image" of the grid format), looking at the one the template has.
 * - In the summary of the course, it asks for a new cover of the course, looking at the one the template has.
 *
 * Each marker becomes one slot, sent to the AI service with its reference picture. The markers are taken out of the
 * texts the new course receives.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_course_image_slots {
    /** @var string One reference marker, in either dialect, without delimiters; the instruction is the "text" group. */
    private const MARKER_BODY = '(?:\[\[|⟦)\s*coursegen:reference\s*(?::(?P<text>.*?))?\s*(?:\]\]|⟧)';

    /** @var string The same, as a pattern. */
    private const MARKER = '/' . self::MARKER_BODY . '/su';

    /** @var string[] The kinds of picture the AI service can look at. */
    private const REFERENCE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /**
     * Every slot of a template's course.
     *
     * @param \stdClass $course The template's course.
     * @return array[] Each: uid, key, kind, instruction, filename, mimetype, section, label, file (stored_file|null).
     * @throws \moodle_exception A marker is misplaced.
     */
    public static function for_course(\stdClass $course): array {
        global $DB;

        $slots = [];
        $cover = self::item_slots((string) $course->summary, 'the summary of the course');
        foreach ($cover['item'] as $marker) {
            $file = self::cover_file((int) $course->id);
            $slots[] = self::slot('slot-course', 'course:cover', 'course_cover', $marker, null, 'Course cover', $file);
        }
        foreach ($DB->get_records('course_sections', ['course' => $course->id], 'section') as $section) {
            $number = (int) $section->section;
            $analysed = self::item_slots((string) $section->summary, 'the summary of section ' . $number);
            $uid = 'slot-section-' . $number;
            foreach ($analysed['item'] as $marker) {
                $file = self::grid_file((int) $course->id, (int) $section->id);
                $label = 'Section ' . $number . ' picture';
                $slots[] = self::slot($uid, 'section:' . $number . ':image', 'section_image', $marker, $number, $label, $file);
            }
            foreach ($analysed['text'] as $position => $marker) {
                $file = self::summary_file((int) $course->id, (int) $section->id, $marker['filename']);
                $key = 'section:' . $number . ':summary:' . $position;
                $label = 'Section ' . $number . ' summary picture ' . $position;
                $slots[] = self::slot($uid, $key, 'summary_image', $marker, $number, $label, $file);
            }
        }
        return $slots;
    }

    /**
     * The slots as they travel in the payload: without the stored files.
     *
     * @param array[] $slots What for_course() returned.
     * @return array[]
     */
    public static function payload(array $slots): array {
        return array_map(static function (array $slot): array {
            unset($slot['file']);
            return $slot;
        }, $slots);
    }

    /**
     * The files to send to a session, one per slot that has a picture to look at.
     *
     * @param array[] $slots What for_course() returned.
     * @return array[] Each: uid (string), file (stored_file).
     */
    public static function uploads(array $slots): array {
        $uploads = [];
        foreach ($slots as $slot) {
            if ($slot['file'] !== null && $slot['filename'] !== '') {
                $uploads[] = ['uid' => $slot['uid'], 'file' => $slot['file']];
            }
        }
        return $uploads;
    }

    /**
     * A text without its reference markers, and without the paragraph a marker was alone in.
     *
     * @param string $html
     * @return string
     */
    public static function without_markers(string $html): string {
        if (strpos($html, 'coursegen:reference') === false) {
            return $html;
        }
        $alone = '/<(p|div)[^>]*>\s*(?:&nbsp;|\s)*' . self::MARKER_BODY . '\s*(?:&nbsp;|\s)*<\/\1>\s*/su';
        $html = (string) preg_replace($alone, '', $html);
        return (string) preg_replace(self::MARKER, '', $html);
    }

    /**
     * The markers of a text, told apart by whether a picture of the text follows them.
     *
     * @param string $html
     * @param string $where Where the text is, for the message of an error.
     * @return array{item: array[], text: array<int,array>}
     * @throws \moodle_exception
     */
    private static function item_slots(string $html, string $where): array {
        $found = ['item' => [], 'text' => []];
        foreach (self::analyse($html, $where) as $position => $marker) {
            if ($marker['filename'] === null) {
                $found['item'][] = $marker;
            } else {
                $found['text'][$position + 1] = $marker;
            }
        }
        if (count($found['item']) > 1) {
            throw new \moodle_exception('template_image_marker_twice', 'local_coursegen', '', $where);
        }
        return $found;
    }

    /**
     * Each marker of a text with the instruction it holds and the name of the file the next element carries.
     *
     * @param string $html
     * @param string $where
     * @return array[] Each: instruction, filename (null when no element with a file follows).
     */
    private static function analyse(string $html, string $where): array {
        if (strpos($html, 'coursegen:reference') === false) {
            return [];
        }
        preg_match_all(self::MARKER, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $marked = $html;
        foreach (array_reverse(array_keys($matches)) as $index) {
            [$text, $offset] = $matches[$index][0];
            $span = '<span data-cgm="' . $index . '"></span>';
            $marked = substr($marked, 0, $offset) . $span . substr($marked, $offset + strlen($text));
        }
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="cgroot">' . $marked . '</div>');
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $analysed = [];
        foreach (array_keys($matches) as $index) {
            $span = $xpath->query('//span[@data-cgm="' . $index . '"]')->item(0);
            $next = $span ? $xpath->query('following::*[1]', $span)->item(0) : null;
            $instruction = trim((string) ($matches[$index]['text'][0] ?? ''));
            $analysed[] = ['instruction' => $instruction, 'filename' => self::file_of($next, $xpath, $where)];
        }
        return $analysed;
    }

    /**
     * The name of the only file an element carries, or null when it carries none or is another marker.
     *
     * @param \DOMNode|null $element
     * @param DOMXPath $xpath
     * @param string $where
     * @return string|null
     * @throws \moodle_exception The element carries more than one file.
     */
    private static function file_of($element, DOMXPath $xpath, string $where): ?string {
        if (!$element instanceof DOMElement || $element->hasAttribute('data-cgm')) {
            return null;
        }
        $names = [];
        $nodes = $xpath->query('descendant-or-self::*/@src | descendant-or-self::*/@href | descendant-or-self::*/@data | ' .
            'descendant-or-self::*/@poster', $element);
        foreach ($nodes as $attribute) {
            $name = template_reference_scanner::file_name_of($attribute->nodeValue);
            if ($name !== null) {
                $names[$name] = true;
            }
        }
        if (count($names) > 1) {
            throw new \moodle_exception('template_image_marker_many_files', 'local_coursegen', '', $where);
        }
        return $names === [] ? null : (string) array_key_first($names);
    }

    /**
     * One slot.
     *
     * @param string $uid
     * @param string $key
     * @param string $kind
     * @param array $marker
     * @param int|null $section
     * @param string $label
     * @param \stored_file|null $file The picture of the template in that place.
     * @return array
     */
    private static function slot(
        string $uid,
        string $key,
        string $kind,
        array $marker,
        ?int $section,
        string $label,
        ?\stored_file $file
    ): array {
        $usable = $file !== null && in_array($file->get_mimetype(), self::REFERENCE_TYPES, true);
        return [
            'uid' => $uid,
            'key' => $key,
            'kind' => $kind,
            'instruction' => $marker['instruction'],
            'filename' => $usable ? $file->get_filename() : '',
            'mimetype' => $usable ? $file->get_mimetype() : '',
            'section' => $section,
            'label' => $label,
            'file' => $usable ? $file : null,
        ];
    }

    /**
     * The cover of a course, or null when it has none.
     *
     * @param int $courseid
     * @return \stored_file|null
     */
    private static function cover_file(int $courseid): ?\stored_file {
        $context = \context_course::instance($courseid);
        $files = get_file_storage()->get_area_files($context->id, 'course', 'overviewfiles', 0, 'filename', false);
        return $files ? reset($files) : null;
    }

    /**
     * The picture of a section in the grid format, or null when it has none.
     *
     * @param int $courseid
     * @param int $sectionid
     * @return \stored_file|null
     */
    private static function grid_file(int $courseid, int $sectionid): ?\stored_file {
        global $DB;

        $context = \context_course::instance($courseid);
        $files = get_file_storage()->get_area_files($context->id, 'format_grid', 'sectionimage', $sectionid, 'filename', false);
        $record = $DB->get_record('format_grid_image', ['sectionid' => $sectionid]);
        if (!$files || !$record) {
            return null;
        }
        return reset($files);
    }

    /**
     * A file of the summary of a section, or null when the section does not have it.
     *
     * @param int $courseid
     * @param int $sectionid
     * @param string $filename
     * @return \stored_file|null
     */
    private static function summary_file(int $courseid, int $sectionid, string $filename): ?\stored_file {
        $context = \context_course::instance($courseid);
        $file = get_file_storage()->get_file($context->id, 'course', 'section', $sectionid, '/', $filename);
        return $file ?: null;
    }
}
