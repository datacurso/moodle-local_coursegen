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

use local_coursegen\local\models\template_activity;
use local_coursegen\local\models\template_instance;
use local_coursegen\local\models\template_section;
use local_coursegen\output\template_row_options;

/**
 * Replaces a template's saved section/activity/instance configuration.
 *
 * Split out of classes/external/save_template.php so that class stays a
 * thin external-API adapter (parameter validation, capability check) and
 * this one owns the actual persistence: every save fully deletes and
 * recreates a template's child records rather than diffing them in place.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_persistence_service {
    /**
     * Replace every section/activity/instance record for a template.
     *
     * @param int $templateid
     * @param array $sections Each with sectionid, sectionnum, behavior,
     *     activities array, and an optional instances array — the exact
     *     shape save_template::execute_parameters() validates.
     */
    public static function save_sections(int $templateid, array $sections): void {
        self::delete_children($templateid);

        foreach ($sections as $sectiondata) {
            $sec = new template_section(0);
            $sec->set('templateid', $templateid);
            $sec->set('sectionid', $sectiondata['sectionid']);
            $sec->set('sectionnum', $sectiondata['sectionnum']);
            $sec->set('behavior', $sectiondata['behavior']);
            $sec->create();

            foreach ($sectiondata['activities'] as $actdata) {
                self::create_activity($templateid, $sectiondata['sectionid'], $actdata);
            }
            foreach (($sectiondata['instances'] ?? []) as $instdata) {
                self::create_instance($templateid, $sectiondata['sectionid'], $instdata);
            }
        }
    }

    /**
     * Delete every existing activity, section, and instance record for a template.
     *
     * @param int $templateid
     */
    private static function delete_children(int $templateid): void {
        foreach (template_activity::get_records(['templateid' => $templateid]) as $record) {
            $record->delete();
        }
        foreach (template_section::get_records(['templateid' => $templateid]) as $record) {
            $record->delete();
        }
        foreach (template_instance::get_records(['templateid' => $templateid]) as $record) {
            $record->delete();
        }
    }

    /**
     * Persist one real activity's saved configuration.
     *
     * @param int $templateid
     * @param int $sectionid
     * @param array $actdata
     */
    private static function create_activity(int $templateid, int $sectionid, array $actdata): void {
        $act = new template_activity(0);
        $act->set('templateid', $templateid);
        $act->set('sectionid', $sectionid);
        $act->set('cmid', $actdata['cmid']);
        $act->set('action', $actdata['action']);
        $act->set('useasreference', (int) $actdata['useasreference']);
        $act->set('templatescope', self::normalise_scope($actdata['templatescope'] ?? 'course'));
        $act->set('prompt', $actdata['prompt']);
        $act->create();
    }

    /**
     * Persist one virtual instance's saved configuration.
     *
     * @param int $templateid
     * @param int $sectionid
     * @param array $instdata
     */
    private static function create_instance(int $templateid, int $sectionid, array $instdata): void {
        $instance = new template_instance(0);
        $instance->set('templateid', $templateid);
        $instance->set('sectionid', $sectionid);
        $instance->set('sourcecmid', $instdata['sourcecmid']);
        $instance->set('sourcename', $instdata['sourcename']);
        $instance->set('name', $instdata['name']);
        $instance->set('typelabel', $instdata['typelabel']);
        $instance->set('modname', $instdata['modname'] ?? '' ?: null);
        $instance->set('prompt', $instdata['prompt'] ?? '');
        $instance->set('anchorcmid', $instdata['anchorcmid'] ?? 0);
        $instance->set('sortorder', $instdata['sortorder'] ?? 0);
        $instance->create();
    }

    /**
     * Fall back an unrecognised template scope to "course" instead of
     * persisting whatever a client sent — mirrors
     * template_row_options::template_scope_options()'s own fallback so a row
     * can never be saved with a scope value it would not even render.
     *
     * @param string $scope
     * @return string
     */
    private static function normalise_scope(string $scope): string {
        if (in_array($scope, template_row_options::SCOPE_VALUES, true)) {
            return $scope;
        }
        return 'course';
    }
}
