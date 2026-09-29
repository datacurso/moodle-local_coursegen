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

namespace local_coursegen\local\preview;

/**
 * Turns one plan entry into the shape an activity preview reads.
 *
 * A run is previewable twice over, and the two moments hold the same activity
 * in different shapes. While it is being reviewed it exists as a plan: the
 * mould's own markup with a draft written into it, one entry per part. Once it
 * has been generated it exists as the module's real parameters.
 *
 * The previews are written against the second shape, because that is the shape
 * the activity is finally built from. This converts the first into it, so the
 * same preview draws the draft and the finished thing, and what the teacher
 * approves looks like what they will get for the reason that it is drawn by
 * the same code.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plan_activity {
    /**
     * The activity parameters a plan entry stands for.
     *
     * @param array $entry One entry of template_plan.
     * @return array Parameters in the same shape the generated answer uses.
     */
    public static function to_parameters(array $entry): array {
        $parts = $entry['parts'] ?? [];
        $name = $entry['name'] ?? '';
        $name = (string) $name;

        $resourcetype = $entry['resource_type'] ?? '';
        if ($resourcetype === 'lesson') {
            return self::lesson_parameters($name, $parts);
        }
        return self::flat_parameters($name, $parts);
    }

    /**
     * A lesson plan entry's parameters: one page per part.
     *
     * @param string $name
     * @param array $parts
     * @return array
     */
    private static function lesson_parameters(string $name, array $parts): array {
        $pages = [];
        foreach ($parts as $part) {
            $pages[] = self::lesson_plan_page($part);
        }
        return ['name' => $name, 'mod_settings' => ['pages' => $pages]];
    }

    /**
     * One plan part, as a lesson page.
     *
     * @param array $part
     * @return array
     */
    private static function lesson_plan_page(array $part): array {
        $key = $part['key'] ?? '';
        $key = (string) $key;
        $id = self::element_id($key);

        $title = $part['title'] ?? '';
        $title = (string) $title;

        $contenthtml = $part['html'] ?? '';
        $contenthtml = (string) $contenthtml;

        return [
            // The piece of the mould this one fills, named by the id
            // the mould's own module gave it.
            'id' => $id,
            'page_type' => 'content',
            'title' => $title,
            'content_html' => $contenthtml,
            'buttons' => [],
        ];
    }

    /**
     * A non-lesson plan entry's parameters: every part's text concatenated,
     * in the order the mould authored them.
     *
     * @param string $name
     * @param array $parts
     * @return array
     */
    private static function flat_parameters(string $name, array $parts): array {
        $html = '';
        foreach ($parts as $part) {
            $parthtml = $part['html'] ?? '';
            $parthtml = (string) $parthtml;
            $html .= $parthtml;
        }
        return [
            'name' => $name,
            'introeditor' => ['text' => $html, 'format' => FORMAT_HTML, 'itemid' => 0],
            'page' => $html,
        ];
    }

    /**
     * The mould, with what the run intends to write laid over it.
     *
     * A plan does not describe a whole activity: it describes the pieces the
     * mould offered to fill. A mould also holds pieces it offers to nobody,
     * which carry through to the delivered activity exactly as they are, so an
     * activity previewed from the plan alone is missing them and is not the
     * activity anyone will receive.
     *
     * So the mould is what is shown, with each planned piece laid over the one
     * it fills, matched by the id it carries rather than by where it sits. How
     * the reader moves between them is the mould's throughout: the plan says
     * what a piece will say and never how it is reached.
     *
     * @param array $parameters The draft, as to_parameters() built it.
     * @param string $modname
     * @param int $sourcecmid The mould this activity is written into.
     * @param \local_coursegen\local\models\course_session $session
     * @return array
     */
    public static function over_mould(
        array $parameters,
        string $modname,
        int $sourcecmid,
        \local_coursegen\local\models\course_session $session
    ): array {
        if ($modname !== 'lesson' || $sourcecmid === 0) {
            return $parameters;
        }

        // The payload the run was given, not the template's course as it
        // happens to look right now: the mould has to be the one the plan's
        // ids were matched against, not whatever the professor may have
        // edited since.
        $coursedata = $session->get('coursedata');
        $coursedata = (string) $coursedata;
        $coursedata = json_decode($coursedata, true);
        $payload = $coursedata['payload'] ?? [];
        if (!$payload) {
            return $parameters;
        }

        $mould = self::mould_pages($payload, $sourcecmid);
        if (!$mould) {
            return $parameters;
        }

        $drafted = self::drafted_pages_by_id($parameters);
        $pages = self::merged_pages($mould, $drafted);

        $parameters['mod_settings']['pages'] = $pages;
        return $parameters;
    }

    /**
     * The mould activity's own lesson pages, from the payload's activities.
     *
     * @param array $payload
     * @param int $sourcecmid
     * @return array
     */
    private static function mould_pages(array $payload, int $sourcecmid): array {
        $activities = $payload['activities'] ?? [];
        foreach ($activities as $activity) {
            $cmid = $activity['cmid'] ?? 0;
            $cmid = (int) $cmid;
            if ($cmid === $sourcecmid) {
                $activityparameters = kept_activity::to_parameters($activity);
                $modsettings = $activityparameters['mod_settings'] ?? [];
                return $modsettings['pages'] ?? [];
            }
        }
        return [];
    }

    /**
     * The drafted pages already in $parameters, keyed by the mould page id
     * each one fills.
     *
     * @param array $parameters
     * @return array
     */
    private static function drafted_pages_by_id(array $parameters): array {
        $modsettings = $parameters['mod_settings'] ?? [];
        $draftedpages = $modsettings['pages'] ?? [];
        $drafted = [];
        foreach ($draftedpages as $page) {
            $id = $page['id'] ?? '';
            $id = (string) $id;
            $drafted[$id] = $page;
        }
        return $drafted;
    }

    /**
     * The mould's pages, with any drafted page's title/content laid over the
     * one it fills.
     *
     * @param array $mould
     * @param array $drafted Page id (string) => drafted page.
     * @return array
     */
    private static function merged_pages(array $mould, array $drafted): array {
        $pages = [];
        foreach ($mould as $page) {
            $id = $page['id'] ?? '';
            $id = (string) $id;
            $draft = $drafted[$id] ?? null;
            if ($draft !== null) {
                $page['title'] = $draft['title'] ?? $page['title'];
                $page['content_html'] = $draft['content_html'] ?? $page['content_html'];
            }
            $pages[] = $page;
        }
        return $pages;
    }

    /**
     * The id inside the name a plan gives one piece of a mould.
     *
     * A piece is named for what it is and which one it is, as in "page-24399",
     * so the activity's own id for it is the tail.
     *
     * @param string $key
     * @return int|null
     */
    private static function element_id(string $key): ?int {
        $at = strrpos($key, '-');
        if ($at === false) {
            return null;
        }
        $id = substr($key, $at + 1);
        if (!ctype_digit($id)) {
            return null;
        }
        return (int) $id;
    }
}
