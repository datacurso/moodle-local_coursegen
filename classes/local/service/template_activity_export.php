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

use cm_info;

/**
 * One real activity's "parameters" for the course-template payload.
 *
 * A mold is the case that actually matters here: the AI reproduces its
 * structure page by page, so a lesson's real pages (title + content_html,
 * markers and all) must travel intact under mod_settings.pages - the shape
 * course_ai's _mold_lesson_pages() reads. Every other module type only needs
 * enough to identify and place it.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_activity_export {
    /**
     * Build one activity's parameters.
     *
     * @param cm_info $cm
     * @return array
     */
    public static function parameters_for(cm_info $cm): array {
        if ($cm->modname === 'lesson') {
            return self::lesson_parameters($cm);
        }
        if ($cm->modname === 'url') {
            return self::url_parameters($cm);
        }
        if ($cm->modname === 'resource') {
            return self::resource_parameters($cm);
        }
        return [
            'name' => $cm->name,
            'section' => (int) $cm->sectionnum,
        ];
    }

    /**
     * A File's raw description, its appearance settings and its document's
     * identity.
     *
     * The generated activity always builds a NEW document; the mold's own file
     * never travels as bytes. Its name and extension do, because the generated
     * document is produced in the same format the author chose here.
     *
     * @param cm_info $cm
     * @return array
     */
    private static function resource_parameters(cm_info $cm): array {
        global $DB;

        $resource = $DB->get_record('resource', ['id' => $cm->instance]);
        if (!$resource) {
            return ['name' => $cm->name, 'section' => (int) $cm->sectionnum];
        }

        $parameters = array_merge(
            self::resource_display_settings($resource),
            [
                'name' => $cm->name,
                'section' => (int) $cm->sectionnum,
                'intro' => $resource->intro ?? '',
            ]
        );

        $moldfile = self::resource_mold_file($cm);
        if ($moldfile !== null) {
            $parameters['moldfile'] = $moldfile;
        }

        return $parameters;
    }

    /**
     * The appearance settings worth reproducing on the generated activity.
     *
     * mod_resource stores them serialized in displayoptions and, unlike
     * mod_url, it only writes the checkbox options when they are ON (see
     * resource_set_display_options). An absent key therefore means OFF, not
     * "fall back to the site default" - reading it the other way would turn
     * on options the author deliberately left off.
     *
     * @param \stdClass $resource
     * @return array
     */
    private static function resource_display_settings($resource): array {
        $options = [];
        if (!empty($resource->displayoptions)) {
            $options = (array) unserialize_array($resource->displayoptions);
        }
        $config = get_config('resource');

        return [
            'display' => (int) ($resource->display ?? $config->display ?? 0),
            // Only meaningful for AUTO/EMBED/FRAME, where mod_resource writes it.
            'printintro' => (int) ($options['printintro'] ?? $config->printintro ?? 1),
            'showsize' => (int) ($options['showsize'] ?? 0),
            'showtype' => (int) ($options['showtype'] ?? 0),
            'showdate' => (int) ($options['showdate'] ?? 0),
            'popupwidth' => (int) ($options['popupwidth'] ?? $config->popupwidth ?? 620),
            'popupheight' => (int) ($options['popupheight'] ?? $config->popupheight ?? 450),
            'filterfiles' => (int) ($resource->filterfiles ?? $config->filterfiles ?? 0),
        ];
    }

    /**
     * The identity of the document the mold carries, or null when it has none.
     *
     * Only what the service needs to reproduce the format: never the bytes.
     *
     * @param cm_info $cm
     * @return array|null
     */
    private static function resource_mold_file(cm_info $cm): ?array {
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $cm->context->id,
            'mod_resource',
            'content',
            0,
            'sortorder DESC, id ASC',
            false
        );
        $file = reset($files);
        if (!$file) {
            return null;
        }

        $filename = $file->get_filename();
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return [
            'filename' => $filename,
            'mimetype' => (string) $file->get_mimetype(),
            'extension' => $extension,
        ];
    }

    /**
     * A URL's address, its raw description and its display settings.
     *
     * The description is the marker-bearing field the service fills in, so it
     * travels raw - formatting or filtering it here would destroy the mold.
     * The address travels verbatim too: a plain link is reused as is, while a
     * marked one tells the service to resolve it for the new course.
     *
     * @param cm_info $cm
     * @return array
     */
    private static function url_parameters(cm_info $cm): array {
        global $DB;

        $url = $DB->get_record('url', ['id' => $cm->instance]);
        if (!$url) {
            return ['name' => $cm->name, 'section' => (int) $cm->sectionnum];
        }

        return array_merge(
            self::url_display_settings($url),
            [
                'name' => $cm->name,
                'section' => (int) $cm->sectionnum,
                'intro' => $url->intro ?? '',
                'externalurl' => $url->externalurl ?? '',
            ]
        );
    }

    /**
     * The display settings worth reproducing on the generated activity.
     *
     * mod_url stores them serialized in displayoptions but rebuilds that blob
     * from flat fields on save (see url_add_instance), so the flat shape is
     * what the generated activity can actually consume. Missing entries fall
     * back to the site defaults rather than travelling as nulls.
     *
     * @param \stdClass $url
     * @return array
     */
    private static function url_display_settings($url): array {
        $options = [];
        if (!empty($url->displayoptions)) {
            $options = (array) unserialize_array($url->displayoptions);
        }
        $config = get_config('url');

        return [
            'display' => (int) ($url->display ?? $config->display ?? 0),
            'printintro' => (int) ($options['printintro'] ?? $config->printintro ?? 1),
            'popupwidth' => (int) ($options['popupwidth'] ?? $config->popupwidth ?? 620),
            'popupheight' => (int) ($options['popupheight'] ?? $config->popupheight ?? 450),
        ];
    }

    /**
     * A lesson's real settings plus its ordered pages.
     *
     * @param cm_info $cm
     * @return array
     */
    private static function lesson_parameters(cm_info $cm): array {
        global $DB;

        $lesson = $DB->get_record('lesson', ['id' => $cm->instance]);
        if (!$lesson) {
            return ['name' => $cm->name, 'section' => (int) $cm->sectionnum];
        }

        // Every real mod_lesson setting travels too, not just the pages: the
        // generated activity is meant to BE this mold (progress bar, menu,
        // retakes, grading, ...), and course_ai copies these verbatim onto
        // it (see _lesson_mold_config_overrides). Sending only name/pages is
        // what left generated lessons on the schema's generic defaults.
        return array_merge(
            self::lesson_settings_columns($lesson),
            [
                'name' => $cm->name,
                'section' => (int) $cm->sectionnum,
                'intro' => $lesson->intro ?? '',
                'mod_settings' => ['pages' => self::lesson_pages((int) $lesson->id)],
            ]
        );
    }

    /**
     * The mod_lesson settings worth reproducing on the generated activity.
     *
     * Identity/placement columns (id, course, timemodified, ...) are left out
     * on purpose - they describe THIS lesson, never the new one.
     *
     * @param \stdClass $lesson
     * @return array
     */
    private static function lesson_settings_columns($lesson): array {
        $fields = [
            'practice', 'modattempts', 'usepassword', 'password', 'dependency', 'conditions',
            'grade', 'custom', 'ongoing', 'usemaxgrade', 'maxanswers', 'maxattempts',
            'review', 'nextpagedefault', 'feedback', 'minquestions', 'maxpages', 'timelimit',
            'retake', 'activitylink', 'mediafile', 'mediaheight', 'mediawidth', 'mediaclose',
            'slideshow', 'width', 'height', 'bgcolor', 'displayleft', 'displayleftif',
            'progressbar', 'available', 'deadline', 'completionendreached', 'completiontimespent',
        ];
        $settings = [];
        foreach ($fields as $field) {
            if (isset($lesson->$field)) {
                $settings[$field] = $lesson->$field;
            }
        }
        return $settings;
    }

    /**
     * Every page of one lesson, in the order students actually walk it.
     *
     * mod_lesson stores that order as a prevpageid/nextpageid chain, NOT as
     * the row id order: a page inserted between two existing ones keeps a
     * higher id while sitting in the middle. Ordering by id therefore
     * scrambled the mold's real sequence.
     *
     * @param int $lessonid
     * @return array
     */
    private static function lesson_pages(int $lessonid): array {
        global $DB;

        $records = $DB->get_records('lesson_pages', ['lessonid' => $lessonid], 'id ASC');
        $pages = [];
        foreach (self::chain_order($records) as $page) {
            $pages[] = [
                'title' => $page->title,
                'page_type' => 'content',
                'content_html' => $page->contents,
                'buttons' => self::page_buttons((int) $page->id),
            ];
        }
        return $pages;
    }

    /**
     * Walk the prevpageid/nextpageid chain from its first page.
     *
     * @param array $records lesson_pages rows, keyed by id.
     * @return array Ordered rows; falls back to the given order if the chain
     *     is broken (never loses a page).
     */
    private static function chain_order(array $records): array {
        $first = null;
        foreach ($records as $page) {
            if ((int) $page->prevpageid === 0) {
                $first = $page;
                break;
            }
        }
        if ($first === null) {
            return array_values($records);
        }

        $ordered = [];
        $current = $first;
        while ($current !== null && count($ordered) < count($records)) {
            $ordered[] = $current;
            $nextid = (int) $current->nextpageid;
            $current = $nextid > 0 ? ($records[$nextid] ?? null) : null;
        }

        return count($ordered) === count($records) ? $ordered : array_values($records);
    }

    /**
     * One page's real navigation buttons: every answer, with its jump.
     *
     * A content page's answers ARE its buttons ("Anterior"/"Siguiente"/"Fin
     * de la lección", each with its own jumpto). Sending only the first one
     * collapsed every page to a single button - and, when that first answer
     * was "Anterior", to a back-labelled button that jumped forward.
     *
     * @param int $pageid
     * @return array
     */
    private static function page_buttons(int $pageid): array {
        global $DB;

        $buttons = [];
        $answers = $DB->get_records('lesson_answers', ['pageid' => $pageid], 'id ASC', 'id, answer, jumpto');
        foreach ($answers as $answer) {
            $text = trim(html_to_text((string) $answer->answer, 0, false));
            if ($text === '') {
                continue;
            }
            $buttons[] = ['text' => $text, 'jumpto' => (int) $answer->jumpto];
        }
        return $buttons;
    }
}
