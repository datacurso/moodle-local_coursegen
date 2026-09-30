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

use local_coursegen\local\models\template_space;

/**
 * Interleaves a section's real activity cmids with its saved virtual rows
 * (template_instance and template_space) into a single render order.
 *
 * Every instance is anchored to render immediately after a given real cmid
 * (aftercmid=0 means "the start of the section"); sortorder breaks ties
 * among instances sharing the same anchor. An instance whose aftercmid no
 * longer matches any real cmid in this section (the base course activity it
 * was placed after was removed) is appended at the very end instead of
 * being dropped, ordered after every legitimately-anchored trailing
 * instance by its own sortorder.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_instance_layout {
    /**
     * Build the final row order for one section.
     *
     * @param int[] $realcmids The section's real activity cmids, in course order.
     * @param array $instances Every saved virtual row for this section:
     *     template_instance records and template_space records, which share
     *     the anchor (aftercmid) and sortorder fields.
     * @return array Each entry is ['type' => 'real', 'cmid' => int],
     *     ['type' => 'instance', 'record' => template_instance] or
     *     ['type' => 'space', 'record' => template_space].
     */
    public static function ordered_rows(array $realcmids, array $instances): array {
        $groups = self::group_by_anchor($instances, $realcmids);

        $rows = self::instance_rows($groups[0] ?? []);
        foreach ($realcmids as $cmid) {
            $rows[] = ['type' => 'real', 'cmid' => $cmid];
            $rows = array_merge($rows, self::instance_rows($groups[$cmid] ?? []));
        }
        $rows = array_merge($rows, self::instance_rows($groups['orphan'] ?? []));

        return $rows;
    }

    /**
     * Group instances by their effective anchor: their own aftercmid when
     * it matches a real cmid in this section (or is 0, "section start"),
     * otherwise the "orphan" bucket appended at the very end.
     *
     * @param array $instances Instances and spaces.
     * @param int[] $realcmids
     * @return array<int|string, array> Keyed by aftercmid, 0, or "orphan".
     */
    private static function group_by_anchor(array $instances, array $realcmids): array {
        $validanchors = array_flip($realcmids);
        $groups = [];
        foreach ($instances as $instance) {
            $anchor = (int) $instance->get('aftercmid');
            $key = 'orphan';
            if ($anchor === 0 || isset($validanchors[$anchor])) {
                $key = $anchor;
            }
            $groups[$key][] = $instance;
        }
        foreach ($groups as $key => $group) {
            usort($group, fn($a, $b) => $a->get('sortorder') <=> $b->get('sortorder'));
            $groups[$key] = $group;
        }
        return $groups;
    }

    /**
     * Wrap a group of virtual rows into row entries.
     *
     * @param array $group Instances and spaces.
     * @return array
     */
    private static function instance_rows(array $group): array {
        $rows = [];
        foreach ($group as $key => $record) {
            $rows[$key] = self::row_entry($record);
        }
        return $rows;
    }

    /**
     * Wrap one virtual row into its row entry.
     *
     * @param template_instance|template_space $record
     * @return array {type: 'instance'|'space', record}
     */
    private static function row_entry($record): array {
        $type = 'instance';
        if ($record instanceof template_space) {
            $type = 'space';
        }
        return ['type' => $type, 'record' => $record];
    }
}
