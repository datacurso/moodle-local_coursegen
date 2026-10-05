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
    /** Row type: a real activity of the base course. */
    const TYPE_REAL = 'real';

    /** Row type: a virtual instance created from a template activity. */
    const TYPE_INSTANCE = 'instance';

    /** Row type: a virtual space the professor fills. */
    const TYPE_SPACE = 'space';

    /** Group key of the virtual rows whose anchor activity no longer exists. */
    const ANCHOR_ORPHAN = 'orphan';

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

        $rows = self::group_rows($groups, 0);
        foreach ($realcmids as $cmid) {
            $rows[] = ['type' => self::TYPE_REAL, 'cmid' => $cmid];
            $anchored = self::group_rows($groups, $cmid);
            $rows = array_merge($rows, $anchored);
        }
        $orphans = self::group_rows($groups, self::ANCHOR_ORPHAN);
        $rows = array_merge($rows, $orphans);

        return $rows;
    }

    /**
     * The row entries of one anchor group, none when the group does not exist.
     *
     * @param array $groups Groups keyed by aftercmid, 0, or "orphan".
     * @param int|string $key The group to wrap.
     * @return array
     */
    private static function group_rows(array $groups, $key): array {
        $group = $groups[$key] ?? [];
        return self::instance_rows($group);
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
            $key = self::ANCHOR_ORPHAN;
            if ($anchor === 0 || isset($validanchors[$anchor])) {
                $key = $anchor;
            }
            $groups[$key][] = $instance;
        }
        return self::sort_groups($groups);
    }

    /**
     * Order the rows inside every anchor group by their shared sortorder.
     *
     * @param array $groups Groups keyed by aftercmid, 0, or "orphan".
     * @return array The same groups, each one sorted.
     */
    private static function sort_groups(array $groups): array {
        foreach ($groups as $key => $group) {
            $groups[$key] = self::sort_group($group);
        }
        return $groups;
    }

    /**
     * Order one group of rows by their sortorder.
     *
     * @param array $group Instances and spaces.
     * @return array
     */
    private static function sort_group(array $group): array {
        usort($group, [self::class, 'compare_by_sortorder']);
        return $group;
    }

    /**
     * Compare two virtual rows by their sortorder.
     *
     * @param template_instance|template_space $first
     * @param template_instance|template_space $second
     * @return int
     */
    private static function compare_by_sortorder($first, $second): int {
        $firstorder = $first->get('sortorder');
        $secondorder = $second->get('sortorder');
        return $firstorder <=> $secondorder;
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
        $type = self::TYPE_INSTANCE;
        if ($record instanceof template_space) {
            $type = self::TYPE_SPACE;
        }
        return ['type' => $type, 'record' => $record];
    }
}
