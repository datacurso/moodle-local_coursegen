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
use local_coursegen\local\models\template_section;
use local_coursegen\local\models\template_activity;

defined('MOODLE_INTERNAL') || die();

/**
 * Orchestrates "create course from template": builds a new course out of a
 * template's base course, importing sections/activities marked "keep" as-is
 * (via the core backup/restore import mechanism), regenerating "modify"
 * activities and professor-added new activities through the AI (currently
 * mocked, see mock_template_ai_service), and skipping "exclude"/"reference"
 * activities entirely from the final course.
 *
 * The client is never trusted for behavior/action: this service re-reads
 * template_section/template_activity from the database as the single source
 * of truth, only accepting from the caller what the server cannot infer by
 * itself (new sections/activities the professor added on top of the
 * template).
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_course_builder_service {
    /**
     * Fully-qualified class name of the AI service used to generate template
     * activity content. Centralised here (instead of repeating the literal
     * class name at every call site) so swapping the mock for the real AI
     * backend is a single-line change, regardless of what the replacement
     * class is named.
     *
     * @var string
     */
    private const AI_SERVICE_CLASS = mock_template_ai_service::class;

    /**
     * Create a course from a template.
     *
     * @param template $template Template persistent (already loaded).
     * @param array $newsections New sections added by the professor: [['clientid' => int, 'name' => string], ...].
     * @param array $newactivities New activities added by the professor:
     *     [['sectionid' => int, 'modname' => string], ...]. sectionid > 0 is a real base-course section id
     *     (must be behavior=custom); sectionid < 0 references a newsections clientid.
     * @param int $userid User performing the creation (used for the backup/restore operations).
     * @return array Result: success, courseid, courseurl, fullname, shortname, message,
     *     partial, haswarnings, warningscount, activityerrors.
     */
    public static function create_course_from_template(
        template $template,
        array $newsections,
        array $newactivities,
        int $userid
    ): array {
        global $CFG, $DB;

        try {
            \core_php_time_limit::raise();
            raise_memory_limit(MEMORY_EXTRA);
            // Release the session so other tabs are not blocked during the
            // (potentially slow) synchronous backup/restore pass.
            \core\session\manager::write_close();

            require_once($CFG->dirroot . '/course/lib.php');
            require_once($CFG->dirroot . '/course/modlib.php');
            require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
            require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

            $templateid = (int) $template->get('id');
            $sourcecourseid = (int) $template->get('courseid');
            $sourcecourse = get_course($sourcecourseid);

            // 1. Re-read the ground truth for behavior/action — never trust the client.
            $sectionbehaviors = self::load_section_behaviors($templateid);
            $activityactions = self::load_activity_actions($templateid);

            // 2. Create the destination course: empty, hidden until fully built.
            $newcourse = self::create_empty_course($template, $sourcecourse);
            // create_course() may auto-create a default "Announcements" forum
            // in section 0 (site "newcourse" default); remove it so section 0
            // starts genuinely empty before the keep/import pass below —
            // otherwise it would leak into the final course regardless of
            // what the template says about the base course's section 0.
            self::clear_default_activities($newcourse->id);

            // 3. Import everything "keep" (whole sections, or individual
            // activities inside "custom" sections) via backup/restore, and
            // resolve the resulting section-number map in the same pass
            // (id-mapping temp tables are dropped by backup/restore's own
            // cleanup step before control returns here, so the mapping is
            // derived positionally instead — see import_kept_content()).
            $imported = self::import_kept_content(
                $sourcecourse,
                $newcourse->id,
                $sectionbehaviors,
                $activityactions,
                $userid
            );
            $sectionnummap = $imported['sectionnums'];

            $activityerrors = [];

            // 5. Create the brand-new sections the professor added, capped to
            // however many the template's section limit still allows.
            $clientsectionmap = self::create_new_sections(
                $newcourse->id,
                $newsections,
                $template,
                $sectionnummap,
                $activityerrors
            );

            // 6. Regenerate "modify" activities (mock AI), with per-activity reference context.
            self::process_modify_activities(
                $sourcecourse,
                $newcourse,
                $sectionbehaviors,
                $activityactions,
                $sectionnummap,
                $activityerrors
            );

            // 7. Create the professor's newly added activities (mock AI, no reference content).
            $clientsectionnames = self::index_new_section_names($newsections);
            self::process_new_activities(
                $newactivities,
                $newcourse,
                $sourcecourse,
                $sectionbehaviors,
                $sectionnummap,
                $clientsectionnames,
                $clientsectionmap,
                $template,
                $activityerrors
            );

            // 8. Remove any stray empty section Moodle's own course-format
            // machinery may have auto-created to keep section numbering
            // contiguous (observed after backup/restore skips an excluded
            // section in the middle of the sequence) — never a section this
            // service intentionally populated or the professor added.
            self::cleanup_stray_sections($newcourse->id, $sectionnummap, $clientsectionmap);

            // 9. Integrity repair + cache stabilisation, then publish.
            self::repair_course_structure($newcourse->id);
            $DB->set_field('course', 'visible', 1, ['id' => $newcourse->id]);
            self::repair_course_structure($newcourse->id);

            return [
                'success' => true,
                'courseid' => $newcourse->id,
                'courseurl' => course_get_url($newcourse->id)->out(),
                'fullname' => $newcourse->fullname,
                'shortname' => $newcourse->shortname,
                'message' => get_string('coursecreated', 'local_coursegen'),
                'partial' => !empty($activityerrors),
                'haswarnings' => !empty($activityerrors),
                'warningscount' => count($activityerrors),
                'activityerrors' => $activityerrors,
            ];
        } catch (\Throwable $e) {
            // If the destination course was already created before whatever
            // failed later, do not leave it orphaned (visible=0, unreachable,
            // no reference anywhere) — remove it before reporting the error.
            $orphancourseid = (isset($newcourse) && !empty($newcourse->id)) ? (int) $newcourse->id : 0;
            if ($orphancourseid > 0) {
                try {
                    require_once($CFG->dirroot . '/course/lib.php');
                    delete_course($orphancourseid, false);
                } catch (\Throwable $deleteexception) {
                    debugging(
                        'local_coursegen: could not delete orphaned course ' . $orphancourseid
                        . ' after failed template build. ' . $deleteexception->getMessage(),
                        DEBUG_DEVELOPER
                    );
                }
            }
            debugging(
                'local_coursegen: create_course_from_template failed'
                . ($orphancourseid > 0 ? ' (orphaned course ' . $orphancourseid . ' deleted)' : '')
                . '. ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return [
                'success' => false,
                'courseid' => 0,
                'courseurl' => '',
                'fullname' => '',
                'shortname' => '',
                'message' => $e->getMessage(),
                'partial' => false,
                'haswarnings' => false,
                'warningscount' => 0,
                'activityerrors' => [],
            ];
        }
    }

    /**
     * Load the saved behavior for every section of the template's base course.
     *
     * @param int $templateid Template ID.
     * @return array<int,string> sectionid (base course) => behavior.
     */
    private static function load_section_behaviors(int $templateid): array {
        $map = [];
        foreach (template_section::get_records(['templateid' => $templateid]) as $record) {
            $map[(int) $record->get('sectionid')] = (string) $record->get('behavior');
        }
        return $map;
    }

    /**
     * Load the saved action/reference flag/prompt for every activity of the template's base course.
     *
     * @param int $templateid Template ID.
     * @return array<int,array{action:string,useasreference:bool,prompt:string}> cmid (base course) => info.
     */
    private static function load_activity_actions(int $templateid): array {
        $map = [];
        foreach (template_activity::get_records(['templateid' => $templateid]) as $record) {
            $map[(int) $record->get('cmid')] = [
                'action' => (string) $record->get('action'),
                'useasreference' => (bool) $record->get('useasreference'),
                'prompt' => (string) ($record->get('prompt') ?? ''),
            ];
        }
        return $map;
    }

    /**
     * Create the empty, hidden destination course.
     *
     * @param template $template Template persistent.
     * @param \stdClass $sourcecourse Base course record.
     * @return \stdClass Newly created course record.
     */
    private static function create_empty_course(template $template, \stdClass $sourcecourse): \stdClass {
        global $DB;

        $coursedata = new \stdClass();
        $templatename = trim((string) $template->get('name'));
        $fullname = ($templatename !== '' ? $templatename : get_string('createwithai', 'local_coursegen'))
            . ' - ' . userdate(time(), '%d %b %Y');
        $coursedata->fullname = (string) \core_text::substr($fullname, 0, 255);
        $coursedata->shortname = self::build_unique_shortname($templatename !== '' ? $templatename : 'course');

        $category = (int) $DB->get_field('course', 'category', ['id' => $sourcecourse->id]);
        if (empty($category)) {
            $defaultcategory = \core_course_category::get_default();
            $category = $defaultcategory ? (int) $defaultcategory->id : 1;
        }
        $coursedata->category = $category;
        $coursedata->visible = 0;
        if (!empty($sourcecourse->format)) {
            $coursedata->format = $sourcecourse->format;
        }

        return create_course($coursedata);
    }

    /**
     * Build a unique course shortname from a base string.
     *
     * @param string $base Base text (e.g. the template name).
     * @return string
     */
    private static function build_unique_shortname(string $base): string {
        global $DB;

        $base = trim($base) !== '' ? trim($base) : 'course';
        $base = (string) \core_text::substr($base, 0, 80);

        $candidate = (string) \core_text::substr($base . '-' . time(), 0, 100);
        $suffix = 1;
        while ($DB->record_exists('course', ['shortname' => $candidate])) {
            $candidate = (string) \core_text::substr($base . '-' . time() . '-' . $suffix, 0, 100);
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Remove every course module a fresh course was created with (e.g. the
     * site "newcourse" default "Announcements" forum some sites auto-create
     * in section 0), so the destination course starts genuinely empty.
     *
     * @param int $courseid Course ID.
     * @return void
     */
    private static function clear_default_activities(int $courseid): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        foreach ($DB->get_records('course_modules', ['course' => $courseid], '', 'id') as $record) {
            try {
                course_delete_module((int) $record->id);
            } catch (\Throwable $e) {
                debugging('local_coursegen: could not remove default course module. ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Import, via core backup/restore (the only supported way to clone
     * course_modules cross-course), everything marked "keep": whole sections
     * with behavior=keep, and individual activities with action=keep inside
     * behavior=custom sections.
     *
     * Sections with behavior=exclude are excluded from the backup plan
     * entirely. Sections with behavior=custom stay included as containers
     * (so they exist, in the right relative order, ready to receive
     * "modify"/new activities afterwards) but only their action=keep
     * activities travel through backup/restore; every other activity in them
     * is excluded from the plan and is instead (re)created directly by
     * process_modify_activities()/process_new_activities().
     *
     * The resulting old->new section/activity mapping is resolved
     * positionally (destination rows ordered by id/section, zipped against
     * the base course's included sections/activities in their original
     * relative order) rather than through backup/restore's id-mapping temp
     * tables: those tables are dropped by backup/restore's own final cleanup
     * step before execute_plan() returns control here, so they are not
     * reliably readable afterwards. This relies on the destination course
     * being empty (only section 0, no course modules) before this call —
     * guaranteed by create_empty_course() + clear_default_activities().
     *
     * @param \stdClass $sourcecourse Base course record.
     * @param int $destcourseid Destination course ID.
     * @param array $sectionbehaviors sectionid (base) => behavior.
     * @param array $activityactions cmid (base) => info (see load_activity_actions()).
     * @param int $userid User performing the operation.
     * @return array{sectionnums:array<int,?int>} sectionid (base) => sectionnum (dest)|null.
     */
    private static function import_kept_content(
        \stdClass $sourcecourse,
        int $destcourseid,
        array $sectionbehaviors,
        array $activityactions,
        int $userid
    ): array {
        $modinfo = get_fast_modinfo($sourcecourse);

        $includesection = [];
        $includeactivity = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            $sectionid = (int) $section->id;
            $behavior = $sectionbehaviors[$sectionid] ?? 'custom';
            $included = ($behavior !== 'exclude');
            $includesection[$sectionid] = $included;

            if (!$included || empty($modinfo->sections[$section->section])) {
                continue;
            }

            foreach ($modinfo->sections[$section->section] as $cmid) {
                $cmid = (int) $cmid;
                if ($behavior === 'keep') {
                    $includeactivity[$cmid] = true;
                    continue;
                }
                // Custom section: only its action=keep activities travel through backup/restore.
                $action = $activityactions[$cmid]['action'] ?? 'modify';
                $includeactivity[$cmid] = ($action === 'keep');
            }
        }

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $sourcecourse->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $userid
        );

        $plan = $bc->get_plan();
        foreach ($includesection as $sectionid => $included) {
            if (!$included) {
                self::set_backup_setting_safe($plan, 'section_' . $sectionid . '_included', 0);
            }
        }
        foreach ($modinfo->get_cms() as $cm) {
            $cmid = (int) $cm->id;
            if (empty($includeactivity[$cmid])) {
                self::set_backup_setting_safe($plan, $cm->modname . '_' . $cmid . '_included', 0);
            }
        }

        $bc->execute_plan();
        $backupid = $bc->get_backupid();
        // Same pattern core_course_external::import_course() uses: capture the
        // temp backup basepath so it can be cleaned up below regardless of
        // whether the restore precheck fails or the import succeeds.
        $backupbasepath = $bc->get_plan()->get_basepath();
        $bc->destroy();

        $rc = new \restore_controller(
            $backupid,
            $destcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $userid,
            \backup::TARGET_EXISTING_ADDING
        );

        if (!$rc->execute_precheck()) {
            $results = $rc->get_precheck_results();
            $rc->destroy();
            fulldelete($backupbasepath);
            throw new \Exception('Restore precheck failed while importing template content: '
                . self::describe_precheck_errors($results));
        }

        $rc->execute_plan();
        $rc->destroy();
        fulldelete($backupbasepath);

        // Restoring (MODE_IMPORT / TARGET_EXISTING_ADDING) onto a destination
        // course that starts with only an empty section 0 preserves each
        // included section's ORIGINAL section number verbatim (there is never
        // a destination section already occupying that number to shift around
        // — confirmed empirically: content lands in the section matching its
        // source number, not in creation-order position). So the destination
        // number for an included section is simply its own base number.
        $sectionnummap = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            $sid = (int) $section->id;
            $sectionnummap[$sid] = !empty($includesection[$sid]) ? (int) $section->section : null;
        }
        return ['sectionnums' => $sectionnummap];
    }

    /**
     * Set one backup plan setting, tolerating settings that don't exist or
     * cannot be changed (e.g. dependency-locked) without aborting the import.
     *
     * @param \backup_plan $plan Backup plan.
     * @param string $name Setting name.
     * @param mixed $value New value.
     * @return void
     */
    private static function set_backup_setting_safe($plan, string $name, $value): void {
        try {
            $plan->get_setting($name)->set_value($value);
        } catch (\Throwable $e) {
            debugging('local_coursegen: could not set backup setting ' . $name . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Build a short human-readable message out of restore precheck results.
     *
     * @param array $results Precheck results (errors/warnings).
     * @return string
     */
    private static function describe_precheck_errors(array $results): string {
        $errors = $results['errors'] ?? [];
        if (empty($errors)) {
            return 'unknown restore precheck error';
        }
        return implode('; ', array_map('strval', $errors));
    }

    /**
     * Index the professor-added new sections' names by their client id, used
     * only to give new activities placed into a brand-new section a
     * sensible default title (see mock_template_ai_service::default_title()).
     *
     * @param array $newsections [['clientid' => int, 'name' => string], ...].
     * @return array<int,string> clientid => name.
     */
    private static function index_new_section_names(array $newsections): array {
        $names = [];
        foreach ($newsections as $newsection) {
            $clientid = (int) ($newsection['clientid'] ?? 0);
            if ($clientid < 0) {
                $names[$clientid] = trim((string) ($newsection['name'] ?? ''));
            }
        }
        return $names;
    }

    /**
     * Create the brand-new sections the professor added on top of the
     * template, capped to the template's configured section limit.
     *
     * @param int $destcourseid Destination course ID.
     * @param array $newsections [['clientid' => int, 'name' => string], ...].
     * @param template $template Template persistent (for maxsections/nolimit).
     * @param array $sectionnummap sectionid (base) => sectionnum (dest)|null, used to count
     *     how many template sections are already present in the destination course — the
     *     same count get_template_structure.php uses to compute "remainingsections".
     * @param array $activityerrors Error accumulator (by reference); sections beyond the
     *     limit are reported here instead of aborting the rest.
     * @return array<int,int> clientid => new section number.
     */
    private static function create_new_sections(
        int $destcourseid,
        array $newsections,
        template $template,
        array $sectionnummap,
        array &$activityerrors
    ): array {
        global $DB;

        if (empty($newsections)) {
            return [];
        }

        $nolimit = (bool) $template->get('nolimit');
        $maxsections = (int) ($template->get('maxsections') ?? 0);
        $currentsections = count(array_filter($sectionnummap, static fn($num) => $num !== null));
        $remaining = $nolimit ? PHP_INT_MAX : max(0, $maxsections - $currentsections);

        $maxsection = (int) $DB->get_field_sql(
            'SELECT MAX(section) FROM {course_sections} WHERE course = ?',
            [$destcourseid]
        );

        $map = [];
        $next = $maxsection + 1;
        $created = 0;
        foreach ($newsections as $newsection) {
            $clientid = (int) ($newsection['clientid'] ?? 0);
            if ($clientid >= 0) {
                // Only client-placeholder (negative) ids are meaningful new sections.
                continue;
            }
            $name = trim((string) ($newsection['name'] ?? ''));
            if ($created >= $remaining) {
                $activityerrors[] = [
                    'resource_type' => 'section',
                    'section' => 0,
                    'message' => 'New section exceeds the template section limit and was not created.',
                    'title' => $name,
                ];
                continue;
            }

            $sectiondata = new \stdClass();
            $sectiondata->course = $destcourseid;
            $sectiondata->section = $next;
            $sectiondata->name = $name;
            $sectiondata->summary = '';
            $sectiondata->summaryformat = FORMAT_HTML;
            $sectiondata->sequence = '';
            $sectiondata->visible = 1;
            $sectiondata->availability = null;
            $sectiondata->timemodified = time();
            $DB->insert_record('course_sections', $sectiondata);

            $map[$clientid] = $next;
            $next++;
            $created++;
        }

        if (!empty($map)) {
            $course = get_course($destcourseid);
            $courseformat = course_get_format($course);
            $formatoptions = $courseformat->get_format_options();
            if (isset($formatoptions['numsections']) && ($next - 1) > (int) $formatoptions['numsections']) {
                $courseformat->update_course_format_options(['numsections' => $next - 1]);
            }
            rebuild_course_cache($destcourseid, true);
        }

        return $map;
    }

    /**
     * Regenerate every action=modify activity (mock AI for now), inside
     * behavior=custom sections only — kept sections are already imported
     * verbatim and never processed activity-by-activity here.
     *
     * @param \stdClass $sourcecourse Base course record.
     * @param \stdClass $newcourse Destination course record.
     * @param array $sectionbehaviors sectionid (base) => behavior.
     * @param array $activityactions cmid (base) => info.
     * @param array $sectionnummap sectionid (base) => sectionnum (dest)|null.
     * @param array $activityerrors Error accumulator (by reference).
     * @return void
     */
    private static function process_modify_activities(
        \stdClass $sourcecourse,
        \stdClass $newcourse,
        array $sectionbehaviors,
        array $activityactions,
        array $sectionnummap,
        array &$activityerrors
    ): void {
        $modinfo = get_fast_modinfo($sourcecourse);

        foreach ($modinfo->get_section_info_all() as $section) {
            $sectionid = (int) $section->id;
            $behavior = $sectionbehaviors[$sectionid] ?? 'custom';
            if ($behavior !== 'custom') {
                // Kept sections travel verbatim; excluded sections don't exist in the new course.
                continue;
            }
            $destsectionnum = $sectionnummap[$sectionid] ?? null;
            if ($destsectionnum === null || empty($modinfo->sections[$section->section])) {
                continue;
            }

            $sectionname = get_section_name($sourcecourse, $section);

            foreach ($modinfo->sections[$section->section] as $cmid) {
                $cm = $modinfo->cms[$cmid] ?? null;
                if (!$cm || !$cm->uservisible) {
                    continue;
                }
                $cmid = (int) $cmid;
                $meta = $activityactions[$cmid] ?? null;
                $action = $meta['action'] ?? 'modify';
                if ($action !== 'modify') {
                    // keep -> already imported; exclude/reference -> never materialize.
                    continue;
                }

                // Reference content is specific to THIS activity: its own
                // content (only if its own useasreference flag is set) plus
                // its sibling activities explicitly marked action=reference —
                // never content from other keep/modify siblings, regardless
                // of their own useasreference flag.
                $referencecontent = self::build_activity_reference_content(
                    $modinfo,
                    (int) $section->section,
                    $cmid,
                    $activityactions
                );

                try {
                    $payload = [
                        'modname' => $cm->modname,
                        'sectionname' => $sectionname,
                        'prompt' => (string) ($meta['prompt'] ?? ''),
                        'referencecontent' => $referencecontent,
                        'lang' => current_language(),
                        'title' => (string) format_string($cm->name),
                    ];
                    $aiserviceclass = self::AI_SERVICE_CLASS;
                    $generated = $aiserviceclass::generate($payload);
                    create_mod_service::create_from_ai_result($generated, $newcourse, $destsectionnum);
                } catch (\Throwable $e) {
                    $activityerrors[] = [
                        'resource_type' => $cm->modname,
                        'section' => $destsectionnum,
                        'message' => $e->getMessage(),
                        'title' => (string) format_string($cm->name),
                    ];
                    debugging('local_coursegen: template modify-activity failed. ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }
    }

    /**
     * Build the reference-context text for ONE specific "modify" activity:
     * its own content (only if its own useasreference flag is set) plus its
     * sibling activities in the same section explicitly marked
     * action=reference. Never includes content from other keep/modify
     * siblings — their own useasreference flag only governs whether THEIR
     * OWN content is used as reference for themselves, it never propagates
     * to other activities being regenerated in the same section.
     *
     * @param \course_modinfo $modinfo Base course modinfo.
     * @param int $sectionnum Base course section number.
     * @param int $cmid The activity (base course module id) being regenerated.
     * @param array $activityactions cmid (base) => info.
     * @return string
     */
    private static function build_activity_reference_content(
        \course_modinfo $modinfo,
        int $sectionnum,
        int $cmid,
        array $activityactions
    ): string {
        if (empty($modinfo->sections[$sectionnum])) {
            return '';
        }

        $parts = [];

        $ownmeta = $activityactions[$cmid] ?? null;
        if (!empty($ownmeta['useasreference'] ?? false)) {
            $owncm = $modinfo->cms[$cmid] ?? null;
            if ($owncm && $owncm->uservisible) {
                $parts[] = self::extract_reference_snippet($owncm);
            }
        }

        foreach ($modinfo->sections[$sectionnum] as $siblingcmid) {
            $siblingcmid = (int) $siblingcmid;
            if ($siblingcmid === $cmid) {
                // The activity's own content is only ever included via the
                // useasreference check above, never duplicated here.
                continue;
            }
            $cm = $modinfo->cms[$siblingcmid] ?? null;
            if (!$cm || !$cm->uservisible) {
                continue;
            }
            $meta = $activityactions[$siblingcmid] ?? null;
            $action = $meta['action'] ?? 'modify';
            if ($action === 'reference') {
                $parts[] = self::extract_reference_snippet($cm);
            }
        }

        return implode("\n", array_filter($parts));
    }

    /**
     * Extract a short, best-effort text snippet describing an activity, used
     * as reference context for the mock AI. Falls back to just the activity
     * name when the module has no readable 'intro' field.
     *
     * @param \cm_info $cm Course module info.
     * @return string
     */
    private static function extract_reference_snippet(\cm_info $cm): string {
        global $DB;

        $text = trim((string) format_string($cm->name));
        try {
            $dbman = $DB->get_manager();
            $table = new \xmldb_table($cm->modname);
            if ($dbman->table_exists($table) && $dbman->field_exists($table, new \xmldb_field('intro'))) {
                $intro = (string) $DB->get_field($cm->modname, 'intro', ['id' => $cm->instance]);
                $intro = trim(html_to_text($intro, 0));
                if ($intro !== '') {
                    $text .= ': ' . \core_text::substr($intro, 0, 400);
                }
            }
        } catch (\Throwable $e) {
            // Best-effort only — the mock still works with just the activity name.
            debugging('local_coursegen: could not extract reference snippet. ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return $text;
    }

    /**
     * Decode a template's configured "allowedtypes" (modnames the professor
     * may add on top of the template), mirroring get_template_structure.php's
     * decoding of the same persistent field.
     *
     * @param template $template Template persistent.
     * @return string[] Allowed modnames (possibly empty if unconfigured).
     */
    private static function decode_allowed_types(template $template): array {
        $raw = $template->get('allowedtypes');
        if (empty($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Create the professor's newly added activities (chooser picks), each
     * targeting either an existing behavior=custom base section, or a
     * brand-new section created by create_new_sections().
     *
     * @param array $newactivities [['sectionid' => int, 'modname' => string], ...].
     * @param \stdClass $newcourse Destination course record.
     * @param \stdClass $sourcecourse Base course record.
     * @param array $sectionbehaviors sectionid (base) => behavior.
     * @param array $sectionnummap sectionid (base) => sectionnum (dest)|null.
     * @param array $clientsectionnames clientid => section name (see index_new_section_names()).
     * @param array $clientsectionmap clientid => sectionnum (dest).
     * @param template $template Template persistent (for allowedtypes).
     * @param array $activityerrors Error accumulator (by reference).
     * @return void
     */
    private static function process_new_activities(
        array $newactivities,
        \stdClass $newcourse,
        \stdClass $sourcecourse,
        array $sectionbehaviors,
        array $sectionnummap,
        array $clientsectionnames,
        array $clientsectionmap,
        template $template,
        array &$activityerrors
    ): void {
        $allowedtypes = self::decode_allowed_types($template);

        foreach ($newactivities as $newactivity) {
            $modname = clean_param((string) ($newactivity['modname'] ?? ''), PARAM_PLUGIN);
            $rawsectionid = (int) ($newactivity['sectionid'] ?? 0);
            if ($modname === '' || $rawsectionid === 0) {
                continue;
            }

            if (!in_array($modname, $allowedtypes, true)) {
                $activityerrors[] = [
                    'resource_type' => $modname,
                    'section' => 0,
                    'message' => 'Activity type is not allowed by the template configuration.',
                    'title' => $modname,
                ];
                continue;
            }

            $destsectionnum = null;
            $sectionname = '';
            if ($rawsectionid > 0) {
                $behavior = $sectionbehaviors[$rawsectionid] ?? 'custom';
                if ($behavior === 'custom') {
                    $destsectionnum = $sectionnummap[$rawsectionid] ?? null;
                    $sectionname = self::describe_base_section_name($sourcecourse, $rawsectionid);
                }
            } else {
                $destsectionnum = $clientsectionmap[$rawsectionid] ?? null;
                $sectionname = $clientsectionnames[$rawsectionid] ?? '';
            }

            if ($destsectionnum === null) {
                $activityerrors[] = [
                    'resource_type' => $modname,
                    'section' => 0,
                    'message' => 'Target section for the new activity could not be resolved.',
                    'title' => $modname,
                ];
                continue;
            }

            try {
                $payload = [
                    'modname' => $modname,
                    'sectionname' => $sectionname,
                    'prompt' => '',
                    'referencecontent' => '',
                    'lang' => current_language(),
                    'title' => '',
                ];
                $aiserviceclass = self::AI_SERVICE_CLASS;
                $generated = $aiserviceclass::generate($payload);
                create_mod_service::create_from_ai_result($generated, $newcourse, $destsectionnum);
            } catch (\Throwable $e) {
                $activityerrors[] = [
                    'resource_type' => $modname,
                    'section' => $destsectionnum,
                    'message' => $e->getMessage(),
                    'title' => $modname,
                ];
                debugging('local_coursegen: template new-activity failed. ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Look up a base-course section's display name by its section id.
     *
     * @param \stdClass $course Base course record.
     * @param int $sectionid Section ID.
     * @return string
     */
    private static function describe_base_section_name(\stdClass $course, int $sectionid): string {
        global $DB;

        $section = $DB->get_record('course_sections', ['id' => $sectionid, 'course' => $course->id]);
        if (!$section) {
            return '';
        }
        return get_section_name($course, $section);
    }

    /**
     * Delete any section (section > 0, empty) that is not one of the numbers
     * this service deliberately produced: a base-course section that resolved
     * a destination number, or a brand-new section the professor added.
     *
     * Moodle's own course-format section-creation machinery can, in some
     * course formats, auto-create an empty placeholder section to keep
     * section numbering contiguous when backup/restore skips an excluded
     * section in the middle of the sequence — that placeholder must never
     * surface to the professor as part of the generated course.
     *
     * @param int $courseid Destination course ID.
     * @param array $sectionnummap sectionid (base) => sectionnum (dest)|null.
     * @param array $clientsectionmap clientid => sectionnum (dest).
     * @return void
     */
    private static function cleanup_stray_sections(int $courseid, array $sectionnummap, array $clientsectionmap): void {
        global $DB;

        $legitimate = [0];
        foreach (array_merge(array_values($sectionnummap), array_values($clientsectionmap)) as $num) {
            if ($num !== null) {
                $legitimate[] = (int) $num;
            }
        }
        $legitimate = array_flip($legitimate);

        $sections = $DB->get_records_select('course_sections', 'course = ? AND section > 0', [$courseid]);
        $stray = [];
        foreach ($sections as $section) {
            if (isset($legitimate[(int) $section->section])) {
                continue;
            }
            if (trim((string) ($section->sequence ?? '')) !== '') {
                // Not actually empty — do not risk destroying real content.
                debugging(
                    'local_coursegen: unexpected non-empty stray section ' . $section->id . ' left untouched.',
                    DEBUG_DEVELOPER
                );
                continue;
            }
            $stray[] = $section;
        }

        // course_delete_section() -> base::delete_section() calls
        // move_section_to() BEFORE deleting, which renumbers every section
        // AFTER the one being removed. Deleting from the highest stray
        // section number down to the lowest means each deletion can only
        // ever shift sections that are either already processed or were
        // never stray to begin with — a not-yet-processed stray section's
        // number is never affected by an earlier deletion in this loop.
        usort($stray, static fn($a, $b) => (int) $b->section <=> (int) $a->section);

        foreach ($stray as $section) {
            try {
                course_delete_section($courseid, $section, false, false);
            } catch (\Throwable $e) {
                debugging('local_coursegen: could not remove stray section ' . $section->id . '. ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Remove invalid course module ids from section sequences and stabilize
     * modinfo/course-cache state. A light version of create_course_service's
     * equivalent integrity pass, specific to this service.
     *
     * @param int $courseid Course ID.
     * @return void
     */
    private static function repair_course_structure(int $courseid): void {
        global $DB;

        $validcmids = [];
        foreach ($DB->get_records('course_modules', ['course' => $courseid], '', 'id') as $record) {
            $validcmids[(int) $record->id] = true;
        }

        $sections = $DB->get_records('course_sections', ['course' => $courseid]);
        foreach ($sections as $section) {
            $raw = trim((string) ($section->sequence ?? ''));
            if ($raw === '') {
                continue;
            }
            $ids = array_filter(array_map('intval', explode(',', $raw)), static fn($id) => $id > 0);
            $filtered = array_values(array_filter($ids, static fn($id) => isset($validcmids[$id])));
            $newsequence = implode(',', $filtered);
            if ($newsequence !== $raw) {
                $DB->set_field('course_sections', 'sequence', $newsequence, ['id' => $section->id]);
            }
        }

        \course_modinfo::clear_instance_cache($courseid);
        rebuild_course_cache($courseid, true);
        rebuild_course_cache($courseid, false);
        \course_modinfo::clear_instance_cache($courseid);
    }
}
