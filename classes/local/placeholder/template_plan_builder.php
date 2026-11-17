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

use local_coursegen\local\ai_activity_types;

/**
 * Builds the sections a template saves from the sections of a course and what was found in their activities.
 *
 * An activity with placeholders is marked "use as template" and gets one instance placed right after it; the others are
 * kept. A section that holds an instance may be modified by the AI, the rest are kept as they are. The keys of the
 * result are the ones the save service of the template reads.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_plan_builder {
    /** @var string The action of an activity the AI does not touch (template_activity::ACTION_KEEP). */
    public const ACTION_KEEP = 'keep';

    /** @var string The action of an activity used as a mold (template_activity::ACTION_TEMPLATE). */
    public const ACTION_TEMPLATE = 'template';

    /** @var string A mold any section may use (template_activity::SCOPE_COURSE). */
    public const SCOPE_COURSE = 'course';

    /** @var string A mold only its own section may use (template_activity::SCOPE_SECTION). */
    public const SCOPE_SECTION = 'section';

    /** @var string A section the AI may modify (template_section::BEHAVIOR_AI_MODIFY). */
    public const BEHAVIOR_AI_MODIFY = 'aimodify';

    /** @var string A section copied as it is (template_section::BEHAVIOR_KEEP). */
    public const BEHAVIOR_KEEP = 'keep';

    /** @var int The size of the name columns of the template tables. */
    public const NAME_LENGTH = 255;

    /**
     * Build the plan.
     *
     * @param array[] $structure The sections of the course: {sectionid, sectionnum, activities[]}, each activity
     *     {cmid, modname, name, typelabel, placeholders}.
     * @param string $scope The scope of every mold: 'course' or 'section'.
     * @return array {sections, molds, unsupported, kept}
     * @throws \invalid_parameter_exception When the scope is not one of the two.
     */
    public static function build(array $structure, string $scope): array {
        self::require_scope($scope);
        $plan = ['sections' => [], 'molds' => [], 'unsupported' => [], 'kept' => 0];
        foreach ($structure as $section) {
            $built = self::build_section($section, $scope);
            $plan['sections'][] = $built['section'];
            $plan['molds'] = array_merge($plan['molds'], $built['molds']);
            $plan['unsupported'] = array_merge($plan['unsupported'], $built['unsupported']);
            $plan['kept'] += $built['kept'];
        }
        return $plan;
    }

    /**
     * Refuse a scope the template does not know.
     *
     * @param string $scope The scope, e.g. 'course'.
     * @throws \invalid_parameter_exception When it is not 'course' or 'section'.
     */
    private static function require_scope(string $scope): void {
        $known = [self::SCOPE_COURSE, self::SCOPE_SECTION];
        if (!in_array($scope, $known, true)) {
            throw new \invalid_parameter_exception('The scope of a template mold is course or section: ' . $scope);
        }
    }

    /**
     * Build one section of the plan.
     *
     * @param array $section {sectionid, sectionnum, activities[]}.
     * @param string $scope The scope of the molds.
     * @return array {section, molds, unsupported, kept}
     */
    private static function build_section(array $section, string $scope): array {
        $built = ['activities' => [], 'instances' => [], 'molds' => [], 'unsupported' => [], 'kept' => 0];
        foreach ($section['activities'] as $activity) {
            $built = self::add_activity($built, $activity, $scope);
        }
        $behavior = self::BEHAVIOR_KEEP;
        if ($built['instances'] !== []) {
            $behavior = self::BEHAVIOR_AI_MODIFY;
        }
        return [
            'section' => [
                'sectionid' => (int) $section['sectionid'],
                'sectionnum' => (int) $section['sectionnum'],
                'behavior' => $behavior,
                'activities' => $built['activities'],
                'instances' => $built['instances'],
                'spaces' => [],
            ],
            'molds' => $built['molds'],
            'unsupported' => $built['unsupported'],
            'kept' => $built['kept'],
        ];
    }

    /**
     * Add one activity to a section being built.
     *
     * @param array $built The section so far.
     * @param array $activity {cmid, modname, name, typelabel, placeholders}.
     * @param string $scope The scope of the molds.
     * @return array The section with the activity added.
     */
    private static function add_activity(array $built, array $activity, string $scope): array {
        $cmid = (int) $activity['cmid'];
        $modname = (string) $activity['modname'];
        $name = self::name_of($activity);
        $hasplaceholders = $activity['placeholders'] > 0;
        $supported = in_array($modname, ai_activity_types::MODNAMES, true);

        if ($hasplaceholders && $supported) {
            $sortorder = count($built['instances']);
            $built['activities'][] = self::activity_row($cmid, self::ACTION_TEMPLATE, $scope);
            $built['instances'][] = self::instance_row($activity, $name, $sortorder);
            $built['molds'][] = $cmid;
            return $built;
        }
        if ($hasplaceholders) {
            $built['unsupported'][] = ['cmid' => $cmid, 'modname' => $modname, 'name' => $name];
        }
        $built['activities'][] = self::activity_row($cmid, self::ACTION_KEEP, self::SCOPE_COURSE);
        $built['kept']++;
        return $built;
    }

    /**
     * The row of one real activity, with the keys the save service reads.
     *
     * @param int $cmid Course module id.
     * @param string $action 'keep' or 'template'.
     * @param string $scope The scope of the mold.
     * @return array
     */
    private static function activity_row(int $cmid, string $action, string $scope): array {
        return [
            'cmid' => $cmid,
            'action' => $action,
            'useasreference' => true,
            'prompt' => '',
            'templatescope' => $scope,
            'spacerequired' => true,
            'spaceinstruction' => '',
        ];
    }

    /**
     * The instance created from a mold: it has the name and type of the mold and sits right after it.
     *
     * @param array $activity The mold.
     * @param string $name Its name, already cut to the column size.
     * @param int $sortorder How many instances the section already has, so the order is stable.
     * @return array
     */
    private static function instance_row(array $activity, string $name, int $sortorder): array {
        $typelabel = mb_substr((string) $activity['typelabel'], 0, self::NAME_LENGTH);
        return [
            'sourcecmid' => (int) $activity['cmid'],
            'sourcename' => $name,
            'name' => $name,
            'typelabel' => $typelabel,
            'modname' => (string) $activity['modname'],
            'prompt' => '',
            'aftercmid' => (int) $activity['cmid'],
            'sortorder' => $sortorder,
        ];
    }

    /**
     * The name of an activity, cut to the column size; an activity without one is named by its type and id.
     *
     * @param array $activity {cmid, modname, name}.
     * @return string
     */
    private static function name_of(array $activity): string {
        $name = trim((string) $activity['name']);
        if ($name === '') {
            $name = $activity['modname'] . ' ' . $activity['cmid'];
        }
        return mb_substr($name, 0, self::NAME_LENGTH);
    }
}
