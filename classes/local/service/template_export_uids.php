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
     * An instance is the one element of a generation with no course module of
     * its own, so it has nothing to be called by, and every id invented for it
     * so far was really some other activity's: first a course module id offset
     * by 900000 to be improbable, then the same id negated to be impossible.
     * Both were a row id in a costume.
     *
     * It is stored rather than derived because it has to be the same string in
     * two different requests - the page that draws the link, and the payload
     * the answer comes back against - and those cannot agree on something
     * generated in either of them.
     *
     * @param template_instance $instance
     * @return string A UUID.
     */
    public static function instance_uid(template_instance $instance): string {
        $uid = (string) $instance->get('uid');
        if ($uid !== '') {
            return $uid;
        }

        // A row from before the column existed and missed by the upgrade's
        // backfill. Naming it here rather than returning an empty string keeps
        // the two requests agreeing, which is the whole point of storing it.
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
     * A fresh random name on every export looked the same and was not: it
     * sent the reader to a preview that could not find what it had just been
     * asked for.
     *
     * The result is a UUID (RFC 4122, version 5): a name hashed from a
     * namespace of this plugin's own and the element's identity.
     *
     * @param int $templateid
     * @param string $kind 'cm' or 'section'.
     * @param int $id That element's own id within its kind.
     * @return string
     */
    public static function stable_uid(int $templateid, string $kind, int $id): string {
        // This plugin's own namespace for these names, fixed so the same
        // element always hashes to the same uid.
        $namespace = hex2bin('6f0d2a4c1b7e4a7f9c3e2d1b0a9f8e7d');
        $hash = sha1($namespace . "local_coursegen/{$templateid}/{$kind}/{$id}", true);
        $bytes = substr($hash, 0, 16);
        // Version 5 in the high nibble of byte 6; RFC 4122 variant in byte 8.
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    /**
     * The id one virtual instance travels under.
     *
     * Negative, because an instance is not a course module and has no id of its
     * own in that space: every real cmid is positive, so a negative one cannot
     * be mistaken for one, and no constant has to be chosen high enough to stay
     * out of their way. Which is what the previous base of 900000 was, a number
     * picked to be improbable, leaking into URLs and quietly waiting for a site
     * large enough to reach it.
     *
     * @param int $instanceid
     * @return int
     */
    public static function instance_cmid(int $instanceid): int {
        return -$instanceid;
    }
}
