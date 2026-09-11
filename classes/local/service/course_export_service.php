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
 * Export a real, already-existing Moodle course into the same resultdata
 * shape create_course_service::create_course() consumes from the Datacurso
 * API (course_configuration, sections_info, generated_activities,
 * subsections_info, blocks_info).
 *
 * Used by the mbz+course round-trip test page: this is the "course 422"
 * side of that unification, read live via Moodle's own APIs (get_fast_modinfo(),
 * course_sections, mod tables) - no HTTP/webservice/DB-of-another-app
 * involved, since this code already runs inside Moodle.
 *
 * Scope intentionally mirrors what the .mbz side (coursegen_template's
 * mbzExtractor/activityGenerator) can build an envelope for: activities
 * created inside delegated (mod_subsection) sections, and mod_resource
 * activities (their file would need a separate transport back through the
 * test node service), are left out of this export.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_export_service {
    /** @var string[] Module types this export can build an envelope for. */
    private const SUPPORTED_TYPES = ['label', 'page', 'forum', 'lesson', 'feedback'];

    /**
     * @var array<string,array{mimetype:string,content_base64:string}> Real file
     * content, keyed by Moodle's own contenthash, collected once per
     * export_course() call and shipped as a single course-level catalog
     * (see class docblock note on transport below) instead of embedded
     * per-activity: the same real image is very often reused verbatim
     * across many activities/pages (e.g. a shared banner reused in every
     * lesson page), and embedding its base64 bytes again for every one of
     * those references would multiply an otherwise ~10MB real payload into
     * several hundred MB for a single course export.
     */
    private static array $imageassets = [];

    /**
     * Export a course into resultdata shape.
     *
     * Transport for embedded images: real file bytes travel as base64 in
     * this same JSON (no new endpoint), but de-duplicated at the course
     * level under 'image_assets' (keyed by contenthash) rather than
     * repeated inside every activity's own 'images' entry - each activity's
     * 'images' entries only carry a 'content_hash' reference into that
     * shared catalog.
     *
     * @param int $courseid Course ID to export.
     * @return array {course_configuration, sections_info, generated_activities,
     *     subsections_info, blocks_info, image_assets}
     */
    public static function export_course(int $courseid): array {
        global $CFG;

        require_once($CFG->dirroot . '/course/lib.php');

        self::$imageassets = [];

        $course = get_course($courseid);
        $modinfo = get_fast_modinfo($course);

        $sectionsinfo = [];
        foreach ($modinfo->get_section_info_all() as $sectioninfo) {
            // Delegated (mod_subsection) sections are out of scope for this export.
            if (!empty($sectioninfo->component)) {
                continue;
            }
            $sectionsinfo[] = [
                'uid' => bin2hex(random_bytes(16)),
                'section' => (int)$sectioninfo->section,
                'name' => get_section_name($course, $sectioninfo),
                'description' => (string)($sectioninfo->summary ?? ''),
                'descriptionformat' => (int)($sectioninfo->summaryformat ?? FORMAT_HTML),
                'visible' => (int)$sectioninfo->visible,
            ];
        }

        $generatedactivities = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!empty($cm->deletioninprogress)) {
                continue;
            }
            if (!in_array($cm->modname, self::SUPPORTED_TYPES, true)) {
                continue;
            }
            $cmsectioninfo = $cm->get_section_info();
            if (!empty($cmsectioninfo->component)) {
                // Activity lives inside a delegated subsection; out of scope (see class docblock).
                continue;
            }
            $envelope = self::build_activity_envelope($cm, (int)$cmsectioninfo->section);
            if ($envelope !== null) {
                $envelope['uid'] = bin2hex(random_bytes(16));
                $generatedactivities[] = $envelope;
            }
        }

        $imageassets = self::$imageassets;
        self::$imageassets = [];

        return [
            'uid' => bin2hex(random_bytes(16)),
            'course_configuration' => self::course_configuration($course),
            'sections_info' => $sectionsinfo,
            'generated_activities' => $generatedactivities,
            'subsections_info' => [],
            'blocks_info' => self::export_blocks($course),
            'image_assets' => $imageassets,
        ];
    }

    /**
     * Export the block instances sitting directly in the course context
     * (the sidebar: "Recent activity", "Calendar", "Search forums", ...).
     *
     * Blocks inherited from a parent context via showinsubcontexts are not
     * rows in this course's own context and are intentionally left out -
     * they are not "this course's" blocks to reproduce.
     *
     * @param \stdClass $course Real course record.
     * @return array List of {blockname, defaultregion, defaultweight,
     *     showinsubcontexts, pagetypepattern, subpagepattern, configdata}.
     */
    private static function export_blocks(\stdClass $course): array {
        global $DB;

        $coursecontext = \context_course::instance($course->id);
        $blockrecords = $DB->get_records(
            'block_instances',
            ['parentcontextid' => $coursecontext->id],
            'defaultregion, defaultweight'
        );

        $blocksinfo = [];
        foreach ($blockrecords as $blockrecord) {
            $blocksinfo[] = [
                'blockname' => (string)$blockrecord->blockname,
                'defaultregion' => (string)$blockrecord->defaultregion,
                'defaultweight' => (int)$blockrecord->defaultweight,
                'showinsubcontexts' => (int)$blockrecord->showinsubcontexts,
                'pagetypepattern' => (string)$blockrecord->pagetypepattern,
                'subpagepattern' => (string)($blockrecord->subpagepattern ?? ''),
                'configdata' => self::decode_block_configdata($blockrecord->configdata),
            ];
        }

        return $blocksinfo;
    }

    /**
     * Decode a block_instances.configdata blob (base64-encoded serialized
     * PHP, see block_base::instance_config_save()) into a plain array so it
     * stays legible in the exported JSON instead of shipping the raw blob.
     *
     * A config field that decodes to a non-scalar (e.g. an editor/filemanager
     * field carrying a draft file itemid) is out of scope for the same
     * reason mod_resource is (see class docblock): the file itself needs a
     * separate transport this pipeline does not provide, so that single
     * field is dropped rather than forced through.
     *
     * @param string|null $configdata Raw configdata column value.
     * @return array|null Null when there is no config to reproduce.
     */
    private static function decode_block_configdata(?string $configdata): ?array {
        if (empty($configdata)) {
            return null;
        }

        $decoded = @unserialize(base64_decode($configdata), ['allowed_classes' => [\stdClass::class]]);
        if (!is_object($decoded) && !is_array($decoded)) {
            return null;
        }

        $config = (array)$decoded;
        foreach ($config as $key => $value) {
            if (is_object($value) || is_array($value)) {
                unset($config[$key]);
            }
        }

        return $config ?: null;
    }

    /**
     * Build the course_configuration entry from the real course record.
     *
     * Course-level format options (course_format_options with sectionid = 0,
     * e.g. format_grid's gridjustification/hiddensections/popup/... or any
     * other format's course-level scalar settings) are included verbatim
     * under 'format_options', except 'numsections', which create_course_service
     * already derives from sections_info. Per-section format options (e.g.
     * format_grid's sectionimage, which needs its own file transport, like
     * mod_resource) are intentionally left out - see class docblock.
     *
     * @param \stdClass $course Real course record.
     * @return array
     */
    private static function course_configuration(\stdClass $course): array {
        global $DB;

        $formatoptions = $DB->get_records_select(
            'course_format_options',
            'courseid = ? AND sectionid = 0 AND name <> ?',
            [$course->id, 'numsections'],
            '',
            'name,value'
        );
        $formatoptionsmap = [];
        foreach ($formatoptions as $option) {
            $formatoptionsmap[$option->name] = $option->value;
        }

        return [
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
            'category' => (int)$course->category,
            'format' => $course->format,
            'summary' => (string)$course->summary,
            'summaryformat' => (int)$course->summaryformat,
            'startdate' => (int)$course->startdate,
            'enddate' => (int)$course->enddate,
            'visible' => (int)$course->visible,
            'lang' => (string)$course->lang,
            'newsitems' => (int)$course->newsitems,
            'showgrades' => (int)$course->showgrades,
            'showreports' => (int)$course->showreports,
            'maxbytes' => (int)$course->maxbytes,
            'enablecompletion' => (int)$course->enablecompletion,
            'groupmode' => (int)$course->groupmode,
            'groupmodeforce' => (int)$course->groupmodeforce,
            'format_options' => $formatoptionsmap,
        ];
    }

    /**
     * Fields every generated_activities-shape entry shares.
     *
     * Generic course-module-level settings (visible, groupmode, groupingid,
     * idnumber, completion tracking) are read from the real $cm so they
     * survive the export/create round-trip exactly as configured.
     *
     * Access restrictions ($cm->availability) are intentionally left out:
     * the JSON tree can reference other course module/grouping/grade-item
     * ids, which are not stable across the export/create round-trip and
     * would need id remapping this pipeline does not do.
     *
     * @param \cm_info $cm Course module.
     * @param int $sectionnum Destination section number.
     * @return array
     */
    private static function base_parameters(\cm_info $cm, int $sectionnum): array {
        return [
            'modulename' => $cm->modname,
            'name' => $cm->name,
            'section' => $sectionnum,
            'visible' => (int)$cm->visible,
            'visibleoncoursepage' => (int)$cm->visibleoncoursepage,
            'groupmode' => (int)$cm->groupmode,
            'groupingid' => (int)$cm->groupingid,
            'cmidnumber' => (string)$cm->idnumber,
            'completion' => (int)$cm->completion,
            'completiongradeitemnumber' => $cm->completiongradeitemnumber,
            'completionview' => (int)$cm->completionview,
            'completionexpected' => (int)$cm->completionexpected,
            'completionpassgrade' => (int)$cm->completionpassgrade,
            'showdescription' => (int)$cm->showdescription,
            'mod_settings' => [],
        ];
    }

    /**
     * Build a generated_activities-shape entry for one existing course module.
     *
     * @param \cm_info $cm Course module.
     * @param int $sectionnum Destination section number.
     * @return array|null Null when the module instance record can't be read.
     */
    private static function build_activity_envelope(\cm_info $cm, int $sectionnum): ?array {
        switch ($cm->modname) {
            case 'label':
                return self::label_envelope($cm, $sectionnum);
            case 'page':
                return self::page_envelope($cm, $sectionnum);
            case 'forum':
                return self::forum_envelope($cm, $sectionnum);
            case 'lesson':
                return self::lesson_envelope($cm, $sectionnum);
            case 'feedback':
                return self::feedback_envelope($cm, $sectionnum);
            default:
                return null;
        }
    }

    /**
     * Build a mod_label envelope from its real DB row.
     *
     * @param \cm_info $cm Course module.
     * @param int $sectionnum Destination section number.
     * @return array
     */
    private static function label_envelope(\cm_info $cm, int $sectionnum): array {
        global $DB;

        $record = $DB->get_record('label', ['id' => $cm->instance], '*', MUST_EXIST);
        $parameters = self::base_parameters($cm, $sectionnum);
        $parameters['introeditor'] = ['text' => (string)$record->intro, 'format' => (int)$record->introformat];

        $contextid = \context_module::instance($cm->id)->id;
        $images = self::extract_pluginfile_images($contextid, 'mod_label', 'intro', 0, (string)$record->intro);
        if (!empty($images)) {
            $parameters['images'] = $images;
        }

        return ['resource_type' => 'label', 'parameters' => $parameters];
    }

    /**
     * Build a mod_page envelope from its real DB row.
     *
     * @param \cm_info $cm Course module.
     * @param int $sectionnum Destination section number.
     * @return array
     */
    private static function page_envelope(\cm_info $cm, int $sectionnum): array {
        global $DB;

        $record = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);
        $parameters = self::base_parameters($cm, $sectionnum);
        $parameters['introeditor'] = ['text' => (string)$record->intro, 'format' => (int)$record->introformat];
        $parameters['page'] = ['text' => (string)$record->content, 'format' => (int)$record->contentformat];

        $displayoptions = self::unserialize_display_options($record->displayoptions);
        $parameters['display'] = (int)$record->display;
        $parameters['printintro'] = (int)($displayoptions['printintro'] ?? 0);
        $parameters['printlastmodified'] = (int)($displayoptions['printlastmodified'] ?? 0);
        $parameters['popupwidth'] = (int)($displayoptions['popupwidth'] ?? 0);
        $parameters['popupheight'] = (int)($displayoptions['popupheight'] ?? 0);

        $contextid = \context_module::instance($cm->id)->id;
        $images = self::merge_images(
            self::extract_pluginfile_images($contextid, 'mod_page', 'intro', 0, (string)$record->intro),
            self::extract_pluginfile_images($contextid, 'mod_page', 'content', 0, (string)$record->content)
        );
        if (!empty($images)) {
            $parameters['images'] = $images;
        }

        return ['resource_type' => 'page', 'parameters' => $parameters];
    }

    /**
     * Build a mod_forum envelope from its real DB rows (forum + discussions + first posts).
     *
     * @param \cm_info $cm Course module.
     * @param int $sectionnum Destination section number.
     * @return array
     */
    private static function forum_envelope(\cm_info $cm, int $sectionnum): array {
        global $DB;

        $record = $DB->get_record('forum', ['id' => $cm->instance], '*', MUST_EXIST);
        $parameters = self::base_parameters($cm, $sectionnum);
        $parameters['introeditor'] = ['text' => (string)$record->intro, 'format' => (int)$record->introformat];
        $parameters['type'] = (string)$record->type;
        $parameters['assessed'] = (int)$record->assessed;
        $parameters['assesstimestart'] = (int)$record->assesstimestart;
        $parameters['assesstimefinish'] = (int)$record->assesstimefinish;
        $parameters['scale'] = (int)$record->scale;
        $parameters['grade_forum'] = (int)$record->grade_forum;
        $parameters['grade_forum_notify'] = (int)$record->grade_forum_notify;
        $parameters['duedate'] = (int)$record->duedate;
        $parameters['cutoffdate'] = (int)$record->cutoffdate;
        $parameters['maxbytes'] = (int)$record->maxbytes;
        $parameters['maxattachments'] = (int)$record->maxattachments;
        $parameters['forcesubscribe'] = (int)$record->forcesubscribe;
        $parameters['trackingtype'] = (int)$record->trackingtype;
        $parameters['rsstype'] = (int)$record->rsstype;
        $parameters['rssarticles'] = (int)$record->rssarticles;
        $parameters['warnafter'] = (int)$record->warnafter;
        $parameters['blockafter'] = (int)$record->blockafter;
        $parameters['blockperiod'] = (int)$record->blockperiod;
        $parameters['displaywordcount'] = (int)$record->displaywordcount;
        $parameters['lockdiscussionafter'] = (int)$record->lockdiscussionafter;
        $parameters['completiondiscussions'] = (int)$record->completiondiscussions;
        $parameters['completionreplies'] = (int)$record->completionreplies;
        $parameters['completionposts'] = (int)$record->completionposts;

        $contextid = \context_module::instance($cm->id)->id;
        $imagelists = [
            self::extract_pluginfile_images($contextid, 'mod_forum', 'intro', 0, (string)$record->intro),
        ];

        $discussions = [];
        $discussionrecords = $DB->get_records('forum_discussions', ['forum' => $record->id], 'id ASC');
        foreach ($discussionrecords as $discussion) {
            $post = $DB->get_record('forum_posts', ['id' => $discussion->firstpost]);
            $message = $post ? (string)$post->message : '';
            $discussions[] = [
                'subject' => (string)$discussion->name,
                'message' => $message,
            ];
            if ($post) {
                $imagelists[] = self::extract_pluginfile_images($contextid, 'mod_forum', 'post', (int)$post->id, $message);
            }
        }
        if (empty($discussions)) {
            // Seed one discussion from the forum's own real name/intro so the
            // activity isn't silently empty, mirroring the mbz side's fallback.
            $discussions[] = ['subject' => (string)$cm->name, 'message' => (string)$record->intro];
        }
        $parameters['mod_settings'] = ['discussions' => $discussions];

        $images = self::merge_images(...$imagelists);
        if (!empty($images)) {
            $parameters['images'] = $images;
        }

        return ['resource_type' => 'forum', 'parameters' => $parameters];
    }

    /**
     * Build a mod_lesson envelope from its real DB rows (lesson_pages + lesson_answers).
     *
     * @param \cm_info $cm Course module.
     * @param int $sectionnum Destination section number.
     * @return array
     */
    private static function lesson_envelope(\cm_info $cm, int $sectionnum): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/lesson/locallib.php');
        require_once($CFG->dirroot . '/mod/lesson/pagetypes/branchtable.php');
        require_once($CFG->dirroot . '/mod/lesson/pagetypes/multichoice.php');
        require_once($CFG->dirroot . '/mod/lesson/pagetypes/truefalse.php');

        $record = $DB->get_record('lesson', ['id' => $cm->instance], '*', MUST_EXIST);
        $parameters = self::base_parameters($cm, $sectionnum);
        $parameters['introeditor'] = ['text' => (string)$record->intro, 'format' => (int)$record->introformat];
        $parameters['grade'] = (int)$record->grade;
        $parameters['practice'] = (int)$record->practice;
        $parameters['modattempts'] = (int)$record->modattempts;
        $parameters['usepassword'] = (int)$record->usepassword;
        $parameters['password'] = (string)$record->password;
        $parameters['custom'] = (int)$record->custom;
        $parameters['ongoing'] = (int)$record->ongoing;
        $parameters['usemaxgrade'] = (int)$record->usemaxgrade;
        $parameters['maxanswers'] = (int)$record->maxanswers;
        $parameters['maxattempts'] = (int)$record->maxattempts;
        $parameters['review'] = (int)$record->review;
        $parameters['nextpagedefault'] = (int)$record->nextpagedefault;
        $parameters['feedback'] = (int)$record->feedback;
        $parameters['minquestions'] = (int)$record->minquestions;
        $parameters['maxpages'] = (int)$record->maxpages;
        $parameters['timelimit'] = (int)$record->timelimit;
        $parameters['retake'] = (int)$record->retake;
        $parameters['progressbar'] = (int)$record->progressbar;
        $parameters['displayleft'] = (int)$record->displayleft;
        $parameters['displayleftif'] = (int)$record->displayleftif;
        $parameters['slideshow'] = (int)$record->slideshow;
        $parameters['width'] = (int)$record->width;
        $parameters['height'] = (int)$record->height;
        $parameters['bgcolor'] = (string)$record->bgcolor;
        $parameters['mediaheight'] = (int)$record->mediaheight;
        $parameters['mediawidth'] = (int)$record->mediawidth;
        $parameters['mediaclose'] = (int)$record->mediaclose;
        $parameters['available'] = (int)$record->available;
        $parameters['deadline'] = (int)$record->deadline;
        $parameters['completionendreached'] = (int)$record->completionendreached;
        $parameters['completiontimespent'] = (int)$record->completiontimespent;
        $parameters['allowofflineattempts'] = (int)$record->allowofflineattempts;

        $contextid = \context_module::instance($cm->id)->id;
        $imagelists = [
            self::extract_pluginfile_images($contextid, 'mod_lesson', 'intro', 0, (string)$record->intro),
        ];

        $pages = [];
        $pagerecords = $DB->get_records('lesson_pages', ['lessonid' => $record->id], 'id ASC');
        foreach ($pagerecords as $pagerecord) {
            $title = trim((string)$pagerecord->title);
            $contenthtml = trim((string)$pagerecord->contents);
            if ($title === '' || $contenthtml === '') {
                continue;
            }
            $imagelists[] = self::extract_pluginfile_images(
                $contextid,
                'mod_lesson',
                'page_contents',
                (int)$pagerecord->id,
                $contenthtml
            );

            $qtype = (int)$pagerecord->qtype;
            $answers = array_values($DB->get_records('lesson_answers', ['pageid' => $pagerecord->id], 'id ASC'));

            if ($qtype === LESSON_PAGE_MULTICHOICE || $qtype === LESSON_PAGE_TRUEFALSE) {
                $options = [];
                foreach ($answers as $answer) {
                    $text = trim((string)$answer->answer);
                    if ($text === '') {
                        continue;
                    }
                    $options[] = [
                        'text' => $text,
                        'correct' => ((int)$answer->score) > 0,
                        'feedback' => (string)$answer->response,
                    ];
                }
                if (empty($options)) {
                    continue;
                }
                $pages[] = [
                    'page_type' => $qtype === LESSON_PAGE_MULTICHOICE ? 'multi_choice' : 'true_false',
                    'title' => $title,
                    'content_html' => $contenthtml,
                    'options' => $options,
                ];
            } else {
                $buttons = [];
                foreach ($answers as $answer) {
                    $text = trim((string)$answer->answer);
                    if ($text === '') {
                        continue;
                    }
                    $buttons[] = ['text' => $text, 'jumpto' => (int)$answer->jumpto];
                }
                if (empty($buttons)) {
                    continue;
                }
                $pages[] = [
                    'page_type' => 'content',
                    'title' => $title,
                    'content_html' => $contenthtml,
                    'buttons' => $buttons,
                ];
            }
        }

        $parameters['mod_settings'] = ['pages' => $pages];

        $images = self::merge_images(...$imagelists);
        if (!empty($images)) {
            $parameters['images'] = $images;
        }

        return ['resource_type' => 'lesson', 'parameters' => $parameters];
    }

    /**
     * Build a mod_feedback envelope from its real DB rows (feedback_item).
     *
     * @param \cm_info $cm Course module.
     * @param int $sectionnum Destination section number.
     * @return array
     */
    private static function feedback_envelope(\cm_info $cm, int $sectionnum): array {
        global $DB;

        $record = $DB->get_record('feedback', ['id' => $cm->instance], '*', MUST_EXIST);
        $parameters = self::base_parameters($cm, $sectionnum);
        $parameters['introeditor'] = ['text' => (string)$record->intro, 'format' => (int)$record->introformat];
        $parameters['anonymous'] = (int)$record->anonymous;
        $parameters['email_notification'] = (int)$record->email_notification;
        $parameters['multiple_submit'] = (int)$record->multiple_submit;
        $parameters['autonumbering'] = (int)$record->autonumbering;
        $parameters['page_after_submit_editor'] = [
            'text' => (string)$record->page_after_submit,
            'format' => (int)$record->page_after_submitformat,
        ];
        $parameters['site_after_submit'] = (string)$record->site_after_submit;
        $parameters['publish_stats'] = (int)$record->publish_stats;
        $parameters['timeopen'] = (int)$record->timeopen;
        $parameters['timeclose'] = (int)$record->timeclose;
        $parameters['completionsubmit'] = (int)$record->completionsubmit;

        $questions = [];
        $itemrecords = $DB->get_records_select(
            'feedback_item',
            'feedback = ? AND typ != ?',
            [$record->id, 'pagebreak'],
            'position ASC'
        );
        foreach ($itemrecords as $item) {
            $questions[] = [
                'typ' => (string)$item->typ,
                'name' => (string)$item->name,
                'label' => (string)$item->label,
                'presentation' => (string)$item->presentation,
                'hasvalue' => (int)$item->hasvalue,
                'required' => (int)$item->required,
                'dependitem' => (int)$item->dependitem,
                'dependvalue' => (string)$item->dependvalue,
                'options' => (string)$item->options,
            ];
        }
        $parameters['mod_settings'] = ['questions' => $questions];

        $contextid = \context_module::instance($cm->id)->id;
        $images = self::merge_images(
            self::extract_pluginfile_images($contextid, 'mod_feedback', 'intro', 0, (string)$record->intro),
            self::extract_pluginfile_images(
                $contextid,
                'mod_feedback',
                'page_after_submit',
                0,
                (string)$record->page_after_submit
            )
        );
        if (!empty($images)) {
            $parameters['images'] = $images;
        }

        return ['resource_type' => 'feedback', 'parameters' => $parameters];
    }

    /**
     * Find every real @@PLUGINFILE@@ reference in a rich text field and
     * return one lightweight reference per resolved file - real content
     * itself is registered once (by contenthash) in self::$imageassets
     * (see that property's docblock for why) instead of being embedded
     * here, so callers only get {filename, original_filename, mimetype,
     * content_hash}.
     *
     * @param int $contextid Real context id owning the file area.
     * @param string $component Real component of the file area (e.g. mod_page).
     * @param string $filearea Real filearea of the file area (e.g. content).
     * @param int $itemid Real itemid of the file area (0 unless the field is
     *     itemid-scoped, e.g. a lesson page or a forum post).
     * @param string $text Rich text field value to scan for @@PLUGINFILE@@ tokens.
     * @return array List of {filename, original_filename, mimetype, content_hash}.
     */
    private static function extract_pluginfile_images(
        int $contextid,
        string $component,
        string $filearea,
        int $itemid,
        string $text
    ): array {
        if ($text === '' || strpos($text, '@@PLUGINFILE@@/') === false) {
            return [];
        }
        if (!preg_match_all('/@@PLUGINFILE@@\/([^"\'\s]+)/', $text, $matches)) {
            return [];
        }

        $fs = get_file_storage();
        $images = [];
        foreach (array_unique($matches[1]) as $rawfilename) {
            $filename = rawurldecode($rawfilename);
            $file = $fs->get_file($contextid, $component, $filearea, $itemid, '/', $filename);
            if (!$file || $file->is_directory()) {
                continue;
            }

            $contenthash = $file->get_contenthash();
            if (!isset(self::$imageassets[$contenthash])) {
                self::$imageassets[$contenthash] = [
                    'mimetype' => (string)$file->get_mimetype(),
                    'content_base64' => base64_encode($file->get_content()),
                ];
            }

            $images[] = [
                'filename' => $filename,
                'original_filename' => $filename,
                'mimetype' => (string)$file->get_mimetype(),
                'content_hash' => $contenthash,
            ];
        }

        return $images;
    }

    /**
     * Merge several image lists built by extract_pluginfile_images(),
     * de-duplicated by original_filename (first occurrence wins).
     *
     * @param array ...$imagelists One or more lists of image entries.
     * @return array Merged, de-duplicated list.
     */
    private static function merge_images(array ...$imagelists): array {
        $merged = [];
        $seen = [];
        foreach ($imagelists as $imagelist) {
            foreach ($imagelist as $image) {
                $key = $image['original_filename'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $merged[] = $image;
            }
        }
        return $merged;
    }

    /**
     * Unserialize a resourcelib displayoptions column (mod_page/mod_resource).
     *
     * @param string|null $serialized Raw displayoptions column value.
     * @return array
     */
    private static function unserialize_display_options(?string $serialized): array {
        if (empty($serialized)) {
            return [];
        }
        $options = @unserialize($serialized, ['allowed_classes' => false]);
        return is_array($options) ? $options : [];
    }
}
