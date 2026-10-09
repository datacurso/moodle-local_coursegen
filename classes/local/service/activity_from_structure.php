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
use local_coursegen\local\backup\activity_reader;
use local_coursegen\local\structure\overlay_writer;
use local_coursegen\local\structure\tree_changes;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Creates an activity from the tree of its template activity after the agent rewrote the words of that tree.
 *
 * The template activity is copied with Moodle's own backup and restore, so everything the activity is made of
 * survives: its structure, its rows, its files, its settings, its completion and its dates. Then only the texts the
 * agent changed are written onto the matching rows of the copy. No type of activity is named here: the rows of any
 * module are found through the structure the module declares for its own backup.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class activity_from_structure {
    /** @var string The parameter the agent sets on an activity whose words it rewrote inside the template tree. */
    private const FLAG = 'from_structure';

    /**
     * Whether the agent made this activity by rewriting the words of its template activity.
     *
     * @param array $resultinfo The generated activity of the result.
     * @return bool
     */
    public static function applies(array $resultinfo): bool {
        $parameters = $resultinfo['parameters'] ?? [];
        if (!is_array($parameters) || empty($parameters[self::FLAG])) {
            return false;
        }
        $structure = $parameters['structure'] ?? [];
        if (!is_array($structure) || $structure === []) {
            return false;
        }
        $sourcecmid = self::source_cmid($resultinfo);
        return $sourcecmid > 0;
    }

    /**
     * Create the activity in the course: copy the template activity and write the rewritten texts onto the copy.
     *
     * @param array $resultinfo The generated activity of the result.
     * @param object $course The course the activity goes to.
     * @param int $sectionnum The number of the section it goes to.
     * @param int|null $sourcecourseid Course whose files the rewritten texts may reference.
     * @return \stdClass The new course module: coursemodule, instance and course.
     * @throws \moodle_exception When the template activity is gone or the copy could not be made.
     */
    public static function create(array $resultinfo, $course, int $sectionnum, ?int $sourcecourseid): \stdClass {
        $parameters = (array) $resultinfo['parameters'];
        $sourcecmid = self::source_cmid($resultinfo);
        $sourcecm = self::source_cm($sourcecmid);
        $sectionid = self::section_id($course, $sectionnum);
        $copycmid = template_activity_duplicator::duplicate_into($sourcecm, $course, $sectionid);
        if ($copycmid === null) {
            throw new \moodle_exception('error_activity_creation_failed', 'local_coursegen');
        }
        $courseid = (int) $course->id;
        rebuild_course_cache($courseid, true);
        $modinfo = get_fast_modinfo($courseid);
        $copycm = $modinfo->get_cm($copycmid);

        $rewritten = (array) $parameters['structure'];
        self::write_texts($sourcecm, $copycm, $rewritten);
        rebuild_course_cache($courseid, true);

        $newcm = (object) [
            'coursemodule' => (int) $copycm->id,
            'instance' => (int) $copycm->instance,
            'course' => $courseid,
        ];
        $name = $parameters['name'] ?? $copycm->name;
        $name = (string) $name;
        new_activity_files::give((string) $copycm->modname, $newcm, $name, $sourcecourseid);
        return $newcm;
    }

    /**
     * The course module of the template activity the agent rewrote.
     *
     * @param array $resultinfo The generated activity of the result.
     * @return int Zero when the result does not say.
     */
    private static function source_cmid(array $resultinfo): int {
        $parameters = $resultinfo['parameters'] ?? [];
        $parameters = (array) $parameters;
        $fromparameters = $parameters['source_cmid'] ?? 0;
        $fromparameters = (int) $fromparameters;
        if ($fromparameters > 0) {
            return $fromparameters;
        }
        $payloadcmid = $resultinfo['cmid'] ?? 0;
        return (int) $payloadcmid;
    }

    /**
     * The template activity, read from the course it lives in.
     *
     * @param int $cmid The course module of the template activity.
     * @return \cm_info
     * @throws \moodle_exception When the activity or its course no longer exists.
     */
    private static function source_cm(int $cmid): \cm_info {
        global $DB;

        $courseid = $DB->get_field('course_modules', 'course', ['id' => $cmid]);
        if ($courseid === false) {
            throw new \moodle_exception('error_missing_parameters', 'local_coursegen');
        }
        $courseid = (int) $courseid;
        $modinfo = get_fast_modinfo($courseid);
        return $modinfo->get_cm($cmid);
    }

    /**
     * The id of a section of the course, creating the sections up to it when the course lacks them.
     *
     * @param object $course The course.
     * @param int $sectionnum The number of the section.
     * @return int The id of the section.
     */
    private static function section_id(object $course, int $sectionnum): int {
        global $DB;

        $conditions = ['course' => (int) $course->id, 'section' => $sectionnum];
        $id = $DB->get_field('course_sections', 'id', $conditions);
        if ($id !== false) {
            return (int) $id;
        }
        course_create_sections_if_missing($course, $sectionnum);
        $created = $DB->get_field('course_sections', 'id', $conditions, MUST_EXIST);
        return (int) $created;
    }

    /**
     * Write the texts the agent changed onto the rows of the copy.
     *
     * @param \cm_info $sourcecm The template activity.
     * @param \cm_info $copycm Its copy in the new course.
     * @param array $rewritten The tree the agent returned.
     * @return void
     */
    private static function write_texts(\cm_info $sourcecm, \cm_info $copycm, array $rewritten): void {
        $source = activity_reader::read_with_sources($sourcecm);
        $copy = activity_reader::read_with_sources($copycm);
        $changes = tree_changes::between($source['tree'], $rewritten);
        $writer = new overlay_writer();
        $result = $writer->apply($changes, $copy['tree'], $copy['tables'], $copy['aliases']);
        $skipped = $result->skipped_paths();
        if ($skipped === []) {
            return;
        }
        $list = implode(', ', $skipped);
        $message = 'local_coursegen: texts left as the template has them in ' . $copycm->modname . ' ' . $copycm->id;
        debugging($message . ': ' . $list);
    }
}
