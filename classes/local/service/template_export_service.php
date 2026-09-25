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

use local_coursegen\local\models\template;
use local_coursegen\local\models\template_activity;
use local_coursegen\local\models\template_instance;
use local_coursegen\local\models\template_section;

/**
 * Builds the /course-template/init payload for one saved template.
 *
 * Two kinds of entry travel in "activities": the base course's own real
 * activities (carrying the admin's saved action - keep/reference/template),
 * and the template's virtual instances, which have no course module of their
 * own and are submitted as action="modify" driven by the mold they were
 * created from (template_source_cmid).
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_export_service {
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

    /**
     * Build the full init payload.
     *
     * @param int $templateid
     * @param string $generalinstruction The professor's single prompt/syllabus instruction.
     * @return array
     */
    public static function build_init_payload(int $templateid, string $generalinstruction = ''): array {
        global $CFG;

        $template = template::get_record(['id' => $templateid]);
        if (!$template) {
            throw new \moodle_exception('invalidtemplate', 'local_coursegen');
        }

        $course = get_course($template->get('courseid'));
        $modinfo = get_fast_modinfo($course);

        $actions = self::saved_activity_actions($templateid);
        $behaviors = self::saved_section_behaviors($templateid);
        $lang = $course->lang;
        if (empty($lang)) {
            $lang = current_language();
        }

        return [
            'course_configuration' => [
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'lang' => $lang,
                'numsections' => count($modinfo->get_section_info_all()) - 1,
                // How the course is laid out, and how its format is set up.
                // A course generated from a template is the template's course
                // with other content in it, so it is read the same way, and a
                // preview of it has to be drawn the same way.
                'format' => $course->format,
                'format_options' => self::format_settings($course),
            ],
            'sections_info' => self::sections_info($templateid, $course, $modinfo, $behaviors),
            'activities' => array_merge(
                self::real_activities($templateid, $modinfo, $actions),
                self::instance_activities($templateid, $modinfo)
            ),
            'general_instruction' => $generalinstruction,
            'lang' => $lang,
            'with_images' => false,
            'site_url' => $CFG->wwwroot,
            // Reference files are attached after /init (they need its
            // thread_id), so the run must not start until they have landed.
            'defer_start' => true,
        ];
    }

    /**
     * cmid => saved action, for this template.
     *
     * @param int $templateid
     * @return array
     */
    private static function saved_activity_actions(int $templateid): array {
        $actions = [];
        foreach (template_activity::get_records(['templateid' => $templateid]) as $activity) {
            $actions[(int) $activity->get('cmid')] = $activity->get('action');
        }
        return $actions;
    }

    /**
     * sectionid => saved behavior, for this template.
     *
     * @param int $templateid
     * @return array
     */
    private static function saved_section_behaviors(int $templateid): array {
        $behaviors = [];
        foreach (template_section::get_records(['templateid' => $templateid]) as $section) {
            $behaviors[(int) $section->get('sectionid')] = $section->get('behavior');
        }
        return $behaviors;
    }

    /**
     * Every section, with its saved behavior merged in.
     *
     * @param \stdClass $course
     * @param \course_modinfo $modinfo
     * @param array $behaviors
     * @return array
     */
    private static function sections_info(int $templateid, $course, $modinfo, array $behaviors): array {
        $format = course_get_format($course);
        $images = self::section_images($course);
        $contextid = \context_course::instance($course->id)->id;

        $sections = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            $sections[] = [
                'uid' => self::stable_uid($templateid, 'section', (int) $section->id),
                'section' => (int) $section->section,
                'name' => get_section_name($course, $section),
                // A summary refers to its pictures by a placeholder that only
                // means something to the page that owns them. Whatever reads
                // this payload owns nothing, so they travel as addresses.
                'summary' => file_rewrite_pluginfile_urls(
                    (string) ($section->summary ?? ''),
                    'pluginfile.php',
                    $contextid,
                    'course',
                    'section',
                    (int) $section->id
                ),
                'summaryformat' => (int) ($section->summaryformat ?? FORMAT_HTML),
                // What the format was told about this section in particular,
                // which is where a format keeps the look of it.
                'format_options' => $format->get_format_options($section),
                'image' => $images[(int) $section->id] ?? null,
                'template_behavior' => ['behavior' => $behaviors[$section->id] ?? 'aimodify'],
            ];
        }
        return $sections;
    }

    /**
     * How the course's format is set up, with its defaults already resolved.
     *
     * A format option left alone is stored empty and means "whatever the site
     * says", so a payload carrying it raw describes a course laid out at zero
     * width. A format that resolves its own settings is asked to.
     *
     * @param \stdClass $course
     * @return array
     */
    private static function format_settings($course): array {
        $format = course_get_format($course);
        if (method_exists($format, 'get_settings')) {
            return (array) $format->get_settings();
        }
        return $format->get_format_options();
    }

    /**
     * The picture each section is shown with, where its format gives it one.
     *
     * A format can put a picture on a section, and for the formats that do it
     * is most of what the course looks like. The picture is a file on this
     * site, so what travels is where it can be read from.
     *
     * @param \stdClass $course
     * @return array Section id => image address.
     */
    private static function section_images($course): array {
        global $DB, $CFG;

        if ($course->format !== 'grid' || !file_exists($CFG->dirroot . '/course/format/grid/classes/toolbox.php')) {
            return [];
        }

        $toolbox = \format_grid\toolbox::get_instance();
        $contextid = \context_course::instance($course->id)->id;
        $webp = (get_config('format_grid', 'defaultdisplayedimagefiletype') == 2);

        $images = [];
        foreach ($DB->get_records('format_grid_image', ['courseid' => $course->id]) as $image) {
            if ((int) $image->displayedimagestate < 1) {
                continue;
            }
            $uri = $toolbox->get_displayed_image_uri($image, $contextid, (int) $image->sectionid, $webp);
            $address = (string) $uri;
            if ($uri instanceof \moodle_url) {
                $address = $uri->out(false);
            }
            $images[(int) $image->sectionid] = $address;
        }
        return $images;
    }

    /**
     * The base course's own activities, excluding the ones marked "exclude".
     *
     * @param \course_modinfo $modinfo
     * @param array $actions
     * @return array
     */
    private static function real_activities(int $templateid, $modinfo, array $actions): array {
        $activities = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $action = $actions[$cm->id] ?? 'keep';
            if ($action === 'exclude') {
                continue;
            }
            $activities[] = [
                'resource_type' => $cm->modname,
                'uid' => self::stable_uid($templateid, 'cm', (int) $cm->id),
                'cmid' => (int) $cm->id,
                'parameters' => template_activity_export::parameters_for($cm),
                'template_behavior' => ['action' => $action, 'useasreference' => true],
            ];
        }
        return $activities;
    }

    /**
     * The template's virtual instances, as "modify" activities driven by their mold.
     *
     * @param int $templateid
     * @param \course_modinfo $modinfo
     * @return array
     */
    private static function instance_activities(int $templateid, $modinfo): array {
        $sectionnums = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            $sectionnums[(int) $section->id] = (int) $section->section;
        }

        $activities = [];
        $instances = template_instance::get_records(['templateid' => $templateid], 'sortorder');
        foreach ($instances as $instance) {
            $resourcetype = $instance->get('modname');
            if (empty($resourcetype)) {
                $resourcetype = 'lesson';
            }
            $activities[] = [
                'resource_type' => $resourcetype,
                'uid' => self::instance_uid($instance),
                'cmid' => self::instance_cmid((int) $instance->get('id')),
                'parameters' => [
                    'name' => $instance->get('name'),
                    'section' => $sectionnums[(int) $instance->get('sectionid')] ?? 0,
                ],
                'template_behavior' => [
                    'action' => 'modify',
                    'useasreference' => true,
                    'prompt' => (string) $instance->get('prompt'),
                    'template_source_cmid' => (int) $instance->get('sourcecmid'),
                ],
            ];
        }
        return $activities;
    }
}
