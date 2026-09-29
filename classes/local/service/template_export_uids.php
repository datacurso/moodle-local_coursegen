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
 * How every element of a template's export payload is named and identified,
 * stably, across two requests that cannot agree on something generated in
 * either of them: the page that draws a link to an element, and the page
 * that answers that link.
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
     * The name an element of the template's course travels under, every time.
     *
     * An instance stores its uid, because it is a row of ours. A real activity
     * or section is not: it belongs to Moodle, and there is nowhere of ours to
     * keep a name for it. So its name is derived, from what it is and which
     * template it is being read for, and the same element is named the same
     * way on every export. That is what lets the page that draws a link to it
     * and the page that answers that link, two requests apart, agree.
     *
     * $templateid, $kind and $id are already a unique, stable key on their
     * own - $kind alone tells apart the two id spaces that would otherwise
     * collide. Nothing reads this value expecting a UUID's shape: every
     * caller takes it through PARAM_ALPHANUMEXT, so the key itself, joined
     * by dashes, is exactly what is needed here.
     *
     * @param int $templateid
     * @param string $kind 'cm' or 'section'.
     * @param int $id That element's own id within its kind.
     * @return string
     */
    public static function stable_uid(int $templateid, string $kind, int $id): string {
        return "{$kind}-{$templateid}-{$id}";
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
