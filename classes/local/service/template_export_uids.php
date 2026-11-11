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

use local_coursegen\local\models\template_instance;

/**
 * How every element of a template's export payload is named and identified.
 *
 * Two different lifetimes need two different strategies. A template instance
 * (template_instance) is a row of ours that outlives any one export: it can be
 * read again by a later, unrelated build, so its name has to survive between
 * them and is persisted in the row itself. A real activity or section carries
 * no row of ours at all, but it no longer needs to survive between builds
 * either - the payload that names it is built exactly once per generation and
 * then read back from that same stored copy for as long as the run lasts, so
 * a name generated fresh at that one point in time is already exactly as
 * stable as anything reading it will ever need.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_export_uids {
    /**
     * The name one instance travels under, everywhere it is named.
     *
     * Stored rather than derived: it has to be the same string in two
     * different requests - the page that draws a link to it, and the payload
     * the answer comes back against - and those cannot agree on something
     * generated fresh in either one.
     *
     * @param template_instance $instance
     * @return string A UUID.
     */
    public static function instance_uid(template_instance $instance): string {
        $uid = (string) $instance->get('uid');
        if ($uid !== '') {
            return $uid;
        }

        // No uid stored yet: name it now and persist it, so every later
        // read of this row agrees on the same name.
        $uid = \core\uuid::generate();
        $instance->set('uid', $uid);
        $instance->update();
        return $uid;
    }

    /**
     * The name a real activity or section travels under, for this one export.
     *
     * Nothing of ours to store this against, and nothing needed: the payload
     * that carries this name is built once and read back from that same
     * stored copy for the rest of the run, never rebuilt, so a fresh random
     * name at build time is read consistently without being remembered
     * anywhere of our own.
     *
     * @return string A UUID.
     */
    public static function random_uid(): string {
        return \core\uuid::generate();
    }

    /**
     * The id one virtual instance travels under.
     *
     * Negative, because an instance is not a course module and has no id of
     * its own in that space: every real cmid is positive, so a negative one
     * can never be mistaken for one.
     *
     * @param int $instanceid
     * @return int
     */
    public static function instance_cmid(int $instanceid): int {
        return -$instanceid;
    }
}
