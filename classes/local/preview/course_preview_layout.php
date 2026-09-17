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

namespace local_coursegen\local\preview;

use html_writer;
use moodle_url;
use section_info;

/**
 * Puts what a run is going to add into the course page it will be added to.
 *
 * The page is drawn by the course's own format, which is the only way to get
 * the page the teacher will actually receive: a grid is a grid, a weekly
 * course is dated, and a theme decides what an activity row looks like. None
 * of that survives being redrawn by hand.
 *
 * A format builds its page from the activities that exist, and the ones a run
 * is going to write do not exist yet, so they cannot be in it. They are put in
 * afterwards, into the list the format itself drew for their section, with the
 * markup an activity row has, so they sit among the real ones.
 *
 * What the format drew is edited in place rather than parsed and written back
 * out. A course page carries inline templates, scripts and markup that an HTML
 * parser is entitled to reinterpret, and reinterpreting a whole page in order
 * to add two rows to it is how a preview stops being faithful: reading the
 * grid format's own output back cost a fifth of it. So the only bytes touched
 * are the ones being changed.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_preview_layout {
    /**
     * The rendered course, made into a preview of itself.
     *
     * @param string $rendered What the format drew.
     * @param array $planned Section id => list of planned activities.
     * @param section_info[] $sections The course's sections.
     * @param int $courseid The template's own course, whose links are rewritten.
     * @param int $sessionid The run being previewed.
     * @return string
     */
    public static function rebuild(
        string $rendered,
        array $planned,
        array $sections,
        int $courseid,
        int $sessionid
    ): string {
        if (trim($rendered) === '') {
            return $rendered;
        }

        $out = self::rewrite_course_links($rendered, $courseid, $sessionid, $sections);
        foreach ($planned as $sectionid => $activities) {
            $rows = '';
            foreach ($activities as $activity) {
                $rows .= self::row($activity);
            }
            if ($rows !== '') {
                $out = self::add_to_section($out, (int) $sectionid, $sections, $rows);
            }
        }
        return $out;
    }

    /**
     * Put some rows at the end of one section's list of activities.
     *
     * A section is found by the id of its own record, or by its number in the
     * course for a format that draws that instead, and its activities are in
     * the list core's own template marks as being for them. A format that
     * draws neither, or that does not draw this section at all because it
     * shows one section at a time, is left exactly as it was.
     *
     * @param string $html
     * @param int $sectionid
     * @param section_info[] $sections
     * @param string $rows
     * @return string
     */
    private static function add_to_section(string $html, int $sectionid, array $sections, string $rows): string {
        $anchors = ['data-id="' . $sectionid . '"'];
        foreach ($sections as $section) {
            if ((int) $section->id === $sectionid) {
                $anchors[] = 'id="section-' . (int) $section->section . '"';
                break;
            }
        }

        foreach ($anchors as $anchor) {
            $at = strpos($html, $anchor);
            if ($at === false) {
                continue;
            }
            $list = strpos($html, 'data-for="cmlist"', $at);
            if ($list === false) {
                continue;
            }
            $opens = strpos($html, '>', $list);
            if ($opens === false) {
                continue;
            }
            $closes = self::closing_tag($html, $opens + 1, 'ul');
            if ($closes === null) {
                continue;
            }
            return substr($html, 0, $closes) . $rows . substr($html, $closes);
        }
        return $html;
    }

    /**
     * Where the tag open at this point closes, counting the ones inside it.
     *
     * An activity row can hold lists of its own, so the first closing tag
     * after the opening one is rarely the one that matches it.
     *
     * @param string $html
     * @param int $from Just past the opening tag.
     * @param string $tag
     * @return int|null The offset of the matching closing tag, or null.
     */
    private static function closing_tag(string $html, int $from, string $tag): ?int {
        $depth = 1;
        $at = $from;
        $open = '<' . $tag;
        $close = '</' . $tag;

        while ($depth > 0) {
            $nextopen = stripos($html, $open, $at);
            $nextclose = stripos($html, $close, $at);
            if ($nextclose === false) {
                return null;
            }
            if ($nextopen !== false && $nextopen < $nextclose) {
                $depth++;
                $at = $nextopen + strlen($open);
                continue;
            }
            $depth--;
            if ($depth === 0) {
                return $nextclose;
            }
            $at = $nextclose + strlen($close);
        }
        return null;
    }

    /**
     * Point the format's own navigation back at the preview.
     *
     * A format that shows one section at a time draws links to the rest of
     * them, and those links go to the course. Following one would leave the
     * preview for the template's real course, which is not what the teacher
     * asked to look at, so each is pointed at this page with the section it
     * was going to.
     *
     * @param string $html
     * @param int $courseid
     * @param int $sessionid
     * @param section_info[] $sections
     * @return string
     */
    private static function rewrite_course_links(
        string $html,
        int $courseid,
        int $sessionid,
        array $sections
    ): string {
        // A section's own page, which is how a format whose sections are tiles
        // opens one. It names the section by the id of its record rather than
        // by its number in the course.
        $numbers = [];
        foreach ($sections as $section) {
            $numbers[(int) $section->id] = (int) $section->section;
        }
        $html = (string) preg_replace_callback(
            '~(?<attr>href|value)="[^"]*?/course/section\.php\?id=(?<id>\d+)[^"]*"~',
            static function (array $match) use ($sessionid, $numbers): string {
                $number = $numbers[(int) $match['id']] ?? null;
                if ($number === null) {
                    return $match[0];
                }
                $url = new moodle_url('/local/coursegen/course_preview.php', [
                    'sessionid' => $sessionid,
                    'section' => $number,
                ]);
                return $match['attr'] . '="' . s($url->out(false)) . '"';
            },
            $html
        );

        // Also as the value of an option, because a section page's "jump to"
        // menu is a select rather than a list of links, and it navigates.
        $pattern = '~(?<attr>href|value)="[^"]*?/course/view\.php\?id=' . $courseid . '(?<rest>[^"]*)"~';

        return (string) preg_replace_callback($pattern, static function (array $match) use ($sessionid): string {
            $parameters = [];
            parse_str(ltrim(str_replace('&amp;', '&', $match['rest']), '&'), $parameters);

            $preview = ['sessionid' => $sessionid];
            if (isset($parameters['section'])) {
                $preview['section'] = (int) $parameters['section'];
            }

            $fragment = '';
            if (preg_match('~#(?<name>[^"&]*)$~', $match['rest'], $found)) {
                $fragment = '#' . $found['name'];
            }

            $url = new moodle_url('/local/coursegen/course_preview.php', $preview);
            return $match['attr'] . '="' . s($url->out(false) . $fragment) . '"';
        }, $html);
    }

    /**
     * One planned activity, in the shape of an activity row.
     *
     * @param array $activity
     * @return string
     */
    private static function row(array $activity): string {
        global $OUTPUT;

        $modname = (string) $activity['modname'];
        $icon = $OUTPUT->image_icon('monologo', $modname, 'mod_' . $modname, ['class' => 'icon activityicon']);

        $name = html_writer::link(
            $activity['url'],
            format_string((string) $activity['name']),
            ['class' => 'aalink stretched-link', 'target' => '_blank', 'rel' => 'noopener']
        );
        $badge = html_writer::span(
            get_string('courseai_template_instance_badge', 'local_coursegen'),
            'badge badge-light border ms-2',
            ['title' => get_string('courseai_template_instance_badge_tip', 'local_coursegen')]
        );

        $title = html_writer::div(
            html_writer::div(
                html_writer::span($name . $badge, 'instancename'),
                'activityname'
            ),
            'activitytitle modtype_' . $modname . ' position-relative align-self-start'
        );

        $description = '';
        $summary = trim((string) ($activity['summary'] ?? ''));
        if ($summary !== '') {
            $description = html_writer::div(
                format_text($summary, FORMAT_PLAIN, ['context' => \context_system::instance()]),
                'activity-altcontent description mt-1'
            );
        }

        $namearea = html_writer::div(
            $title . $description,
            'activity-name-area activity-instance d-flex flex-column me-2'
        );

        $grid = html_writer::div(
            html_writer::div($icon, 'activity-icon activityiconcontainer smaller courseicon align-self-start me-2')
            . $namearea,
            'activity-grid'
        );

        return html_writer::tag(
            'li',
            html_writer::div($grid, 'activity-item', ['data-region' => 'activity-card']),
            ['class' => 'activity activity-wrapper ' . $modname . ' modtype_' . $modname . ' local-coursegen-planned']
        );
    }
}
