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

/**
 * The course a run is going to produce, built from what was sent and answered.
 *
 * Nothing here reads the site. The payload sent to the service already
 * describes the whole course: its sections, every activity in them, what each
 * activity is made of, and what the run was told to do with it. The answer
 * adds what the run intends to write. Between them there is nothing left to
 * ask Moodle for, and asking anyway is how a preview ends up showing a course
 * that exists instead of the one being decided about.
 *
 * Every element is named by the uid it carries in that payload, so opening one
 * asks for it by the name it already has.
 *
 * What comes out is the context of core's own course format templates, not
 * markup: the sections and the activity rows are then drawn by the same
 * templates that draw a real course, so they are the same rows.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_from_payload {
    /**
     * The whole course, ready for core_courseformat/local/content.
     *
     * @param array $payload What was sent to the service.
     * @param array $summaries uid => what the plan says that activity will contain.
     * @param int $sessionid The run being previewed, for the links out.
     * @param int|null $only One section on its own, for a format that opens
     *                       them that way; null for the whole course.
     * @return array
     */
    public static function content(array $payload, array $summaries, int $sessionid, ?int $only = null): array {
        $bysection = self::activities_by_section($payload);

        $sections = [];
        $initial = null;
        foreach (($payload['sections_info'] ?? []) as $info) {
            $number = (int) ($info['section'] ?? 0);
            if ($only !== null && $number !== $only && $number !== 0) {
                continue;
            }
            $section = self::section($info, $bysection[$number] ?? [], $summaries, $sessionid);
            if ($number === 0 && $only !== null) {
                continue;
            }
            if ($number === 0) {
                $initial = $section;
                continue;
            }
            $sections[] = $section;
        }

        return [
            // The class the list of sections carries, which is the format's
            // own name: a format styles its list by it.
            'format' => (string) (($payload['course_configuration'] ?? [])['format'] ?? 'topics'),
            'initialsection' => $initial,
            'sections' => $sections,
            'hassections' => !empty($sections),
        ];
    }

    /**
     * Every activity, grouped by its own section number.
     *
     * @param array $payload
     * @return array
     */
    private static function activities_by_section(array $payload): array {
        $bysection = [];
        foreach (($payload['activities'] ?? []) as $activity) {
            // A mould is read to write the activities built on it and is never
            // one of them, so it is not part of the course being previewed.
            if ((($activity['template_behavior'] ?? [])['action'] ?? '') === 'template') {
                continue;
            }
            $number = (int) (($activity['parameters'] ?? [])['section'] ?? 0);
            $bysection[$number][] = $activity;
        }
        return $bysection;
    }

    /**
     * One section, with the activities it is going to hold.
     *
     * @param array $info The section as the payload describes it.
     * @param array $activities Its activities, in the order they were sent.
     * @param array $summaries
     * @param int $sessionid
     * @return array
     */
    private static function section(array $info, array $activities, array $summaries, int $sessionid): array {
        $number = (int) ($info['section'] ?? 0);
        $name = (string) ($info['name'] ?? '');

        $cms = [];
        foreach ($activities as $activity) {
            $cms[] = ['cmitem' => self::activity($activity, $summaries, $sessionid)];
        }

        return [
            'num' => $number,
            'id' => (string) ($info['uid'] ?? $number),
            'sectionname' => $name,
            'header' => [
                'name' => $name,
                'title' => $name,
                'ishidden' => false,
            ],
            'cmlist' => [
                'cms' => $cms,
                'hascms' => !empty($cms),
            ],
            'ishidden' => false,
            'iscurrent' => false,
            'contentcollapsed' => false,
            'summary' => ['summarytext' => self::summary($info)],
        ];
    }

    /**
     * A section's summary, shown the way a course page shows it.
     *
     * It is text a person wrote, in the format they wrote it in, and a course
     * page runs it through the same cleaning and filtering as any other.
     *
     * @param array $info
     * @return string
     */
    private static function summary(array $info): string {
        global $PAGE;

        $text = (string) ($info['summary'] ?? '');
        if (trim($text) === '') {
            return '';
        }
        return format_text($text, (int) ($info['summaryformat'] ?? FORMAT_HTML), ['context' => $PAGE->context]);
    }

    /**
     * One activity, as a row of the list its section draws.
     *
     * @param array $activity
     * @param array $summaries
     * @param int $sessionid
     * @return array
     */
    private static function activity(array $activity, array $summaries, int $sessionid): array {
        global $OUTPUT;

        $uid = (string) ($activity['uid'] ?? '');
        $modname = (string) ($activity['resource_type'] ?? '');
        $name = (string) (($activity['parameters'] ?? [])['name'] ?? '');
        $writing = (($activity['template_behavior'] ?? [])['action'] ?? '') === 'modify';

        $url = (new moodle_url('/local/coursegen/activity_preview.php', [
            'sessionid' => $sessionid,
            'uid' => $uid,
        ]))->out(false);

        $summary = trim((string) ($summaries[$uid] ?? ''));

        $activitybadge = null;
        if ($writing) {
            $activitybadge = [
                'badgecontent' => get_string('courseai_template_instance_badge', 'local_coursegen'),
                'badgestyle' => 'badge-none border',
            ];
        }
        $altcontent = '';
        if ($summary !== '') {
            $altcontent = format_text($summary, FORMAT_PLAIN);
        }
        $extraclasses = '';
        if ($writing) {
            $extraclasses = 'local-coursegen-planned';
        }

        return [
            'cmformat' => [
                'hasname' => true,
                'activityname' => format_string($name),
                'cmname' => [
                    'url' => $url,
                    'modname' => $modname,
                    'textclasses' => '',
                    'activityicon' => self::icon($modname),
                    // The name as a course page carries it: the value on its
                    // own, because there is nothing here to edit in place.
                    'activityname' => [
                        'displayvalue' => html_writer::link(
                            $url,
                            html_writer::span(format_string($name), 'instancename'),
                            ['class' => 'aalink']
                        ),
                    ],
                    // An activity the run is going to write says so, because
                    // that is what the teacher is deciding about.
                    'activitybadge' => $activitybadge,
                ],
                'altcontent' => $altcontent,
                'hasaltcontent' => $summary !== '',
            ],
            'id' => $uid,
            'anchor' => 'activity-' . $uid,
            'module' => $modname,
            'extraclasses' => $extraclasses,
            'indent' => 0,
        ];
    }

    /**
     * A module's icon, as a course page draws it.
     *
     * @param string $modname
     * @return array
     */
    private static function icon(string $modname): array {
        global $OUTPUT;

        return [
            'icon' => $OUTPUT->image_url('monologo', $modname)->out(false),
            'purpose' => plugin_supports('mod', $modname, FEATURE_MOD_PURPOSE, MOD_PURPOSE_OTHER),
            'pluginname' => get_string('pluginname', 'mod_' . $modname),
            'showtooltip' => true,
            'branded' => false,
        ];
    }
}
