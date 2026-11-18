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
 * Applies the hidden state and the completion rule the guideline asked for to the generated activities.
 *
 * The AI service sets ``parameters.visible`` (false: hidden from students) and ``parameters.completion_rule``
 * (none, view, grade, submit or post) on the activities it adds. An activity without either key is left as
 * it was created, so ordinary template courses are not touched.
 *
 * Moodle 4.5 fields written per rule: view = completion automatic + completionview; grade = completion
 * automatic + completiongradeitemnumber 0 (the grade is required) when the module has a grade item, else it
 * falls back to view; submit = the module's own completionsubmit (assign, feedback); post = the module's own
 * completionposts (forum). A rule the module cannot honour is skipped and logged.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class guideline_activity_settings {
    /** @var string[][] Custom completion column of the module table, by rule and module. */
    private const CUSTOM_COLUMNS = [
        'submit' => ['assign' => 'completionsubmit', 'feedback' => 'completionsubmit'],
        'post' => ['forum' => 'completionposts'],
    ];

    /** @var string[] The completion rules the service may send besides none. */
    private const RULES = ['view', 'grade', 'submit', 'post'];

    /**
     * Apply the hidden state and the completion rules of the created activities.
     *
     * @param int $courseid The new course.
     * @param array $activities The result's generated activities.
     * @param array $generatedcms Activity key (generated_activity_key) => created cmid.
     * @return void
     */
    public static function apply(int $courseid, array $activities, array $generatedcms): void {
        $planned = self::planned($activities, guideline_cm_map::by_uid($activities, $generatedcms));
        if (!$planned) {
            return;
        }
        $completion = self::course_completion_ready($courseid, $planned);
        self::apply_planned($courseid, $planned, $completion);
        rebuild_course_cache($courseid, true);
    }

    /**
     * The activities that carry a hidden state or a rule, with their created course module.
     *
     * @param array $activities
     * @param int[] $cmids Activity uid => course module id.
     * @return array[] Each with 'cmid', 'hidden' (bool) and 'rule' (string).
     */
    private static function planned(array $activities, array $cmids): array {
        $planned = [];
        foreach ($activities as $activity) {
            $uid = (string) ($activity['uid'] ?? '');
            $item = self::plan_of((array) ($activity['parameters'] ?? []));
            if ($item === null || !isset($cmids[$uid])) {
                continue;
            }
            $item['cmid'] = $cmids[$uid];
            $planned[] = $item;
        }
        return $planned;
    }

    /**
     * The hidden state and rule of one activity's parameters, or null when it carries neither.
     *
     * @param array $parameters
     * @return array|null
     */
    private static function plan_of(array $parameters): ?array {
        $hidden = array_key_exists('visible', $parameters) && $parameters['visible'] === false;
        $rule = (string) ($parameters['completion_rule'] ?? 'none');
        if (!in_array($rule, self::RULES, true)) {
            $rule = 'none';
        }
        if (!$hidden && $rule === 'none') {
            return null;
        }
        return ['hidden' => $hidden, 'rule' => $rule];
    }

    /**
     * Whether the completion rules can be written: any rule needs completion on in the site and the course.
     *
     * @param int $courseid
     * @param array[] $planned
     * @return bool
     */
    private static function course_completion_ready(int $courseid, array $planned): bool {
        global $CFG, $DB;
        $ruled = array_column($planned, 'rule');
        $rules = array_diff($ruled, ['none']);
        if (!$rules) {
            return false;
        }
        if (empty($CFG->enablecompletion)) {
            debugging('local_coursegen: completion rules skipped, completion tracking is disabled in the site.');
            return false;
        }
        $DB->set_field('course', 'enablecompletion', 1, ['id' => $courseid]);
        return true;
    }

    /**
     * Apply every planned item.
     *
     * @param int $courseid
     * @param array[] $planned
     * @param bool $completion Whether the completion rules can be written.
     * @return void
     */
    private static function apply_planned(int $courseid, array $planned, bool $completion): void {
        foreach ($planned as $item) {
            $cm = get_coursemodule_from_id('', $item['cmid'], $courseid);
            if (!$cm) {
                continue;
            }
            if ($item['hidden']) {
                set_coursemodule_visible($cm->id, 0);
            }
            if ($completion && $item['rule'] !== 'none') {
                self::apply_rule($cm, $item['rule']);
            }
        }
    }

    /**
     * Write one completion rule on a course module.
     *
     * @param \stdClass $cm The course module with its modname.
     * @param string $rule
     * @return void
     */
    private static function apply_rule(\stdClass $cm, string $rule): void {
        if ($rule === 'grade' && !self::has_grade_item($cm)) {
            debugging('local_coursegen: ' . $cm->modname . ' ' . $cm->id . ' has no grade item, tracking its view instead.');
            $rule = 'view';
        }
        if ($rule === 'view') {
            self::write_core_rule($cm, ['completionview' => 1]);
            return;
        }
        if ($rule === 'grade') {
            self::write_core_rule($cm, ['completiongradeitemnumber' => 0]);
            return;
        }
        self::write_custom_rule($cm, $rule);
    }

    /**
     * Whether the module instance has a grade item of its own.
     *
     * @param \stdClass $cm
     * @return bool
     */
    private static function has_grade_item(\stdClass $cm): bool {
        global $DB;
        return $DB->record_exists('grade_items', [
            'courseid' => $cm->course,
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
            'itemnumber' => 0,
        ]);
    }

    /**
     * Write automatic completion with a rule kept in the course module row.
     *
     * @param \stdClass $cm
     * @param array $fields The rule columns of course_modules.
     * @return void
     */
    private static function write_core_rule(\stdClass $cm, array $fields): void {
        global $DB;
        if ($fields === ['completionview' => 1] && !plugin_supports('mod', $cm->modname, FEATURE_COMPLETION_TRACKS_VIEWS, false)) {
            debugging('local_coursegen: ' . $cm->modname . ' cannot track views, completion rule skipped.');
            return;
        }
        $columns = array_merge($fields, ['id' => $cm->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC]);
        $row = (object) $columns;
        $DB->update_record('course_modules', $row);
    }

    /**
     * Write automatic completion with the rule column the module keeps in its own table.
     *
     * @param \stdClass $cm
     * @param string $rule submit or post.
     * @return void
     */
    private static function write_custom_rule(\stdClass $cm, string $rule): void {
        global $DB;
        $column = self::CUSTOM_COLUMNS[$rule][$cm->modname] ?? null;
        if ($column === null || !$DB->get_manager()->field_exists($cm->modname, new \xmldb_field($column))) {
            debugging('local_coursegen: ' . $cm->modname . ' does not support the ' . $rule . ' completion rule, skipped.');
            return;
        }
        $DB->set_field($cm->modname, $column, 1, ['id' => $cm->instance]);
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_AUTOMATIC, ['id' => $cm->id]);
    }
}
