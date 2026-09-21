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
        if ($cm->modname === 'forum') {
            return self::forum_parameters($cm);
        }
        if ($cm->modname === 'book') {
            return self::book_parameters($cm);
        }
        if ($cm->modname === 'wiki') {
            return self::wiki_parameters($cm);
        }
        if ($cm->modname === 'data') {
            return self::data_parameters($cm);
        }
        return [
            'name' => $cm->name,
            'section' => (int) $cm->sectionnum,
        ];
    }

    /** @var string[] Every template column mod_data really owns (see its install.xml). */
    private const DATA_TEMPLATE_COLUMNS = [
        'singletemplate', 'listtemplate', 'listtemplateheader', 'listtemplatefooter',
        'addtemplate', 'rsstemplate', 'rsstitletemplate', 'csstemplate', 'jstemplate',
        'asearchtemplate',
    ];

    /** @var string[] Field types whose value cannot be seeded back (their content is a file). */
    private const DATA_UNSEEDABLE_TYPES = ['file', 'picture'];

    /**
     * A Database's raw description, every instance setting, and its structure.
     *
     * Three traps of mod_data's schema shape this branch:
     *
     * - defaultsort stores a data_fields.id of THIS database. Reused as is it
     *   would point at a foreign row (or at nothing) in the generated one, so
     *   it never travels: the NAME of that field does, under defaultsortfield,
     *   and data_settings maps it back to the new field id.
     * - data_add_instance zeroes the rating window unless ratingtime says it is
     *   in use, exactly as mod_forum does, so that flag travels with the dates
     *   instead of being inferred on the way back in.
     * - The field definitions live in param1..param10, but only param1..param5
     *   ever reach data_field_base::define_field(). They all travel anyway; the
     *   consumer writes the last five straight onto the row.
     *
     * @param cm_info $cm
     * @return array
     */
    private static function data_parameters(cm_info $cm): array {
        global $DB;

        $data = $DB->get_record('data', ['id' => $cm->instance]);
        if (!$data) {
            return ['name' => $cm->name, 'section' => (int) $cm->sectionnum];
        }

        $dataid = (int) $data->id;
        $fields = $DB->get_records('data_fields', ['dataid' => $dataid], 'id ASC');

        $parameters = array_merge(
            self::data_settings_columns($data),
            [
                'name' => $cm->name,
                'section' => (int) $cm->sectionnum,
                'intro' => $data->intro ?? '',
                'ratingtime' => (!empty($data->assesstimestart) && !empty($data->assesstimefinish)) ? 1 : 0,
                'defaultsortfield' => self::data_default_sort_field($fields, (int) ($data->defaultsort ?? 0)),
            ]
        );

        $collections = [];
        if ($fields) {
            $collections['fields'] = self::data_fields($fields);
        }
        $templates = self::data_templates($data);
        if ($templates) {
            $collections['templates'] = $templates;
        }
        $entries = self::data_example_entries($dataid, $fields);
        if ($entries) {
            $collections['example_entries'] = $entries;
        }
        // The sort field is a top-level setting, but only data_settings can resolve the name
        // into the new field's id - and mod_settings is all create_mod_service hands it - so
        // it travels there too, with the direction it has to apply alongside it.
        if ($parameters['defaultsortfield'] !== '') {
            $collections['defaultsortfield'] = $parameters['defaultsortfield'];
            $collections['defaultsortdir'] = (int) ($data->defaultsortdir ?? 0);
        }
        if ($collections) {
            $parameters['mod_settings'] = $collections;
        }

        return $parameters;
    }

    /**
     * The mod_data settings worth reproducing on the generated activity.
     *
     * The whole scope of the type travels (grading, entries, dates, access,
     * display and completion); identity and placement columns (id, course,
     * name, timemodified, config, defaultsort) are left out on purpose - they
     * describe THIS database, never the new one.
     *
     * @param \stdClass $data
     * @return array
     */
    private static function data_settings_columns($data): array {
        $fields = [
            'approval', 'manageapproved', 'comments',
            'requiredentries', 'requiredentriestoview', 'maxentries',
            'timeavailablefrom', 'timeavailableto', 'timeviewfrom', 'timeviewto',
            'editany', 'notification', 'completionentries',
            'assessed', 'scale', 'assesstimestart', 'assesstimefinish',
            'defaultsortdir', 'rssarticles',
        ];
        $settings = [];
        foreach ($fields as $field) {
            if (isset($data->$field)) {
                $settings[$field] = $data->$field;
            }
        }
        return $settings;
    }

    /**
     * The NAME of the field the mold sorts by, or '' when it sorts by time added.
     *
     * @param array $fields data_fields rows, keyed by id.
     * @param int $defaultsort The mold's data.defaultsort (a data_fields.id).
     * @return string
     */
    private static function data_default_sort_field(array $fields, int $defaultsort): string {
        if ($defaultsort <= 0 || !isset($fields[$defaultsort])) {
            return '';
        }
        return (string) $fields[$defaultsort]->name;
    }

    /**
     * Every field of one database, in creation order, definition included.
     *
     * The params are the definition itself (choices, sizes, autolink, ...) and
     * travel raw - they are nullable columns, and guessing them per type would
     * rebuild a different column than the one the author authored.
     *
     * @param array $fields data_fields rows, keyed by id and already ordered.
     * @return array
     */
    private static function data_fields(array $fields): array {
        $exported = [];
        foreach ($fields as $field) {
            $spec = [
                'type' => $field->type,
                'name' => $field->name,
                'description' => $field->description ?? '',
                'required' => (int) $field->required,
            ];
            for ($param = 1; $param <= 10; $param++) {
                $key = 'param' . $param;
                $spec[$key] = $field->$key ?? null;
            }
            $exported[] = $spec;
        }
        return $exported;
    }

    /**
     * The mold's authored template columns, the non-empty ones only.
     *
     * They travel raw: a template carries both mod_data's own [[Field name]]
     * references and the service's markers, so any escaping or filtering here
     * would leave the generated database rendering nothing. An absent column
     * lets Moodle generate its own default, as it does for a hand-built one.
     *
     * @param \stdClass $data
     * @return array
     */
    private static function data_templates($data): array {
        $templates = [];
        foreach (self::DATA_TEMPLATE_COLUMNS as $column) {
            $value = (string) ($data->$column ?? '');
            if (trim($value) !== '') {
                $templates[$column] = $value;
            }
        }
        return $templates;
    }

    /**
     * The mold's entries, in authoring order, re-encoded for the consumer.
     *
     * The shape is the one data_settings::seed_example_entries() already reads,
     * so an exported mold flows back in unchanged. Values of a file/picture
     * field are dropped: the consumer cannot seed them, and copying a mold's
     * embedded files is not implemented anywhere in the plugin yet.
     *
     * @param int $dataid
     * @param array $fields data_fields rows, keyed by id and already ordered.
     * @return array
     */
    private static function data_example_entries(int $dataid, array $fields): array {
        global $DB;

        if (!$fields) {
            return [];
        }

        $records = $DB->get_records('data_records', ['dataid' => $dataid], 'id ASC', 'id');
        $entries = [];
        foreach ($records as $record) {
            $contents = $DB->get_records(
                'data_content',
                ['recordid' => $record->id],
                '',
                'fieldid, content, content1'
            );
            $values = [];
            foreach ($fields as $field) {
                $content = $contents[$field->id] ?? null;
                if ($content === null) {
                    continue;
                }
                $row = self::data_entry_row((string) $field->type, $content);
                if ($row !== null) {
                    $values[] = ['field_name' => $field->name] + $row;
                }
            }
            if ($values) {
                $entries[] = ['values' => $values];
            }
        }
        return $entries;
    }

    /**
     * One stored value, back in the form data_settings reads.
     *
     * The row is always ['value' => ...], plus an optional 'value1' for the
     * types that really own a second stored column. Each type is the exact
     * inverse of data_settings::insert_content(), which is what makes the round
     * trip lossless:
     *
     * - A date is stored as a unix timestamp, but insert_content() parses its
     *   input with strtotime(), and strtotime('1700000000') is false - so the
     *   day travels as a date string, not as the raw timestamp.
     * - multimenu/checkbox are stored '##' delimited, while insert_content()
     *   splits its input on commas, so the options travel comma separated.
     * - latlong keeps its pair in content/content1 but travels as ONE comma
     *   separated value, the way insert_content() has always read it back: the
     *   pair is meaningless split in two, that encoding already round trips
     *   exactly, and it is the shape the model-driven path emits.
     * - A url owns a real second authored string - the visible link text that
     *   mod_data stores in content1 - so it travels as value1, and ONLY when
     *   the author wrote one. An absent value1 is meaningful: it tells the
     *   consumer to leave content1 alone and keeps the payload identical to
     *   the one every other type ships.
     *
     * @param string $type The field type.
     * @param \stdClass $content The data_content row (content, content1).
     * @return array|null ['value' => string, 'value1' => string (optional)], or
     *     null when this type cannot be seeded back.
     */
    private static function data_entry_row(string $type, $content): ?array {
        if (in_array($type, self::DATA_UNSEEDABLE_TYPES, true)) {
            return null;
        }

        $raw = (string) ($content->content ?? '');
        if ($raw === '') {
            return null;
        }

        switch ($type) {
            case 'date':
                return ['value' => date('Y-m-d', (int) $raw)];
            case 'multimenu':
            case 'checkbox':
                return ['value' => implode(', ', explode('##', $raw))];
            case 'latlong':
                $longitude = (string) ($content->content1 ?? '');
                return $longitude === '' ? null : ['value' => $raw . ', ' . $longitude];
            case 'url':
                $linktext = (string) ($content->content1 ?? '');
                return $linktext === '' ? ['value' => $raw] : ['value' => $raw, 'value1' => $linktext];
            default:
                return ['value' => $raw];
        }
    }

    /**
     * A Wiki's raw description, its four type settings and its pages.
     *
     * A wiki that nobody has opened yet owns no page at all: wiki_add_instance
     * creates neither the subwiki nor the first page, they appear on the first
     * view. Such a mold still travels, simply without mod_settings.
     *
     * @param cm_info $cm
     * @return array
     */
    private static function wiki_parameters(cm_info $cm): array {
        global $DB;

        $wiki = $DB->get_record('wiki', ['id' => $cm->instance]);
        if (!$wiki) {
            return ['name' => $cm->name, 'section' => (int) $cm->sectionnum];
        }

        $parameters = [
            'name' => $cm->name,
            'section' => (int) $cm->sectionnum,
            'intro' => $wiki->intro ?? '',
            'wikimode' => $wiki->wikimode ?? 'collaborative',
            'defaultformat' => $wiki->defaultformat ?? 'html',
            'forceformat' => (int) ($wiki->forceformat ?? 0),
            'firstpagetitle' => $wiki->firstpagetitle ?? '',
        ];

        $pages = self::wiki_pages((int) $wiki->id, (string) ($wiki->firstpagetitle ?? ''));
        if ($pages) {
            $parameters['mod_settings'] = ['pages' => $pages];
        }

        return $parameters;
    }

    /**
     * Every page of one wiki, the first page ahead of the rest, as authored.
     *
     * Three traps of mod_wiki's schema shape this query:
     *
     * - Pages hang off a subwiki, never off the wiki itself. A mold is authored
     *   as a single collaborative wiki, which owns exactly one subwiki
     *   (groupid 0, userid 0), so the wiki's FIRST subwiki is the one carrying
     *   the authored pages.
     * - The authored text lives in wiki_versions.content of the CURRENT (highest)
     *   version. wiki_pages.cachedcontent is the parsed render that
     *   wiki_refresh_cachedcontent stores, so it would deliver markers already
     *   chewed by the wiki parser.
     * - There is no "is first" flag and no ordering column: the first page is
     *   the one whose title matches wiki.firstpagetitle (as wiki_get_first_page
     *   matches it), and id is the only stable sequence for the others.
     *
     * @param int $wikiid
     * @param string $firstpagetitle
     * @return array
     */
    private static function wiki_pages(int $wikiid, string $firstpagetitle): array {
        global $DB;

        $sql = 'SELECT p.id, p.title, v.content
                  FROM {wiki_pages} p
                  JOIN {wiki_subwikis} s ON s.id = p.subwikiid
             LEFT JOIN {wiki_versions} v ON v.pageid = p.id
                       AND v.version = (SELECT MAX(v2.version)
                                          FROM {wiki_versions} v2
                                         WHERE v2.pageid = p.id)
                 WHERE s.wikiid = :wikiid
                   AND s.id = (SELECT MIN(s2.id)
                                 FROM {wiki_subwikis} s2
                                WHERE s2.wikiid = :subwikiid)
              ORDER BY p.id ASC';
        $records = $DB->get_records_sql($sql, ['wikiid' => $wikiid, 'subwikiid' => $wikiid]);

        $first = [];
        $rest = [];
        foreach ($records as $record) {
            $isfirst = $firstpagetitle !== '' && (string) $record->title === $firstpagetitle;
            $page = [
                'title' => $record->title,
                'content' => $record->content ?? '',
                'firstpage' => $isfirst,
            ];
            if ($isfirst) {
                $first[] = $page;
            } else {
                $rest[] = $page;
            }
        }

        return array_merge($first, $rest);
    }

    /**
     * A Book's raw introduction, its three settings and its chapters.
     *
     * mod_book has no parent column: a subchapter belongs to the nearest
     * preceding chapter, so the reading order IS the hierarchy and must be
     * preserved exactly.
     *
     * @param cm_info $cm
     * @return array
     */
    private static function book_parameters(cm_info $cm): array {
        global $DB;

        $book = $DB->get_record('book', ['id' => $cm->instance]);
        if (!$book) {
            return ['name' => $cm->name, 'section' => (int) $cm->sectionnum];
        }

        $parameters = [
            'name' => $cm->name,
            'section' => (int) $cm->sectionnum,
            'intro' => $book->intro ?? '',
            'numbering' => (int) ($book->numbering ?? 0),
            // Moodle's own form has no control for this one, but the generated
            // book must still read the way the mold does.
            'navstyle' => (int) ($book->navstyle ?? 1),
            'customtitles' => (int) ($book->customtitles ?? 0),
        ];

        $chapters = self::book_chapters((int) $book->id);
        if ($chapters) {
            $parameters['mod_settings'] = ['chapters' => $chapters];
        }

        return $parameters;
    }

    /**
     * Every chapter of one book, in reading order.
     *
     * @param int $bookid
     * @return array
     */
    private static function book_chapters(int $bookid): array {
        global $DB;

        $records = $DB->get_records(
            'book_chapters',
            ['bookid' => $bookid],
            'pagenum ASC',
            'id, title, content, subchapter'
        );

        $chapters = [];
        foreach ($records as $record) {
            $chapters[] = [
                'title' => $record->title,
                'content' => $record->content ?? '',
                'subchapter' => (int) $record->subchapter,
            ];
        }
        return $chapters;
    }

    /**
     * A Forum's raw description, its settings and its initial discussions.
     *
     * Unlike url/resource, every forum setting is a plain column - there is no
     * serialized blob to unpack. The discussions are this type's internal
     * elements: their bodies are marker-bearing, so they travel raw and in the
     * order they were authored.
     *
     * @param cm_info $cm
     * @return array
     */
    private static function forum_parameters(cm_info $cm): array {
        global $DB;

        $forum = $DB->get_record('forum', ['id' => $cm->instance]);
        if (!$forum) {
            return ['name' => $cm->name, 'section' => (int) $cm->sectionnum];
        }

        $parameters = array_merge(
            self::forum_settings_columns($forum),
            [
                'name' => $cm->name,
                'section' => (int) $cm->sectionnum,
                'intro' => $forum->intro ?? '',
                // Moodle zeroes the rating window unless this flag says it is
                // in use (see forum_add_instance), so it travels with it
                // instead of being inferred on the way back in.
                'ratingtime' => (!empty($forum->assesstimestart) && !empty($forum->assesstimefinish)) ? 1 : 0,
            ]
        );

        $discussions = self::forum_discussions((int) $forum->id);
        if ($discussions) {
            $parameters['mod_settings'] = ['discussions' => $discussions];
        }

        return $parameters;
    }

    /**
     * The mod_forum settings worth reproducing on the generated activity.
     *
     * Identity/placement columns (id, course, name, timemodified) are left out
     * on purpose - they describe THIS forum, never the new one.
     *
     * @param \stdClass $forum
     * @return array
     */
    private static function forum_settings_columns($forum): array {
        $fields = [
            'type', 'forcesubscribe', 'trackingtype',
            'maxbytes', 'maxattachments', 'displaywordcount',
            'lockdiscussionafter', 'blockperiod', 'blockafter', 'warnafter',
            'grade_forum', 'grade_forum_notify',
            'assessed', 'scale', 'assesstimestart', 'assesstimefinish',
            'completiondiscussions', 'completionreplies', 'completionposts',
            'duedate', 'cutoffdate', 'rsstype', 'rssarticles',
        ];
        $settings = [];
        foreach ($fields as $field) {
            if (isset($forum->$field)) {
                $settings[$field] = $forum->$field;
            }
        }
        return $settings;
    }

    /**
     * Every initial discussion of one forum, as authored.
     *
     * A discussion's body lives on its first post, not on the discussion row.
     * Moodle lists discussions by pinned/last-reply order, which is a reading
     * order, not the authoring one - so they are ordered by id, the only
     * stable "as written" sequence.
     *
     * @param int $forumid
     * @return array
     */
    private static function forum_discussions(int $forumid): array {
        global $DB;

        $sql = 'SELECT d.id, d.name, p.message
                  FROM {forum_discussions} d
                  JOIN {forum_posts} p ON p.id = d.firstpost
                 WHERE d.forum = :forumid
              ORDER BY d.id ASC';
        $records = $DB->get_records_sql($sql, ['forumid' => $forumid]);

        $discussions = [];
        foreach ($records as $record) {
            $discussions[] = [
                'subject' => $record->name,
                'message' => $record->message ?? '',
            ];
        }
        return $discussions;
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
