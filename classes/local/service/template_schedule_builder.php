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
 * The real schedule of a generated course, written where a page of the template asks for it.
 *
 * Marker contract: the author writes `[[coursegen:schedule]]` (or `⟦coursegen:schedule⟧`, no instruction, spaces
 * inside the brackets are free) in the content of a Page of the template. The page is saved with the action "keep",
 * so it is copied as it is and the AI service never sees the marker as something to write. Once the course exists
 * and the settings (visibility and the dates of the restrictions) are copied, every such page gets a table in place
 * of the marker (the paragraph that held it alone is replaced), and no marker is ever left in the course.
 *
 * The table has one row per activity, in the order of the course, with the columns Unit, Activity (a link to the
 * activity), Type and Available from. Units are the top-level sections except section 0 (in the Flexible sections
 * format an activity of a subsection is listed under the unit it hangs from; in any other format every section is a
 * unit). Labels, hidden activities, activities that are not shown on the course page, hidden sections and the page
 * that holds the marker are left out. A unit with no activity still has its row. "Available from" is the date of the
 * restriction "from" of the activity, else of its section, else of the sections it hangs from; when the restriction
 * has several dates, an all-of group opens at its latest date and an any-of group at its earliest, and "—" shows when
 * no date applies. A course with no unit gets a short note. The headings are lang strings in the language of the
 * course (the one of the user who creates it when the course has none).
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_schedule_builder {
    /** @var string The marker, in either dialect, as a pattern body. */
    private const MARKER_BODY = '(?:\[\[|⟦)\s*coursegen:schedule\s*(?:\]\]|⟧)';

    /** @var string What a cell shows when it has no value. */
    private const DASH = '—';

    /**
     * Replace the marker of every page of a course with the schedule table.
     *
     * @param int $courseid
     * @return int How many pages were rewritten.
     */
    public static function apply(int $courseid): int {
        global $DB;

        $like = $DB->sql_like('content', ':marker', false);
        $pages = $DB->get_records_select('page', 'course = :course AND ' . $like, [
            'course' => $courseid,
            'marker' => '%coursegen:schedule%',
        ]);
        $pages = array_filter($pages, static fn($page) => preg_match(self::pattern(), (string) $page->content) === 1);
        if (!$pages) {
            return 0;
        }

        $own = [];
        foreach ($pages as $page) {
            $cm = get_coursemodule_from_instance('page', $page->id, $courseid, false);
            if ($cm) {
                $own[(int) $cm->id] = true;
            }
        }
        $html = self::table_or_note($courseid, $own);
        foreach ($pages as $page) {
            $DB->update_record('page', (object) [
                'id' => $page->id,
                'content' => self::replace((string) $page->content, $html),
                'revision' => (int) $page->revision + 1,
                'timemodified' => time(),
            ]);
        }
        return count($pages);
    }

    /**
     * A text with the marker replaced by some html.
     *
     * @param string $content
     * @param string $html
     * @return string
     */
    public static function replace(string $content, string $html): string {
        $alone = '/<p[^>]*>\s*(?:&nbsp;|\s)*' . self::MARKER_BODY . '\s*(?:&nbsp;|\s)*<\/p>/su';
        $content = (string) preg_replace_callback($alone, static fn() => $html, $content);
        return (string) preg_replace_callback(self::pattern(), static fn() => $html, $content);
    }

    /**
     * The pattern of the marker.
     *
     * @return string
     */
    private static function pattern(): string {
        return '/' . self::MARKER_BODY . '/su';
    }

    /**
     * The table of the course; a note when it cannot be made, so the page never keeps the marker.
     *
     * @param int $courseid
     * @param bool[] $skip Activities (by cmid) that are not listed, set as keys.
     * @return string
     */
    private static function table_or_note(int $courseid, array $skip): string {
        try {
            rebuild_course_cache($courseid, true);
            $course = get_course($courseid);
            $lang = self::language_of($course);
            return self::table($course, get_fast_modinfo($course), $skip, $lang);
        } catch (\Throwable $exception) {
            debugging('local_coursegen: the schedule could not be built: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            return self::note(self::string('schedule_empty', null));
        }
    }

    /**
     * The language of the table: the one of the course when it is installed, else the one of the user.
     *
     * @param \stdClass $course
     * @return string
     */
    private static function language_of(\stdClass $course): string {
        $lang = (string) $course->lang;
        if ($lang !== '' && get_string_manager()->translation_exists($lang, false)) {
            return $lang;
        }
        return current_language();
    }

    /**
     * A string of the plugin in a language.
     *
     * @param string $id
     * @param string|null $lang Null for the language of the user.
     * @return string
     */
    private static function string(string $id, ?string $lang): string {
        return get_string_manager()->get_string($id, 'local_coursegen', null, $lang);
    }

    /**
     * The table, or the note when there is no unit.
     *
     * @param \stdClass $course
     * @param \course_modinfo $modinfo
     * @param bool[] $skip
     * @param string $lang
     * @return string
     */
    private static function table(\stdClass $course, \course_modinfo $modinfo, array $skip, string $lang): string {
        $tree = self::tree($modinfo);
        $units = $tree->children_of(0);
        if (!$units) {
            return self::note(self::string('schedule_empty', $lang));
        }
        $format = get_string_manager()->get_string('strftimedatefullshort', 'langconfig', null, $lang);
        $body = '';
        foreach ($units as $unit) {
            $section = $modinfo->get_section_info($unit);
            if ($section === null || !$section->visible) {
                continue;
            }
            $rows = self::unit_rows($course, $modinfo, $tree, $unit, $skip, $lang, $format);
            $body .= self::unit_html(s(get_section_name($course, $section)), $rows);
        }
        if ($body === '') {
            return self::note(self::string('schedule_empty', $lang));
        }
        $headings = '';
        foreach (['unit', 'activity', 'type', 'from'] as $column) {
            $headings .= '<th scope="col">' . s(self::string('schedule_column_' . $column, $lang)) . '</th>';
        }
        return '<table class="generaltable"><thead><tr>' . $headings . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }

    /**
     * The sections as a tree: the real one in the Flexible sections format, every section a top one otherwise.
     *
     * @param \course_modinfo $modinfo
     * @return template_section_tree
     */
    private static function tree(\course_modinfo $modinfo): template_section_tree {
        $tree = template_section_tree::from_modinfo($modinfo);
        if ($tree !== null) {
            return $tree;
        }
        $entries = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            $entries[] = ['section' => (int) $section->section];
        }
        return template_section_tree::from_entries($entries);
    }

    /**
     * The rows of one unit: the activities of its section and of the subsections under it.
     *
     * @param \stdClass $course
     * @param \course_modinfo $modinfo
     * @param template_section_tree $tree
     * @param int $unit The number of the unit.
     * @param bool[] $skip
     * @param string $lang
     * @param string $format The format of a date.
     * @return string[] The cells of each activity row that follow the unit: activity, type and date.
     */
    private static function unit_rows(
        \stdClass $course,
        \course_modinfo $modinfo,
        template_section_tree $tree,
        int $unit,
        array $skip,
        string $lang,
        string $format
    ): array {
        $rows = [];
        foreach (self::visible_sections($modinfo, $tree, $unit) as $number) {
            $section = $modinfo->get_section_info($number);
            foreach ($modinfo->sections[$number] ?? [] as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if (!self::is_listed($cm) || isset($skip[(int) $cm->id])) {
                    continue;
                }
                $date = self::opens_at_section_chain($cm, $modinfo, $tree, (int) $section->section);
                $rows[] = self::cells($cm, $lang, $date === null ? self::DASH : userdate($date, $format));
            }
        }
        return $rows;
    }

    /**
     * The numbers of a unit and of the subsections under it, in order, without a hidden subsection and what hangs from it.
     *
     * @param \course_modinfo $modinfo
     * @param template_section_tree $tree
     * @param int $number
     * @return int[]
     */
    private static function visible_sections(\course_modinfo $modinfo, template_section_tree $tree, int $number): array {
        $numbers = [$number];
        foreach ($tree->children_of($number) as $child) {
            $section = $modinfo->get_section_info($child);
            if ($section !== null && $section->visible) {
                $numbers = array_merge($numbers, self::visible_sections($modinfo, $tree, $child));
            }
        }
        return $numbers;
    }

    /**
     * Whether an activity is a row of the schedule.
     *
     * @param \cm_info $cm
     * @return bool
     */
    private static function is_listed(\cm_info $cm): bool {
        return $cm->modname !== 'label' && $cm->visible && $cm->visibleoncoursepage && !$cm->deletioninprogress;
    }

    /**
     * When an activity opens: its own restriction, else the one of its section, else of the sections above it.
     *
     * @param \cm_info $cm
     * @param \course_modinfo $modinfo
     * @param template_section_tree $tree
     * @param int $number The section of the activity.
     * @return int|null
     */
    private static function opens_at_section_chain(
        \cm_info $cm,
        \course_modinfo $modinfo,
        template_section_tree $tree,
        int $number
    ): ?int {
        $own = self::opens_at(json_decode((string) $cm->availability));
        if ($own !== null) {
            return $own;
        }
        while ($number > 0) {
            $section = $modinfo->get_section_info($number);
            $date = $section === null ? null : self::opens_at(json_decode((string) $section->availability));
            if ($date !== null) {
                return $date;
            }
            $number = $tree->parent_of($number);
        }
        return null;
    }

    /**
     * The moment the date conditions of a restriction let it open, when they decide it.
     *
     * @param mixed $node A decoded condition or group.
     * @return int|null
     */
    private static function opens_at($node): ?int {
        if (!$node instanceof \stdClass) {
            return null;
        }
        if (isset($node->type)) {
            $from = $node->type === 'date' && ($node->d ?? '') === '>=' && isset($node->t);
            return $from ? (int) $node->t : null;
        }
        $op = $node->op ?? '';
        if (($op !== '&' && $op !== '|') || !is_array($node->c ?? null)) {
            return null;
        }
        $dates = array_map([self::class, 'opens_at'], $node->c);
        $found = array_filter($dates, static fn($date) => $date !== null);
        if (!$found) {
            return null;
        }
        if ($op === '&') {
            return max($found);
        }
        return count($found) === count($dates) ? min($found) : null;
    }

    /**
     * The three cells of an activity that follow the unit.
     *
     * @param \cm_info $cm
     * @param string $lang
     * @param string $date
     * @return string
     */
    private static function cells(\cm_info $cm, string $lang, string $date): string {
        $name = s($cm->name);
        if ($cm->url) {
            $name = '<a href="' . s($cm->url->out(false)) . '">' . $name . '</a>';
        }
        $type = get_string_manager()->get_string('modulename', 'mod_' . $cm->modname, null, $lang);
        return '<td>' . $name . '</td><td>' . s($type) . '</td><td>' . s($date) . '</td>';
    }

    /**
     * The rows of a unit, with its name in the first cell spanning all of them.
     *
     * @param string $unit The escaped name of the unit.
     * @param string[] $rows The cells of each activity.
     * @return string
     */
    private static function unit_html(string $unit, array $rows): string {
        if (!$rows) {
            $dash = '<td>' . self::DASH . '</td>';
            return '<tr><td>' . $unit . '</td>' . $dash . $dash . $dash . '</tr>';
        }
        $html = '';
        foreach ($rows as $index => $cells) {
            $first = $index === 0 ? '<td rowspan="' . count($rows) . '">' . $unit . '</td>' : '';
            $html .= '<tr>' . $first . $cells . '</tr>';
        }
        return $html;
    }

    /**
     * A short note.
     *
     * @param string $text
     * @return string
     */
    private static function note(string $text): string {
        return '<p class="text-muted">' . s($text) . '</p>';
    }
}
