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

namespace local_coursegen\local\space;

use local_coursegen\local\models\template;
use local_coursegen\local\models\template_space;
use local_coursegen\local\service\template_keep_copier;

/**
 * The spaces of a template the teacher fills with one of their own activities.
 *
 * A space is a place of the template where the teacher brings an activity of a given type (a quiz, a file...).
 * When the course is created, the activity the teacher picked is copied into it, in the place of the space.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_spaces {
    /** The most activities offered to pick from. */
    const MAX_CANDIDATES = 300;

    /**
     * The activities of a type the user can bring to a space: the ones of the courses where they can back them up.
     *
     * @param int $userid
     * @param string $modname
     * @return array Each: cmid, name, coursename.
     */
    public static function candidates(int $userid, string $modname): array {
        $candidates = [];
        foreach (enrol_get_users_courses($userid, true, 'id, fullname') as $course) {
            foreach (self::course_candidates($course, $modname, $userid) as $candidate) {
                $candidates[] = $candidate;
                if (count($candidates) >= self::MAX_CANDIDATES) {
                    return $candidates;
                }
            }
        }
        return $candidates;
    }

    /**
     * The activities of a type of one course that the user can back up.
     *
     * @param \stdClass $course
     * @param string $modname
     * @param int $userid
     * @return array Each: cmid, name, coursename.
     */
    private static function course_candidates(\stdClass $course, string $modname, int $userid): array {
        $found = [];
        $modinfo = get_fast_modinfo($course, $userid);
        foreach ($modinfo->get_instances_of($modname) as $cm) {
            if (!$cm->deletioninprogress && self::can_bring($cm, $userid)) {
                $found[] = [
                    'cmid' => (int) $cm->id,
                    'name' => format_string($cm->name),
                    'coursename' => format_string($course->fullname),
                ];
            }
        }
        return $found;
    }

    /**
     * Whether the user can bring this activity: copying it is backing it up.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @return bool
     */
    private static function can_bring(\cm_info $cm, int $userid): bool {
        return has_capability('moodle/backup:backupactivity', \context_module::instance($cm->id), $userid);
    }

    /**
     * The activities picked for the spaces, checked: refused before anything is sent when one does not fit.
     *
     * @param int $templateid
     * @param int $userid
     * @param array $picked Each: uid (of the space), cmid (of the activity the teacher brings).
     * @return array<string,int> Space uid => cmid of the activity.
     * @throws \moodle_exception When a space is not the template's, an activity is not of its type or cannot be
     *     brought by the user, or a required space has none.
     */
    public static function validated(int $templateid, int $userid, array $picked): array {
        $spaces = self::spaces_by_uid($templateid);
        $chosen = [];
        foreach ($picked as $entry) {
            $uid = (string) ($entry['uid'] ?? '');
            $space = $spaces[$uid] ?? null;
            if ($space === null) {
                throw new \moodle_exception('courseai_template_space_activity_invalid', 'local_coursegen');
            }
            $cm = self::activity_of((int) ($entry['cmid'] ?? 0));
            if ($cm === null || $cm->modname !== $space->get('modname') || !self::can_bring($cm, $userid)) {
                throw new \moodle_exception('courseai_template_space_activity_invalid', 'local_coursegen');
            }
            $chosen[$uid] = (int) $cm->id;
        }
        foreach ($spaces as $uid => $space) {
            if ((int) $space->get('required') === 1 && !isset($chosen[$uid])) {
                throw new \moodle_exception('courseai_template_space_activity_missing', 'local_coursegen');
            }
        }
        return $chosen;
    }

    /**
     * The spaces of a template, by their uid.
     *
     * @param int $templateid
     * @return array<string,template_space>
     */
    public static function spaces_by_uid(int $templateid): array {
        $spaces = [];
        foreach (template_space::get_records(['templateid' => $templateid]) as $space) {
            $spaces[(string) $space->get('uid')] = $space;
        }
        return $spaces;
    }

    /**
     * The activity of a cmid, or null when it does not exist.
     *
     * @param int $cmid
     * @return \cm_info|null
     */
    private static function activity_of(int $cmid): ?\cm_info {
        global $DB;

        $courseid = $DB->get_field('course_modules', 'course', ['id' => $cmid]);
        if (!$courseid) {
            return null;
        }
        $cms = get_fast_modinfo((int) $courseid)->get_cms();
        return $cms[$cmid] ?? null;
    }

    /**
     * Copy the activities picked for the spaces into the new course, each in the section of its space.
     *
     * An activity that can no longer be copied (it was deleted meanwhile) is left out: the course is already made.
     *
     * @param int $templateid
     * @param int $courseid The new course.
     * @param array $chosen Space uid => cmid of the activity, as validated() gave it.
     * @return array<string,int> Space uid => cmid of the copy in the new course.
     */
    public static function place(int $templateid, int $courseid, array $chosen): array {
        $template = template::get_record(['id' => $templateid]);
        if (!$template || $courseid <= 0 || !$chosen) {
            return [];
        }
        $sectionnumbers = self::section_numbers((int) $template->get('courseid'));
        $spaces = self::spaces_by_uid($templateid);
        $placed = [];
        foreach ($chosen as $uid => $cmid) {
            $space = $spaces[$uid] ?? null;
            $cm = self::activity_of((int) $cmid);
            if ($space === null || $cm === null) {
                continue;
            }
            $number = $sectionnumbers[(int) $space->get('sectionid')] ?? 0;
            $copy = template_keep_copier::copy_activity($cm, $courseid, $number);
            if ($copy !== null) {
                $placed[$uid] = $copy;
            }
        }
        return $placed;
    }

    /**
     * The number of each section of the template's course, by section id.
     *
     * @param int $courseid
     * @return array<int,int>
     */
    private static function section_numbers(int $courseid): array {
        $numbers = [];
        foreach (get_fast_modinfo($courseid)->get_section_info_all() as $section) {
            $numbers[(int) $section->id] = (int) $section->section;
        }
        return $numbers;
    }
}
