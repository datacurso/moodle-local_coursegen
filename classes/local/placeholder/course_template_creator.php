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

namespace local_coursegen\local\placeholder;

use local_coursegen\external\save_template;
use local_coursegen\local\models\template;
use local_coursegen\local\models\template_activity;
use local_coursegen\local\models\template_instance;
use local_coursegen\local\models\template_section;
use local_coursegen\local\models\template_space;

/**
 * Makes the template of a course whose activities carry placeholders.
 *
 * The template gets the name of the course. Every activity with placeholders is marked "use as template" and gets one
 * instance; the rest are kept. Making the template again for the same course and name replaces the one that exists.
 * The template is saved through the same web service function the editor page uses, so it gets the same validation.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_template_creator {
    /** @var string[] The tables of the template that must exist. */
    private const TABLES = [
        template::TABLE,
        template_section::TABLE,
        template_activity::TABLE,
        template_instance::TABLE,
        template_space::TABLE,
    ];

    /**
     * Work out what would be saved, without saving anything.
     *
     * @param int $courseid The course whose activities carry placeholders, e.g. 646.
     * @param array $options {name: string|null, scope: 'course'|'section', replace: bool, allowempty: bool}.
     * @return array {courseid, coursename, name, scope, sections, molds, instances, kept, unsupported, problems, hidden,
     *     warnings, existing}
     * @throws template_creation_exception When no template can be made from the course.
     */
    public static function plan(int $courseid, array $options = []): array {
        $settings = self::settings($options);
        $course = self::require_course($courseid);
        self::require_tables();
        $read = course_structure_reader::read($course);
        if ($read['activities'] === 0) {
            throw new template_creation_exception(
                'The course ' . $courseid . ' has no activities.',
                template_creation_exception::NO_ACTIVITIES
            );
        }
        $plan = template_plan_builder::build($read['structure'], $settings['scope']);
        if ($plan['molds'] === [] && !$settings['allowempty']) {
            throw new template_creation_exception(
                'No activity of the course ' . $courseid . ' has placeholders; nothing to make a template from.',
                template_creation_exception::NO_PLACEHOLDERS
            );
        }
        $named = self::template_name($course, $settings['name']);
        $existing = self::existing_ids($courseid, $named['name']);
        $instances = count($plan['molds']);
        $warnings = self::warnings($named['warnings'], $plan, $read);

        return [
            'courseid' => $courseid,
            'coursename' => (string) $course->fullname,
            'name' => $named['name'],
            'scope' => $settings['scope'],
            'sections' => $plan['sections'],
            'molds' => $plan['molds'],
            'instances' => $instances,
            'kept' => $plan['kept'],
            'unsupported' => $plan['unsupported'],
            'problems' => $read['problems'],
            'hidden' => $read['hidden'],
            'warnings' => $warnings,
            'existing' => $existing,
        ];
    }

    /**
     * Make the template, or replace the one that exists for this course and name.
     *
     * @param int $courseid The course whose activities carry placeholders, e.g. 646.
     * @param array $options {name: string|null, scope: 'course'|'section', replace: bool, allowempty: bool}.
     * @return array The plan plus {templateid, replaced, saved}.
     * @throws template_creation_exception When no template can be made or the one that exists cannot be replaced.
     */
    public static function create(int $courseid, array $options = []): array {
        $settings = self::settings($options);
        $plan = self::plan($courseid, $options);
        $target = self::target_id($plan['existing'], $settings['replace'], $plan['name']);
        $kept = self::settings_to_keep($target);
        if ($target === 0) {
            $kept['description'] = self::description_for($plan);
        }
        $templateid = self::save($target, $plan, $kept);

        $plan['templateid'] = $templateid;
        $plan['replaced'] = $target > 0;
        $plan['saved'] = true;
        return $plan;
    }

    /**
     * The options with their defaults.
     *
     * @param array $options What the caller gave.
     * @return array {name, scope, replace, allowempty}
     */
    private static function settings(array $options): array {
        $defaults = [
            'name' => null,
            'scope' => template_plan_builder::SCOPE_COURSE,
            'replace' => true,
            'allowempty' => false,
        ];
        return array_merge($defaults, $options);
    }

    /**
     * The course, or a refusal when it does not exist or is the site home.
     *
     * @param int $courseid The id.
     * @return \stdClass
     * @throws template_creation_exception When there is no such course.
     */
    private static function require_course(int $courseid): \stdClass {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course || $courseid === SITEID) {
            throw new template_creation_exception(
                'The course ' . $courseid . ' does not exist.',
                template_creation_exception::COURSE_NOT_FOUND
            );
        }
        return $course;
    }

    /**
     * Refuse when a table of the template is not in the database.
     *
     * @throws template_creation_exception When one is missing: this site has another version of the plugin.
     */
    private static function require_tables(): void {
        global $DB;

        $manager = $DB->get_manager();
        foreach (self::TABLES as $table) {
            if (!$manager->table_exists($table)) {
                throw new template_creation_exception(
                    'The table ' . $table . ' does not exist: this site does not have the template tables this script saves to.',
                    template_creation_exception::TABLES_MISSING
                );
            }
        }
    }

    /**
     * The name of the template: the one asked for or the name of the course, as plain text of at most 255 characters.
     *
     * @param \stdClass $course The course.
     * @param string|null $override The name the caller asked for, or null for the name of the course.
     * @return array {name, warnings}
     * @throws template_creation_exception When nothing is left of the name.
     */
    private static function template_name(\stdClass $course, ?string $override): array {
        $raw = $override;
        if ($raw === null) {
            $raw = (string) $course->fullname;
        }
        $text = strip_tags($raw);
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $single = preg_replace('/\s+/u', ' ', $decoded);
        $name = trim((string) $single);
        if ($name === '') {
            throw new template_creation_exception(
                'The name of the template would be empty.',
                template_creation_exception::NAME_EMPTY
            );
        }
        $warnings = [];
        $length = mb_strlen($name);
        if ($length > template_plan_builder::NAME_LENGTH) {
            $name = mb_substr($name, 0, template_plan_builder::NAME_LENGTH);
            $warnings[] = 'The name was cut to ' . template_plan_builder::NAME_LENGTH . ' characters.';
        }
        return ['name' => $name, 'warnings' => $warnings];
    }

    /**
     * The ids of the templates that already have this name for this course.
     *
     * @param int $courseid The course.
     * @param string $name The name.
     * @return int[]
     */
    private static function existing_ids(int $courseid, string $name): array {
        $records = template::get_records(['name' => $name, 'courseid' => $courseid], 'id');
        $ids = [];
        foreach ($records as $record) {
            $ids[] = (int) $record->get('id');
        }
        return $ids;
    }

    /**
     * Which template to save over: none, the only one that exists, or a refusal.
     *
     * @param int[] $existing Ids of the templates with this name for this course.
     * @param bool $replace Whether the one that exists may be replaced.
     * @param string $name The name, for the message.
     * @return int The id to replace, 0 to make a new one.
     * @throws template_creation_exception When there are several, or one that may not be replaced.
     */
    private static function target_id(array $existing, bool $replace, string $name): int {
        if ($existing === []) {
            return 0;
        }
        if (count($existing) > 1) {
            $ids = implode(', ', $existing);
            throw new template_creation_exception(
                'Several templates are called "' . $name . '" for this course (ids ' . $ids . '); delete the extra ones.',
                template_creation_exception::AMBIGUOUS
            );
        }
        if (!$replace) {
            throw new template_creation_exception(
                'The template "' . $name . '" already exists (id ' . $existing[0] . ') and replacing it is turned off.',
                template_creation_exception::ALREADY_EXISTS
            );
        }
        return $existing[0];
    }

    /**
     * What a replaced template keeps of itself: its description and its section settings.
     *
     * @param int $templateid The template to replace, 0 for a new one.
     * @return array {description, maxsections, nolimit, namingpattern, namingstart}
     */
    private static function settings_to_keep(int $templateid): array {
        $kept = ['description' => '', 'maxsections' => 0, 'nolimit' => false, 'namingpattern' => '', 'namingstart' => 1];
        if ($templateid === 0) {
            return $kept;
        }
        $existing = new template($templateid);
        $kept['description'] = (string) $existing->get('description');
        $kept['maxsections'] = (int) $existing->get('maxsections');
        $kept['nolimit'] = (bool) $existing->get('nolimit');
        $kept['namingpattern'] = (string) $existing->get('namingpattern');
        $kept['namingstart'] = (int) $existing->get('namingstart');
        return $kept;
    }

    /**
     * The description of a template made by this script.
     *
     * @param array $plan The plan.
     * @return string
     */
    private static function description_for(array $plan): string {
        $strings = (object) ['course' => $plan['coursename'], 'courseid' => $plan['courseid'], 'molds' => $plan['instances']];
        return get_string('template_from_placeholders_description', 'local_coursegen', $strings);
    }

    /**
     * Save the template in one transaction, through the web service function the editor page calls.
     *
     * @param int $templateid The template to replace, 0 for a new one.
     * @param array $plan The plan.
     * @param array $kept {description, maxsections, nolimit, namingpattern, namingstart}.
     * @return int The id of the saved template.
     * @throws \Throwable Whatever the save raised, after the transaction was rolled back.
     */
    private static function save(int $templateid, array $plan, array $kept): int {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $saved = save_template::execute(
                $templateid,
                $plan['name'],
                $kept['description'],
                $plan['courseid'],
                $kept['maxsections'],
                $kept['nolimit'],
                $kept['namingpattern'],
                $kept['namingstart'],
                $plan['sections']
            );
        } catch (\Throwable $error) {
            $transaction->rollback($error);
        }
        $transaction->allow_commit();
        return (int) $saved['id'];
    }

    /**
     * The warnings to show: what was cut, types that cannot be molds, malformed and hidden markers.
     *
     * @param string[] $namewarnings Warnings about the name.
     * @param array $plan The plan.
     * @param array $read What was read from the course.
     * @return string[]
     */
    private static function warnings(array $namewarnings, array $plan, array $read): array {
        $unsupported = self::unsupported_warnings($plan['unsupported']);
        $malformed = self::malformed_warnings($read['problems']);
        $warnings = array_merge($namewarnings, $unsupported, $malformed);
        if ($read['hidden'] > 0) {
            $warnings[] = $read['hidden'] . ' placeholder(s) sit inside a comment, a script or a style.';
        }
        return $warnings;
    }

    /**
     * One warning per activity with placeholders whose type cannot be a template.
     *
     * @param array[] $unsupported Each {cmid, modname, name}.
     * @return string[]
     */
    private static function unsupported_warnings(array $unsupported): array {
        $warnings = [];
        foreach ($unsupported as $activity) {
            $warnings[] = 'The activity ' . $activity['cmid'] . ' (' . $activity['modname']
                . ') has placeholders but this type cannot be a template; it is kept.';
        }
        return $warnings;
    }

    /**
     * One warning per malformed marker.
     *
     * @param string[] $problems What the scan reported.
     * @return string[]
     */
    private static function malformed_warnings(array $problems): array {
        $warnings = [];
        foreach ($problems as $problem) {
            $warnings[] = 'Malformed marker: ' . $problem;
        }
        return $warnings;
    }
}
