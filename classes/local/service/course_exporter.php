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

defined('MOODLE_INTERNAL') || die();

/**
 * Exports a real, existing Moodle course into the JSON payload the external
 * `coursegen-template` service ingests.
 *
 * The payload is a faithful, read-only snapshot of the course as it exists
 * today — every section and every activity, hidden ones included, because this
 * is a reference/regeneration export and not a student-facing view.
 *
 * Per-activity content reuses the canonical envelope this plugin already
 * establishes elsewhere ({'resource_type': <modname>, 'parameters': {...}},
 * the same shape create_mod_service::create_from_ai_result() consumes and
 * mock_template_ai_service produces), so an ingested course can be replayed
 * back through the existing creation pipeline. For the four module types that
 * already have a canonical `parameters` contract (page, label, forum, assign)
 * the exact same field names are emitted, populated with the REAL activity
 * data instead of fabricated content. `lesson` gets its own builder too,
 * because a lesson's actual content lives in `lesson_pages`, not in the
 * `lesson` row. Every other module type falls back to a deliberately generic
 * shape (modulename/name/visible/raw_fields[/introeditor]): capturing the raw
 * table row loses nothing, and inventing a per-type contract for each of
 * Moodle's remaining module types is explicitly out of scope here.
 *
 * Images are NEVER inlined. Each image found is handed to the injected
 * uploader callback as a \stored_file, and only the reference the uploader
 * returns is recorded in the payload. Text fields keep their original
 * `@@PLUGINFILE@@` tokens verbatim — the parallel `images` arrays are what
 * correlate a token to the uploaded image, so nothing is rewritten and the
 * export stays lossless.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_exporter {
    /**
     * Extra file areas to scan per module type, on top of the universal `intro` area.
     *
     * Every activity module in Moodle stores its `intro` editor files under
     * filearea 'intro', itemid 0 — that one is scanned unconditionally. These
     * are the additional areas that hold a given module type's actual payload:
     * mod_page's body HTML, and mod_resource/mod_folder's delivered files.
     * mod_lesson is absent on purpose: its per-page areas are itemid-keyed by
     * lesson page id, so they are handled separately (see export_activity()).
     *
     * @var array<string, string[]>
     */
    private const EXTRA_FILEAREAS = [
        'page' => ['content'],
        'resource' => ['content'],
        'folder' => ['content'],
    ];

    /** Module types that get a dedicated, canonical `parameters` builder. */
    private const DEDICATED_TYPES = ['page', 'label', 'forum', 'assign', 'lesson'];

    /** @var callable Uploader callback: function(\stored_file $file): array. */
    private $imageuploader;

    /** @var int Running total of images handed to the uploader during the current export. */
    private int $imagesuploaded = 0;

    /**
     * Constructor.
     *
     * @param callable $imageuploader Callback invoked once per image found, as
     *     `function(\stored_file $file): array`. It must upload the file and return the
     *     service's image reference object (id, filename, original_filename, mimetype,
     *     size, url), which is embedded verbatim in the payload. Injected rather than
     *     hardcoded so this exporter never depends on any particular HTTP client.
     */
    public function __construct(callable $imageuploader) {
        $this->imageuploader = $imageuploader;
    }

    /**
     * Export a whole course, uploading every image it references along the way.
     *
     * Iterates every section and every activity, including hidden ones. A single
     * activity that cannot be exported (unexpected schema, missing instance row,
     * a failed upload) is reported as a warning and skipped, so one broken
     * activity never aborts the export of the rest of the course.
     *
     * @param int $courseid Id of the course to export.
     * @return array The full ingest payload (see this class's docblock and the
     *     service's own contract for the exact shape).
     * @throws \dml_exception If the course itself cannot be read.
     * @throws \moodle_exception If the course does not exist.
     */
    public function export_course(int $courseid): array {
        global $CFG;

        require_once($CFG->dirroot . '/course/lib.php');

        $this->imagesuploaded = 0;

        $course = get_course($courseid);
        $coursecontext = \context_course::instance($course->id);
        $modinfo = get_fast_modinfo($course);
        $sectionmodules = $modinfo->get_sections();

        $sections = [];
        $activitiescount = 0;

        foreach ($modinfo->get_section_info_all() as $sectioninfo) {
            $sectionnum = (int) $sectioninfo->section;

            $activities = [];
            foreach ($sectionmodules[$sectionnum] ?? [] as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                try {
                    $activities[] = $this->export_activity($cm);
                    $activitiescount++;
                } catch (\Throwable $e) {
                    // Deliberately non-fatal: skip this activity, keep exporting the course.
                    $this->warn(
                        'local_coursegen course export: skipped activity cmid=' . (int) $cm->id
                        . ' (' . $cm->modname . '): ' . $e->getMessage()
                    );
                }
            }

            $sections[] = [
                'id' => (int) $sectioninfo->id,
                'sectionnum' => $sectionnum,
                'name' => $sectioninfo->name === null ? null : (string) $sectioninfo->name,
                'summary' => (string) $sectioninfo->summary,
                'summaryformat' => (int) $sectioninfo->summaryformat,
                'summary_images' => $this->export_section_summary_images($coursecontext, (int) $sectioninfo->id),
                'format' => $this->export_section_format($course, $coursecontext, (int) $sectioninfo->id),
                'activities' => $activities,
            ];
        }

        return [
            'courseid' => (int) $course->id,
            'exported_at' => time(),
            'course' => [
                'id' => (int) $course->id,
                'fullname' => (string) $course->fullname,
                'shortname' => (string) $course->shortname,
                'summary' => (string) $course->summary,
                'format' => (string) $course->format,
                'format_options' => self::as_json_object((array) course_get_format($course)->get_format_options()),
                // The FULL real course row, minus only what must never be
                // copied verbatim (its own id/shortname/idnumber — a real
                // course row needs unique values there, regenerated by the
                // caller — and Moodle-managed bookkeeping columns:
                // timecreated/timemodified/sortorder/cacherev/
                // originalcourseid). Every other real setting (dates,
                // language, group mode, news items, max upload size, theme,
                // etc.) is copied as-is rather than picked one at a time —
                // the picked-fields approach above (format_options,
                // enablecompletion) kept missing real settings one at a time.
                'settings' => self::as_json_object(self::course_settings_for_copy($course)),
            ],
            'sections' => $sections,
            'meta' => [
                'images_uploaded' => $this->imagesuploaded,
                'activities_count' => $activitiescount,
                'sections_count' => count($sections),
            ],
        ];
    }

    /**
     * Export one activity into the canonical per-activity envelope.
     *
     * @param \cm_info $cm The course module to export.
     * @return array{cmid:int,modname:string,name:string,resource_type:string,parameters:array,images:array,non_image_files:array}
     * @throws \dml_exception If the module's own instance row cannot be read.
     * @throws \moodle_exception If an image upload fails.
     */
    private function export_activity(\cm_info $cm): array {
        $modname = (string) $cm->modname;
        $component = 'mod_' . $modname;
        $modcontext = \context_module::instance($cm->id);
        $record = $this->get_instance_record($modname, (int) $cm->instance);

        $parameters = $this->build_parameters($cm, $record);

        $images = [];
        $nonimagefiles = [];

        // The `intro` editor area (itemid 0) is a universal Moodle convention: every
        // activity module has it, so it is always scanned, token found in the text or not.
        $this->collect_area_files($modcontext, $component, 'intro', 0, 'intro', $images, $nonimagefiles);

        foreach (self::EXTRA_FILEAREAS[$modname] ?? [] as $filearea) {
            $this->collect_area_files($modcontext, $component, $filearea, 0, $filearea, $images, $nonimagefiles);
        }

        if ($modname === 'lesson') {
            // A lesson's real content is per-page, and so are its files: filearea
            // 'page_contents' is keyed by lesson page id, never by 0. The field name
            // carries the page id so the payload can tell which page an image belongs to.
            foreach ($parameters['mod_settings']['pages'] ?? [] as $page) {
                $pageid = (int) $page['id'];
                $this->collect_area_files(
                    $modcontext,
                    $component,
                    'page_contents',
                    $pageid,
                    'page_contents:' . $pageid,
                    $images,
                    $nonimagefiles
                );
            }
        }

        if ($modname === 'resource') {
            // mod_resource's deliverable is the file itself, not an embedded content
            // image — upload it too (whatever its mimetype) so a caller rebuilding
            // this activity for real (course_recreator) has a real file to attach,
            // not just size/mimetype metadata. Recorded on its own key, never mixed
            // into `images` (which is specifically embedded content images).
            $package = $this->export_resource_package($modcontext, $component);
            if ($package !== null) {
                $parameters['mod_settings']['package'] = $package;
            }
        }

        return [
            'cmid' => (int) $cm->id,
            'modname' => $modname,
            'name' => (string) $cm->name,
            'resource_type' => $modname,
            'parameters' => $parameters,
            'images' => $images,
            'non_image_files' => $nonimagefiles,
        ];
    }

    /**
     * Upload a mod_resource's own real deliverable file.
     *
     * @param \context_module $modcontext The activity's module context.
     * @param string $component File component, e.g. 'mod_resource'.
     * @return array|null The uploaded reference object, or null when the
     *     resource has no real file behind it.
     * @throws \moodle_exception If the upload fails.
     */
    private function export_resource_package(\context_module $modcontext, string $component): ?array {
        $fs = get_file_storage();
        $files = $fs->get_area_files($modcontext->id, $component, 'content', 0, 'sortorder', false);
        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }
            return $this->upload_image($file);
        }
        return null;
    }

    /**
     * Build the `parameters` object for one activity.
     *
     * Dispatches to a dedicated builder for the module types that already have a
     * canonical contract in this plugin, and to the generic raw-row fallback for
     * everything else.
     *
     * @param \cm_info $cm The course module.
     * @param \stdClass|null $record The module's own instance row, or null when unavailable.
     * @return array
     * @throws \dml_exception If a dedicated builder needs extra rows it cannot read.
     */
    private function build_parameters(\cm_info $cm, ?\stdClass $record): array {
        $modname = (string) $cm->modname;

        if ($record === null || !in_array($modname, self::DEDICATED_TYPES, true)) {
            return $this->generic_parameters($cm, $record);
        }

        switch ($modname) {
            case 'page':
                return $this->page_parameters($cm, $record);
            case 'label':
                return $this->label_parameters($cm, $record);
            case 'forum':
                return $this->forum_parameters($cm, $record);
            case 'assign':
                return $this->assign_parameters($cm, $record);
            case 'lesson':
            default:
                return $this->lesson_parameters($cm, $record);
        }
    }

    /**
     * Course-module-level parameters shared by every dedicated module type.
     *
     * Mirrors mock_template_ai_service::base_parameters() field-for-field, but
     * populated from the real course module instead of fabricated defaults.
     *
     * @param \cm_info $cm The course module.
     * @return array
     */
    private static function base_parameters(\cm_info $cm): array {
        return [
            'modulename' => (string) $cm->modname,
            'name' => (string) $cm->name,
            'visible' => (int) $cm->visible,
            'visibleoncoursepage' => (int) $cm->visibleoncoursepage,
            'groupmode' => (int) $cm->groupmode,
            'groupingid' => (int) $cm->groupingid,
            'completion' => (int) $cm->completion,
            'completiongradeitemnumber' => $cm->completiongradeitemnumber === null
                ? ''
                : (int) $cm->completiongradeitemnumber,
            'completionview' => (int) $cm->completionview,
            'completionexpected' => (int) $cm->completionexpected,
            'completionpassgrade' => (int) $cm->completionpassgrade,
            'showdescription' => (int) $cm->showdescription,
            'mod_settings' => [],
        ];
    }

    /**
     * Build the real-data equivalent of mock_template_ai_service::page_result().
     *
     * @param \cm_info $cm The course module.
     * @param \stdClass $record The mod_page instance row.
     * @return array
     */
    private function page_parameters(\cm_info $cm, \stdClass $record): array {
        $parameters = self::base_parameters($cm);
        $parameters['introeditor'] = self::intro_editor($record);
        $parameters['page'] = [
            'text' => (string) ($record->content ?? ''),
            'format' => (int) ($record->contentformat ?? FORMAT_HTML),
        ];
        $parameters['display'] = (int) ($record->display ?? 0);
        $parameters['printintro'] = (int) ($record->printintro ?? 0);
        $parameters['printlastmodified'] = (int) ($record->printlastmodified ?? 1);

        return $parameters;
    }

    /**
     * Build the real-data equivalent of mock_template_ai_service::label_result().
     *
     * A label's content IS its intro field — there is no separate content area.
     *
     * @param \cm_info $cm The course module.
     * @param \stdClass $record The mod_label instance row.
     * @return array
     */
    private function label_parameters(\cm_info $cm, \stdClass $record): array {
        $parameters = self::base_parameters($cm);
        $parameters['introeditor'] = self::intro_editor($record);

        return $parameters;
    }

    /**
     * Build the real-data equivalent of mock_template_ai_service::forum_result().
     *
     * @param \cm_info $cm The course module.
     * @param \stdClass $record The mod_forum instance row.
     * @return array
     */
    private function forum_parameters(\cm_info $cm, \stdClass $record): array {
        $parameters = self::base_parameters($cm);
        $parameters['introeditor'] = self::intro_editor($record);
        $parameters['type'] = (string) ($record->type ?? 'general');
        $parameters['assessed'] = (int) ($record->assessed ?? 0);
        $parameters['scale'] = (int) ($record->scale ?? 0);
        $parameters['forcesubscribe'] = (int) ($record->forcesubscribe ?? 0);
        $parameters['grade_forum'] = (int) ($record->grade_forum ?? 0);

        return $parameters;
    }

    /**
     * Build the real-data equivalent of mock_template_ai_service::assign_result().
     *
     * @param \cm_info $cm The course module.
     * @param \stdClass $record The mod_assign instance row.
     * @return array
     */
    private function assign_parameters(\cm_info $cm, \stdClass $record): array {
        $parameters = self::base_parameters($cm);
        $parameters['introeditor'] = self::intro_editor($record);

        $fields = [
            'alwaysshowdescription', 'submissiondrafts', 'requiresubmissionstatement', 'sendnotifications',
            'sendstudentnotifications', 'sendlatenotifications', 'duedate', 'allowsubmissionsfromdate',
            'grade', 'cutoffdate', 'gradingduedate', 'teamsubmission', 'requireallteammemberssubmit',
            'teamsubmissiongroupingid', 'blindmarking', 'attemptreopenmethod', 'maxattempts',
            'markingworkflow', 'markingallocation', 'markinganonymous', 'activityformat', 'timelimit',
            'submissionattachments',
        ];
        foreach ($fields as $field) {
            if (!property_exists($record, $field)) {
                continue;
            }
            // The attemptreopenmethod field is the only non-numeric one of the set.
            $parameters[$field] = $field === 'attemptreopenmethod'
                ? (string) $record->$field
                : (int) $record->$field;
        }

        return $parameters;
    }

    /**
     * Build the `parameters` object for a lesson.
     *
     * Deliberately its own builder rather than the generic raw-row fallback: a
     * lesson's actual authored content does not live in the `lesson` row at all,
     * it lives one row per page in `lesson_pages` (each page's `contents` field
     * using the usual @@PLUGINFILE@@ convention). The `lesson` row's own
     * intro/introformat still hold the lesson's description, as everywhere else.
     *
     * @param \cm_info $cm The course module.
     * @param \stdClass $record The mod_lesson instance row.
     * @return array
     * @throws \dml_exception If the lesson's pages cannot be read.
     */
    private function lesson_parameters(\cm_info $cm, \stdClass $record): array {
        global $DB;

        $pages = [];
        $rows = $DB->get_records('lesson_pages', ['lessonid' => (int) $cm->instance], 'id ASC');
        foreach ($rows as $row) {
            $answers = array_values($DB->get_records(
                'lesson_answers',
                ['lessonid' => (int) $cm->instance, 'pageid' => (int) $row->id],
                'id ASC'
            ));

            $page = [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'content_html' => (string) $row->contents,
            ];

            // Mirrors lesson_settings::build_page_properties()'s own qtype
            // handling — only these three shapes are ever reconstructed;
            // anything else is recorded as raw metadata only (informational,
            // matches this same class's own "lossless without inventing a
            // contract" philosophy elsewhere).
            if ((int) $row->qtype === 20) { // LESSON_PAGE_BRANCHTABLE.
                // A real content page can carry more than one navigation
                // button (e.g. "Siguiente"/"Anterior") — lesson_settings only
                // reconstructs a single forward button, so the first answer
                // whose jumpto is not "go back" wins; falls back to the
                // first answer of any kind rather than dropping the page.
                $forward = null;
                foreach ($answers as $answer) {
                    if ((int) $answer->jumpto !== -40) { // LESSON_PREVIOUSPAGE.
                        $forward = $answer;
                        break;
                    }
                }
                $forward ??= ($answers[0] ?? null);
                $page['page_type'] = 'content';
                $page['button_text'] = $forward !== null ? (string) $forward->answer : '';
            } else if ((int) $row->qtype === 3 || (int) $row->qtype === 2) { // MULTICHOICE / TRUEFALSE.
                $page['page_type'] = (int) $row->qtype === 3 ? 'multi_choice' : 'true_false';
                $page['options'] = array_map(static fn ($answer) => [
                    'text' => (string) $answer->answer,
                    'correct' => (int) $answer->score > 0,
                    'feedback' => (string) ($answer->response ?? ''),
                ], $answers);
            } else {
                // Unsupported page type today (mirrors lesson_settings.php's
                // own behavior of skipping it) — kept for completeness/
                // inspection, never fed back into a recreation attempt.
                $page['page_type'] = 'unsupported';
                $page['raw_qtype'] = (int) $row->qtype;
            }

            $pages[] = $page;
        }

        $parameters = self::base_parameters($cm);
        $parameters['introeditor'] = self::intro_editor($record);
        $parameters['mod_settings'] = ['pages' => $pages];

        return $parameters;
    }

    /**
     * Build the generic fallback `parameters` object.
     *
     * Used for every module type without a dedicated builder. Capturing the whole
     * instance row verbatim (minus the two columns that are meaningless outside this
     * site: the surrogate `id` and the `course` back-reference) keeps the export
     * lossless without inventing a per-type contract for every Moodle module.
     *
     * @param \cm_info $cm The course module.
     * @param \stdClass|null $record The module's own instance row, or null when unavailable.
     * @return array
     */
    private function generic_parameters(\cm_info $cm, ?\stdClass $record): array {
        $rawfields = [];
        if ($record !== null) {
            $rawfields = (array) $record;
            unset($rawfields['id'], $rawfields['course'], $rawfields['intro'], $rawfields['introformat']);
        }

        // The module's own NOT-NULL columns (e.g. mod_feedback's page_after_submit)
        // must reach add_moduleinfo() as real top-level parameters, not just as the
        // 'raw_fields' JSON summary below (which exists for the AI service's own
        // reference and is never itself consumed by activity creation) — otherwise
        // recreating this module fails on a missing required field. base_parameters()'
        // own keys take priority over a same-named raw column.
        $parameters = array_merge($rawfields, self::base_parameters($cm), [
            'raw_fields' => self::as_json_object((array) $record),
        ]);

        // The intro/introformat pair is a near-universal Moodle module convention, but
        // not a guaranteed one, so it is only emitted when the row actually has it.
        if ($record !== null && property_exists($record, 'intro')) {
            $parameters['introeditor'] = self::intro_editor($record);
        }

        return $parameters;
    }

    /**
     * Build the canonical `introeditor` value ({text, format}) from an instance row.
     *
     * @param \stdClass $record The module's own instance row.
     * @return array{text:string,format:int}
     */
    private static function intro_editor(\stdClass $record): array {
        return [
            'text' => (string) ($record->intro ?? ''),
            'format' => (int) ($record->introformat ?? FORMAT_HTML),
        ];
    }

    /**
     * Export a section's summary images.
     *
     * Section summary editor files live at COURSE context under component 'course',
     * filearea 'section', itemid = the section's own id (verified against core's
     * course/editsection_form.php — note this is 'section', not 'summary'; 'summary'
     * is the *course* summary's area, at itemid 0).
     *
     * Sections carry no `non_image_files` array in the payload contract, so a
     * non-image file in this area is simply skipped rather than recorded.
     *
     * @param \context_course $coursecontext The course context.
     * @param int $sectionid The section's id (not its section number).
     * @return array<int, array{token:string,image:array}>
     * @throws \moodle_exception If an image upload fails.
     */
    private function export_section_summary_images(\context_course $coursecontext, int $sectionid): array {
        $fs = get_file_storage();
        $summaryimages = [];

        $files = $fs->get_area_files($coursecontext->id, 'course', 'section', $sectionid, 'sortorder', false);
        foreach ($files as $file) {
            if ($file->is_directory() || !self::is_image($file)) {
                continue;
            }
            $summaryimages[] = [
                'token' => self::pluginfile_token($file),
                'image' => $this->upload_image($file),
            ];
        }

        return $summaryimages;
    }

    /**
     * Export the course-format-specific block of a section.
     *
     * Only format_grid contributes anything today (its per-section tile image).
     * For every other format this is an empty object on purpose: format-specific
     * keys are never fabricated for formats that do not have them, and the
     * format's own generic settings are already captured once, course-wide, in
     * course.format_options.
     *
     * @param \stdClass $course The course record.
     * @param \context_course $coursecontext The course context.
     * @param int $sectionid The section's id (not its section number).
     * @return array|\stdClass Empty object for non-grid formats, otherwise {grid_tile_image: ...}.
     * @throws \moodle_exception If the tile image upload fails.
     */
    private function export_section_format(
        \stdClass $course,
        \context_course $coursecontext,
        int $sectionid
    ): array|\stdClass {
        if ((string) $course->format !== 'grid') {
            return new \stdClass();
        }

        return ['grid_tile_image' => $this->export_grid_tile_image($course, $coursecontext, $sectionid)];
    }

    /**
     * Export one grid-format section's tile image.
     *
     * @param \stdClass $course The course record.
     * @param \context_course $coursecontext The course context.
     * @param int $sectionid The section's id (not its section number).
     * @return array|null {displayedimagestate, image}, or null when the section has no
     *     usable tile image (no format_grid_image row, or a row whose file is gone).
     * @throws \moodle_exception If the tile image upload fails.
     */
    private function export_grid_tile_image(\stdClass $course, \context_course $coursecontext, int $sectionid): ?array {
        global $DB;

        try {
            $row = $DB->get_record('format_grid_image', [
                'sectionid' => $sectionid,
                'courseid' => (int) $course->id,
            ]);
        } catch (\dml_exception $e) {
            // The format_grid format is set on the course but its table is unreadable:
            // not worth aborting a whole course export over.
            $this->warn('local_coursegen course export: could not read format_grid_image: ' . $e->getMessage());
            return null;
        }

        if (!$row) {
            return null;
        }

        $file = $this->find_grid_tile_file($coursecontext, $sectionid, (string) ($row->image ?? ''));
        if ($file === null) {
            // A row with no surviving file behind it: record the absence, do not fail.
            return null;
        }

        return [
            'displayedimagestate' => (int) ($row->displayedimagestate ?? 0),
            'image' => $this->upload_image($file),
        ];
    }

    /**
     * Locate the real \stored_file behind a grid section's tile image.
     *
     * Prefers the rendered/displayed tile, then the original upload, then any file
     * left in the original upload area (the DB row only records a filename, and
     * format_grid may have rewritten it while generating the displayed version).
     *
     * @param \context_course $coursecontext The course context.
     * @param int $sectionid The section's id, used as the itemid in both file areas.
     * @param string $filename Filename recorded in format_grid_image.image.
     * @return \stored_file|null The file, or null when none of the lookups find one.
     */
    private function find_grid_tile_file(\context_course $coursecontext, int $sectionid, string $filename): ?\stored_file {
        $fs = get_file_storage();

        if ($filename !== '') {
            foreach (['displayedsectionimage', 'sectionimage'] as $filearea) {
                $file = $fs->get_file($coursecontext->id, 'format_grid', $filearea, $sectionid, '/', $filename);
                if ($file && !$file->is_directory()) {
                    return $file;
                }
            }
        }

        $files = $fs->get_area_files($coursecontext->id, 'format_grid', 'sectionimage', $sectionid, 'sortorder', false);
        foreach ($files as $file) {
            if (!$file->is_directory()) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Scan one file area, uploading the images and recording metadata for the rest.
     *
     * Every real file in the area is enumerated regardless of whether a matching
     * pluginfile token appears in the corresponding text field: some content
     * references files without the token, and mod_resource/mod_folder deliverables
     * are files in their own right rather than embedded markup. Over-reporting an
     * orphaned upload is harmless; missing a referenced image is not.
     *
     * Non-image files are recorded as metadata only and never uploaded — this
     * export deliberately carries images alone.
     *
     * @param \context_module $modcontext The activity's module context.
     * @param string $component File component, e.g. 'mod_page'.
     * @param string $filearea File area to scan.
     * @param int $itemid Item id within the area.
     * @param string $field Field label recorded in the payload for the files found here.
     * @param array $images Accumulator for image entries, by reference.
     * @param array $nonimagefiles Accumulator for non-image entries, by reference.
     * @return void
     * @throws \moodle_exception If an image upload fails.
     */
    private function collect_area_files(
        \context_module $modcontext,
        string $component,
        string $filearea,
        int $itemid,
        string $field,
        array &$images,
        array &$nonimagefiles
    ): void {
        $fs = get_file_storage();
        $files = $fs->get_area_files($modcontext->id, $component, $filearea, $itemid, 'sortorder', false);

        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }

            if (self::is_image($file)) {
                $images[] = [
                    'field' => $field,
                    'token' => self::pluginfile_token($file),
                    'image' => $this->upload_image($file),
                ];
                continue;
            }

            $nonimagefiles[] = [
                'field' => $field,
                'filename' => $file->get_filename(),
                'mimetype' => (string) $file->get_mimetype(),
                'size' => (int) $file->get_filesize(),
            ];
        }
    }

    /**
     * Hand one image to the injected uploader and count it.
     *
     * @param \stored_file $file The image to upload.
     * @return array The image reference object returned by the uploader.
     * @throws \moodle_exception If the uploader fails.
     */
    private function upload_image(\stored_file $file): array {
        $reference = ($this->imageuploader)($file);
        $this->imagesuploaded++;

        return (array) $reference;
    }

    /**
     * Rebuild the @@PLUGINFILE@@ token that a text field would use to reference a file.
     *
     * Includes the file's path, so files stored in a subdirectory of the area get the
     * token they actually appear as in the HTML (filepath is always '/'-delimited and
     * '/' for files at the root of an area).
     *
     * @param \stored_file $file The file to build a token for.
     * @return string
     */
    private static function pluginfile_token(\stored_file $file): string {
        return '@@PLUGINFILE@@' . $file->get_filepath() . $file->get_filename();
    }

    /**
     * Whether a stored file is an image, by mimetype.
     *
     * @param \stored_file $file The file to test.
     * @return bool
     */
    private static function is_image(\stored_file $file): bool {
        return str_starts_with((string) $file->get_mimetype(), 'image/');
    }

    /**
     * Read a module's own instance row.
     *
     * @param string $modname Module plugin name, which is also its table name.
     * @param int $instance The course module's instance id.
     * @return \stdClass|null The row, or null when the table or row is unavailable.
     */
    private function get_instance_record(string $modname, int $instance): ?\stdClass {
        global $DB;

        try {
            $record = $DB->get_record($modname, ['id' => $instance]);
        } catch (\dml_exception $e) {
            $this->warn(
                'local_coursegen course export: could not read ' . $modname . ' row ' . $instance
                . ': ' . $e->getMessage()
            );
            return null;
        }

        return $record ?: null;
    }

    /**
     * Return a value that json_encode()s as a JSON object rather than an empty array.
     *
     * PHP's empty array encodes as `[]`, which would break a consumer expecting an
     * object for course.format_options, section.format and parameters.raw_fields.
     *
     * @param array $data The associative array to emit.
     * @return array|\stdClass The array itself, or an empty object when it has no entries.
     */
    private static function as_json_object(array $data): array|\stdClass {
        return $data === [] ? new \stdClass() : $data;
    }

    /**
     * The full real `course` table row, minus what a recreated course must
     * never copy verbatim.
     *
     * @param \stdClass $course The course record (from get_course()).
     * @return array
     */
    private static function course_settings_for_copy(\stdClass $course): array {
        $settings = (array) $course;

        // Its own identity (regenerated by the caller so the new course is a
        // real, distinct course, never a collision with the source).
        unset($settings['id'], $settings['shortname'], $settings['idnumber']);
        // Moodle-managed bookkeeping — never meaningful to copy from another
        // course; create_course()/update_course() set these themselves.
        unset(
            $settings['timecreated'],
            $settings['timemodified'],
            $settings['sortorder'],
            $settings['cacherev'],
            $settings['originalcourseid']
        );
        // Already carried separately (fullname/summary/format above,
        // category resolved by the caller against ITS OWN category tree —
        // the source course's numeric category id means nothing on a
        // different site/instance).
        unset($settings['fullname'], $settings['summary'], $settings['format'], $settings['category']);

        return $settings;
    }

    /**
     * Report a non-fatal export problem.
     *
     * Uses mtrace() under CLI, where this exporter's only caller lives and the
     * message is the whole point, and debugging() otherwise so a web request
     * never writes to the output stream.
     *
     * @param string $message The warning to report.
     * @return void
     */
    private function warn(string $message): void {
        if (CLI_SCRIPT) {
            mtrace($message);
        } else {
            debugging($message, DEBUG_DEVELOPER);
        }
    }
}
