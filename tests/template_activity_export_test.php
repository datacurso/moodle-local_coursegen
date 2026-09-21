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

namespace local_coursegen;

use local_coursegen\local\service\template_activity_export;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/resourcelib.php');
require_once($CFG->dirroot . '/mod/wiki/locallib.php');

/**
 * What a mold activity ships to the AI service.
 *
 * A mold is reproduced by the service, so its own settings and its raw
 * marker-bearing text must travel; the columns that identify THIS activity
 * (course, id, timestamps) must not.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_activity_export
 */
final class template_activity_export_test extends \advanced_testcase {
    /** @var string An intro carrying both marker kinds plus real markup. */
    private const MARKED_INTRO = '<p>⟦coursegen:url: repositorio de la materia⟧</p><p>Ver <b>⟦tema⟧</b></p>';

    /**
     * Export the parameters of a freshly created module.
     *
     * @param string $modname Module type to create.
     * @param array $options Module generator options.
     * @return array Exported parameters.
     */
    private function export_module(string $modname, array $options): array {
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module($modname, $options + ['course' => $course->id]);
        $cm = get_fast_modinfo($course)->get_cm($module->cmid);

        return template_activity_export::parameters_for($cm);
    }

    /**
     * A URL mold ships its address, its raw description and its display settings.
     */
    public function test_url_exports_address_intro_and_display_settings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('url', [
            'name' => 'Institutional repository',
            'externalurl' => 'http://⟦coursegen:url⟧',
            'intro' => self::MARKED_INTRO,
            'introformat' => FORMAT_HTML,
            'display' => \RESOURCELIB_DISPLAY_POPUP,
            'popupwidth' => 800,
            'popupheight' => 600,
            'printintro' => 1,
        ]);

        $this->assertSame('Institutional repository', $params['name']);
        $this->assertSame('http://⟦coursegen:url⟧', $params['externalurl']);
        $this->assertSame((int) \RESOURCELIB_DISPLAY_POPUP, (int) $params['display']);
        $this->assertSame(800, (int) $params['popupwidth']);
        $this->assertSame(600, (int) $params['popupheight']);
        $this->assertSame(1, (int) $params['printintro']);
    }

    /**
     * The description travels raw: the service parses those markers, so a
     * filtered or reformatted copy would destroy the mold.
     */
    public function test_url_intro_keeps_its_markers_untouched(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('url', [
            'externalurl' => 'https://example.org',
            'intro' => self::MARKED_INTRO,
            'introformat' => FORMAT_HTML,
        ]);

        $this->assertSame(self::MARKED_INTRO, $params['intro']);
    }

    /**
     * Columns describing this very activity never travel: the generated one
     * lives in another course and owns its own identity.
     */
    public function test_url_export_omits_identity_columns(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('url', [
            'externalurl' => 'https://example.org',
            'intro' => 'Plain',
            'introformat' => FORMAT_HTML,
        ]);

        foreach (['id', 'course', 'timemodified', 'introformat', 'displayoptions', 'parameters'] as $column) {
            $this->assertArrayNotHasKey($column, $params);
        }
        // A URL has no sub-objects; an empty mod_settings would only make
        // create_mod_service log a discarded-settings warning.
        $this->assertArrayNotHasKey('mod_settings', $params);
    }

    /**
     * A URL saved without display options still exports usable values rather
     * than notices or nulls.
     */
    public function test_url_without_display_options_falls_back_to_defaults(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $url = $this->getDataGenerator()->create_module('url', [
            'course' => $course->id,
            'externalurl' => 'https://example.org',
        ]);
        // Some real rows predate the display options or were built by code
        // that never set them.
        $this->set_display_options((int) $url->id, '');

        $cm = get_fast_modinfo($course)->get_cm($url->cmid);
        $params = template_activity_export::parameters_for($cm);

        $config = get_config('url');
        $this->assertSame((int) $config->popupwidth, (int) $params['popupwidth']);
        $this->assertSame((int) $config->popupheight, (int) $params['popupheight']);
        $this->assertSame((int) $config->printintro, (int) $params['printintro']);
    }

    /**
     * Blank out a url row's serialized display options.
     *
     * @param int $urlid The mod_url instance id.
     * @param string $value Raw column value to store.
     */
    private function set_display_options(int $urlid, string $value): void {
        global $DB;

        $DB->set_field('url', 'displayoptions', $value, ['id' => $urlid]);
    }

    /**
     * A File mold ships its raw description, its appearance settings and the
     * identity of the document it carries.
     */
    public function test_resource_exports_intro_settings_and_mold_file(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', [
            'course' => $course->id,
            'name' => 'Study guide',
            'intro' => self::MARKED_INTRO,
            'introformat' => FORMAT_HTML,
            'display' => \RESOURCELIB_DISPLAY_EMBED,
            'showtype' => 1,
            'showsize' => 1,
            'printintro' => 1,
            'filterfiles' => 2,
        ]);
        $cm = get_fast_modinfo($course)->get_cm($resource->cmid);
        $this->attach_document($cm, 'guia-de-estudio.pdf', 'application/pdf');

        $params = template_activity_export::parameters_for($cm);

        $this->assertSame('Study guide', $params['name']);
        $this->assertSame(self::MARKED_INTRO, $params['intro']);
        $this->assertSame((int) \RESOURCELIB_DISPLAY_EMBED, (int) $params['display']);
        $this->assertSame(1, (int) $params['showtype']);
        $this->assertSame(1, (int) $params['showsize']);
        $this->assertSame(1, (int) $params['printintro']);
        $this->assertSame(2, (int) $params['filterfiles']);
        // The attached document's identity: the service pins the generated
        // document's format to it. Its bytes do not travel.
        $this->assertArrayHasKey('moldfile', $params);
        $this->assertSame('pdf', $params['moldfile']['extension']);
    }

    /**
     * Replace a File activity's content with a named document.
     *
     * @param \cm_info $cm The File activity.
     * @param string $filename Document name, extension included.
     * @param string $mimetype Document mime type.
     */
    private function attach_document(\cm_info $cm, string $filename, string $mimetype): void {
        $fs = get_file_storage();
        $fs->delete_area_files($cm->context->id, 'mod_resource', 'content', 0);
        $fs->create_file_from_string([
            'contextid' => $cm->context->id,
            'component' => 'mod_resource',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
            'mimetype' => $mimetype,
            'sortorder' => 1,
        ], 'document bytes');
    }

    /**
     * mod_resource only serializes showsize/showdate when they are ON, so an
     * absent key means OFF - reading it as "use the site default" would turn
     * options on that the author deliberately left off.
     */
    public function test_resource_absent_display_options_mean_off(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('resource', [
            'display' => \RESOURCELIB_DISPLAY_AUTO,
            'showsize' => 0,
            'showdate' => 0,
            'showtype' => 0,
        ]);

        $this->assertSame(0, (int) $params['showsize']);
        $this->assertSame(0, (int) $params['showdate']);
        $this->assertSame(0, (int) $params['showtype']);
    }

    /**
     * Identity columns never travel, and a File has no sub-objects to declare.
     */
    public function test_resource_export_omits_identity_columns(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('resource', ['intro' => 'Plain', 'introformat' => FORMAT_HTML]);

        foreach (['id', 'course', 'timemodified', 'introformat', 'displayoptions', 'revision'] as $column) {
            $this->assertArrayNotHasKey($column, $params);
        }
        $this->assertArrayNotHasKey('mod_settings', $params);
    }

    /**
     * A Forum mold ships its raw description and every setting the scope names.
     */
    public function test_forum_exports_intro_and_its_settings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('forum', [
            'name' => 'Debate forum',
            'intro' => self::MARKED_INTRO,
            'introformat' => FORMAT_HTML,
            'type' => 'qanda',
            'forcesubscribe' => 1,
            'trackingtype' => 2,
            'maxattachments' => 3,
            'displaywordcount' => 1,
            'blockperiod' => 86400,
            'blockafter' => 5,
            'warnafter' => 3,
            'completiondiscussions' => 2,
        ]);

        $this->assertSame(self::MARKED_INTRO, $params['intro']);
        $this->assertSame('qanda', $params['type']);
        $this->assertSame(1, (int) $params['forcesubscribe']);
        $this->assertSame(2, (int) $params['trackingtype']);
        $this->assertSame(3, (int) $params['maxattachments']);
        $this->assertSame(1, (int) $params['displaywordcount']);
        $this->assertSame(86400, (int) $params['blockperiod']);
        $this->assertSame(5, (int) $params['blockafter']);
        $this->assertSame(3, (int) $params['warnafter']);
        $this->assertSame(2, (int) $params['completiondiscussions']);
    }

    /**
     * The mold's discussions travel in authoring order, bodies raw.
     */
    public function test_forum_exports_its_discussions_in_order(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id,
            'intro' => 'Plain',
            'introformat' => FORMAT_HTML,
        ]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        foreach ([['First debate', '<p>Body ⟦tema⟧</p>'], ['Second debate', '<p>Fixed body</p>']] as $entry) {
            $generator->create_discussion([
                'course' => $course->id,
                'forum' => $forum->id,
                'userid' => get_admin()->id,
                'name' => $entry[0],
                'message' => $entry[1],
                'messageformat' => FORMAT_HTML,
            ]);
        }

        $cm = get_fast_modinfo($course)->get_cm($forum->cmid);
        $params = template_activity_export::parameters_for($cm);

        $discussions = $params['mod_settings']['discussions'];
        $this->assertCount(2, $discussions);
        $this->assertSame('First debate', $discussions[0]['subject']);
        // Raw: the service parses those markers.
        $this->assertSame('<p>Body ⟦tema⟧</p>', $discussions[0]['message']);
        $this->assertSame('Second debate', $discussions[1]['subject']);
    }

    /**
     * Moodle drops the rating window unless ratingtime says it is in use, so
     * the flag travels alongside the dates rather than being inferred later.
     */
    public function test_forum_exports_a_rating_time_flag_with_the_window(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Moodle itself zeroes the dates on save unless ratingtime says the
        // window is in use, so the mold has to be built with it too.
        $withwindow = $this->export_module('forum', [
            'assessed' => 1,
            'ratingtime' => 1,
            'assesstimestart' => 1700000000,
            'assesstimefinish' => 1700600000,
        ]);
        $this->assertSame(1, (int) $withwindow['ratingtime']);

        $withoutwindow = $this->export_module('forum', ['assessed' => 1]);
        $this->assertSame(0, (int) $withoutwindow['ratingtime']);
    }

    /**
     * Identity columns never travel.
     */
    public function test_forum_export_omits_identity_columns(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('forum', ['intro' => 'Plain', 'introformat' => FORMAT_HTML]);

        foreach (['id', 'course', 'timemodified', 'introformat'] as $column) {
            $this->assertArrayNotHasKey($column, $params);
        }
    }

    /**
     * A Book mold ships its raw introduction and its three settings.
     */
    public function test_book_exports_intro_and_its_settings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('book', [
            'name' => 'Study book',
            'intro' => self::MARKED_INTRO,
            'introformat' => FORMAT_HTML,
            'numbering' => 3,
            'customtitles' => 1,
        ]);

        $this->assertSame('Study book', $params['name']);
        $this->assertSame(self::MARKED_INTRO, $params['intro']);
        $this->assertSame(3, (int) $params['numbering']);
        $this->assertSame(1, (int) $params['customtitles']);
        // Moodle's own form has no control for navstyle, but the generated
        // book must still read the same as the mold.
        $this->assertArrayHasKey('navstyle', $params);
    }

    /**
     * Chapters travel in reading order, bodies raw, hierarchy intact.
     *
     * mod_book has no parent column: a subchapter belongs to the nearest
     * preceding chapter, so the order IS the hierarchy.
     */
    public function test_book_exports_its_chapters_with_their_hierarchy(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', [
            'course' => $course->id,
            'intro' => 'Plain',
            'introformat' => FORMAT_HTML,
        ]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_book');
        // The generator defaults every chapter to pagenum 1 and shifts the
        // rest down, so the order has to be stated explicitly.
        $generator->create_chapter(['bookid' => $book->id, 'pagenum' => 1,
            'title' => 'Unit ⟦tema⟧', 'content' => '<p>Body ⟦texto⟧</p>']);
        $generator->create_chapter(['bookid' => $book->id, 'pagenum' => 2, 'subchapter' => 1,
            'title' => 'Objetivos', 'content' => '<p>Fixed</p>']);
        $generator->create_chapter(['bookid' => $book->id, 'pagenum' => 3,
            'title' => 'Closing', 'content' => '<p>End</p>']);

        $cm = get_fast_modinfo($course)->get_cm($book->cmid);
        $params = template_activity_export::parameters_for($cm);

        $chapters = $params['mod_settings']['chapters'];
        $this->assertCount(3, $chapters);
        $this->assertSame('Unit ⟦tema⟧', $chapters[0]['title']);
        $this->assertSame('<p>Body ⟦texto⟧</p>', $chapters[0]['content']);
        $this->assertSame(0, (int) $chapters[0]['subchapter']);
        $this->assertSame('Objetivos', $chapters[1]['title']);
        $this->assertSame(1, (int) $chapters[1]['subchapter']);
        $this->assertSame('Closing', $chapters[2]['title']);
        $this->assertSame(0, (int) $chapters[2]['subchapter']);
    }

    /**
     * Identity and derived columns never travel.
     */
    public function test_book_export_omits_identity_columns(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('book', ['intro' => 'Plain', 'introformat' => FORMAT_HTML]);

        foreach (['id', 'course', 'revision', 'timecreated', 'timemodified', 'introformat'] as $column) {
            $this->assertArrayNotHasKey($column, $params);
        }
    }

    /**
     * A Wiki mold ships its raw description and its four type settings.
     */
    public function test_wiki_exports_intro_and_its_settings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('wiki', [
            'name' => 'Wiki plantilla',
            'intro' => self::MARKED_INTRO,
            'introformat' => FORMAT_HTML,
            'wikimode' => 'collaborative',
            'defaultformat' => 'html',
            'forceformat' => 1,
            'firstpagetitle' => '⟦ Nombre de la pagina en base al tema del curso ⟧',
        ]);

        $this->assertSame('Wiki plantilla', $params['name']);
        $this->assertSame(self::MARKED_INTRO, $params['intro']);
        $this->assertSame('collaborative', $params['wikimode']);
        $this->assertSame('html', $params['defaultformat']);
        $this->assertSame(1, (int) $params['forceformat']);
        $this->assertSame('⟦ Nombre de la pagina en base al tema del curso ⟧', $params['firstpagetitle']);
    }

    /**
     * The mold's pages travel with the first page ahead of the rest, bodies raw.
     *
     * wiki_pages has no ordering column and no "is first" flag: the first page
     * is the one whose title matches wiki.firstpagetitle, and the remaining
     * ones only have their id as a stable sequence.
     */
    public function test_wiki_exports_its_pages_with_the_first_page_first(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_wiki');
        $wiki = $generator->create_instance([
            'course' => $course->id,
            'intro' => 'Plain',
            'introformat' => FORMAT_HTML,
            'firstpagetitle' => 'Índice ⟦tema⟧',
        ]);
        // Authored out of order on purpose: the first page is not the oldest row.
        $generator->create_page($wiki, ['title' => 'Unidad ⟦coursegen:repeat: unidad⟧',
            'content' => '<p>⟦desarrollo del tema⟧</p>']);
        $generator->create_first_page($wiki, ['content' => '<p>[[⟦coursegen:repeat: un enlace por unidad⟧]]</p>']);
        $generator->create_page($wiki, ['title' => 'Cierre', 'content' => '<p>Fixed</p>']);

        $cm = get_fast_modinfo($course)->get_cm($wiki->cmid);
        $params = template_activity_export::parameters_for($cm);

        $pages = $params['mod_settings']['pages'];
        $this->assertCount(3, $pages);
        $this->assertSame('Índice ⟦tema⟧', $pages[0]['title']);
        $this->assertTrue($pages[0]['firstpage']);
        // Raw, byte for byte: the service parses those markers itself, and the
        // parsed render kept in cachedcontent would arrive already mangled.
        $this->assertSame('<p>[[⟦coursegen:repeat: un enlace por unidad⟧]]</p>', $pages[0]['content']);
        $this->assertSame('Unidad ⟦coursegen:repeat: unidad⟧', $pages[1]['title']);
        $this->assertSame('<p>⟦desarrollo del tema⟧</p>', $pages[1]['content']);
        $this->assertFalse($pages[1]['firstpage']);
        $this->assertSame('Cierre', $pages[2]['title']);
        $this->assertFalse($pages[2]['firstpage']);
    }

    /**
     * An edited page travels as its latest authored version.
     *
     * wiki_versions keeps every revision and wiki_pages.cachedcontent holds the
     * PARSED render, so both the older version and the cache would deliver
     * something the author never wrote.
     */
    public function test_wiki_exports_the_current_version_of_an_edited_page(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_wiki');
        $wiki = $generator->create_instance([
            'course' => $course->id,
            'firstpagetitle' => 'Portada',
        ]);
        $page = $generator->create_first_page($wiki, ['content' => '<p>First draft</p>']);
        wiki_save_page($page, '<p>Latest ⟦tema⟧</p>', get_admin()->id);

        $cm = get_fast_modinfo($course)->get_cm($wiki->cmid);
        $params = template_activity_export::parameters_for($cm);

        $pages = $params['mod_settings']['pages'];
        $this->assertCount(1, $pages);
        $this->assertSame('<p>Latest ⟦tema⟧</p>', $pages[0]['content']);
    }

    /**
     * wiki_add_instance creates no subwiki, no page and no version: they only
     * appear the first time somebody opens the wiki. A mold that was never
     * opened must still export, simply without pages.
     */
    public function test_wiki_without_pages_exports_no_mod_settings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('wiki', ['name' => 'Empty wiki', 'firstpagetitle' => 'Portada']);

        $this->assertSame('Empty wiki', $params['name']);
        $this->assertArrayNotHasKey('mod_settings', $params);
    }

    /**
     * Identity columns never travel.
     */
    public function test_wiki_export_omits_identity_columns(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('wiki', ['intro' => 'Plain', 'introformat' => FORMAT_HTML]);

        foreach (['id', 'course', 'timecreated', 'timemodified', 'introformat'] as $column) {
            $this->assertArrayNotHasKey($column, $params);
        }
    }

    /**
     * A Database mold ships its raw description and every instance setting.
     */
    public function test_data_exports_intro_and_every_instance_setting(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('data', [
            'name' => 'Base de datos plantilla prueba',
            'intro' => self::MARKED_INTRO,
            'introformat' => FORMAT_HTML,
            'approval' => 1,
            'manageapproved' => 0,
            'comments' => 1,
            'requiredentries' => 2,
            'requiredentriestoview' => 1,
            'maxentries' => 5,
            'timeavailablefrom' => 1700000000,
            'timeavailableto' => 1700600000,
            'timeviewfrom' => 1700100000,
            'timeviewto' => 1700500000,
            'editany' => 1,
            'notification' => 1,
            'completionentries' => 3,
            'assessed' => 1,
            'scale' => 100,
            'ratingtime' => 1,
            'assesstimestart' => 1700000000,
            'assesstimefinish' => 1700600000,
            'defaultsortdir' => 1,
            'rssarticles' => 4,
        ]);

        $this->assertSame('Base de datos plantilla prueba', $params['name']);
        // Raw: the service parses those markers itself.
        $this->assertSame(self::MARKED_INTRO, $params['intro']);
        $this->assertSame(1, (int) $params['approval']);
        $this->assertSame(0, (int) $params['manageapproved']);
        $this->assertSame(1, (int) $params['comments']);
        $this->assertSame(2, (int) $params['requiredentries']);
        $this->assertSame(1, (int) $params['requiredentriestoview']);
        $this->assertSame(5, (int) $params['maxentries']);
        $this->assertSame(1700000000, (int) $params['timeavailablefrom']);
        $this->assertSame(1700600000, (int) $params['timeavailableto']);
        $this->assertSame(1700100000, (int) $params['timeviewfrom']);
        $this->assertSame(1700500000, (int) $params['timeviewto']);
        $this->assertSame(1, (int) $params['editany']);
        $this->assertSame(1, (int) $params['notification']);
        $this->assertSame(3, (int) $params['completionentries']);
        $this->assertSame(1, (int) $params['assessed']);
        $this->assertSame(100, (int) $params['scale']);
        $this->assertSame(1700000000, (int) $params['assesstimestart']);
        $this->assertSame(1700600000, (int) $params['assesstimefinish']);
        $this->assertSame(1, (int) $params['defaultsortdir']);
        $this->assertSame(4, (int) $params['rssarticles']);
        // Moodle drops the rating window unless this flag says it is in use
        // (see data_add_instance), exactly as mod_forum does.
        $this->assertSame(1, (int) $params['ratingtime']);
    }

    /**
     * The default sort travels as the field NAME, never as the mold's row id.
     *
     * data.defaultsort holds a data_fields.id of THIS database: reused as is it
     * would point at a foreign field (or at nothing) in the generated one.
     */
    public function test_data_exports_its_default_sort_as_a_field_name(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        global $DB;
        [$course, $data] = $this->make_data_mold();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_data');
        $generator->create_field((object) ['type' => 'text', 'name' => 'Titulo'], $data);
        $categoria = $generator->create_field((object) ['type' => 'text', 'name' => 'Categoria'], $data);

        // No default sort yet: nothing to name.
        $this->assertSame('', $this->export_cm($course, $data)['defaultsortfield']);

        $DB->set_field('data', 'defaultsort', $categoria->field->id, ['id' => $data->id]);
        $params = $this->export_cm($course, $data);

        $this->assertSame('Categoria', $params['defaultsortfield']);
        $this->assertArrayNotHasKey('defaultsort', $params);
    }

    /**
     * The mold's fields travel in creation order, params included.
     *
     * The params ARE the field definition (choices, sizes, autolink, ...), so
     * the generated database reproduces the mold's columns instead of the
     * plugin's generic per-type guesses.
     */
    public function test_data_exports_its_fields_in_order_with_their_params(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $data] = $this->make_data_mold();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_data');
        $generator->create_field((object) [
            'type' => 'text',
            'name' => 'Título del Recurso',
            'description' => 'El título del recurso',
            'required' => 1,
        ], $data);
        $generator->create_field((object) [
            'type' => 'menu',
            'name' => 'Categoría / Temática',
            'param1' => "Artículo\nVideo\nLibro",
        ], $data);
        $generator->create_field((object) ['type' => 'textarea', 'name' => 'Resumen y Análisis'], $data);

        $fields = $this->export_cm($course, $data)['mod_settings']['fields'];

        $this->assertCount(3, $fields);
        $this->assertSame('text', $fields[0]['type']);
        $this->assertSame('Título del Recurso', $fields[0]['name']);
        $this->assertSame('El título del recurso', $fields[0]['description']);
        $this->assertSame(1, $fields[0]['required']);
        $this->assertSame('menu', $fields[1]['type']);
        $this->assertSame('Categoría / Temática', $fields[1]['name']);
        // Choices live one per line in param1.
        $this->assertSame("Artículo\nVideo\nLibro", $fields[1]['param1']);
        $this->assertSame('textarea', $fields[2]['type']);
        $this->assertSame('60', $fields[2]['param2']);
        $this->assertSame('35', $fields[2]['param3']);
        // All ten params travel: data_fields owns param1..param10.
        $this->assertArrayHasKey('param10', $fields[2]);
    }

    /**
     * Every non-empty template column travels raw, field references included.
     *
     * A template is the mold's layout: its [[Field name]] references have to
     * arrive byte for byte or the generated database renders nothing.
     */
    public function test_data_exports_all_its_non_empty_templates_raw(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        global $DB;
        [$course, $data] = $this->make_data_mold();
        $listtemplate = '<div>[[Título del Recurso]] — [[Categoría / Temática]] ##edit## ##more##</div>';
        $DB->update_record('data', (object) [
            'id' => $data->id,
            'listtemplate' => $listtemplate,
            'singletemplate' => '<h2>[[Título del Recurso]] ⟦tema⟧</h2>',
            'listtemplateheader' => '<table>',
            'listtemplatefooter' => '</table>',
            'addtemplate' => '<div>[[Título del Recurso]]</div>',
            'rsstemplate' => '<p>[[Resumen y Análisis]]</p>',
            'rsstitletemplate' => '[[Título del Recurso]]',
            'csstemplate' => '.c { color: red; }',
            'jstemplate' => 'window.console.log("x");',
            'asearchtemplate' => '<div>[[Título del Recurso]]</div>',
        ]);

        $templates = $this->export_cm($course, $data)['mod_settings']['templates'];

        $this->assertCount(10, $templates);
        // Byte for byte: the service resolves those references itself.
        $this->assertSame($listtemplate, $templates['listtemplate']);
        $this->assertSame('<h2>[[Título del Recurso]] ⟦tema⟧</h2>', $templates['singletemplate']);
        $this->assertSame('<table>', $templates['listtemplateheader']);
        $this->assertSame('</table>', $templates['listtemplatefooter']);
        $this->assertSame('<div>[[Título del Recurso]]</div>', $templates['addtemplate']);
        $this->assertSame('<p>[[Resumen y Análisis]]</p>', $templates['rsstemplate']);
        $this->assertSame('[[Título del Recurso]]', $templates['rsstitletemplate']);
        $this->assertSame('.c { color: red; }', $templates['csstemplate']);
        $this->assertSame('window.console.log("x");', $templates['jstemplate']);
        $this->assertSame('<div>[[Título del Recurso]]</div>', $templates['asearchtemplate']);
    }

    /**
     * The mold's entries travel in order, each value re-encoded to the string
     * form data_settings::insert_content() reads back.
     *
     * A picture/file value is dropped on purpose: the consumer cannot seed one
     * (copying the mold's embedded files is not implemented plugin-wide).
     */
    public function test_data_exports_its_entries_with_values_per_type(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $data] = $this->make_data_mold();
        $ids = $this->create_typed_fields($data);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_data');
        $generator->create_entry($data, [
            $ids['Titulo'] => 'Cien años ⟦tema⟧',
            $ids['Genero'] => 'Novela',
            $ids['Tags'] => ['A', 'C'],
            $ids['Fecha'] => '30-05-1967',
            $ids['Enlace'] => ['https://example.org/uno', 'Sitio'],
            $ids['Lugar'] => ['1.5', '-2.25'],
            $ids['Resumen'] => '<p>Resumen ⟦texto⟧</p>',
            $ids['Precio'] => '42.5',
            $ids['Foto'] => ['sample.png', 'Alt'],
        ]);
        $generator->create_entry($data, [
            $ids['Titulo'] => 'Segunda ⟦tema⟧',
            $ids['Genero'] => 'Ensayo',
            $ids['Tags'] => ['B'],
            $ids['Fecha'] => '01-02-2020',
            $ids['Enlace'] => ['https://example.org/dos', ''],
            $ids['Lugar'] => ['3.25', '4.75'],
            $ids['Resumen'] => '<p>Otro</p>',
            $ids['Precio'] => '7.5',
            $ids['Foto'] => ['sample.png', ''],
        ]);

        $entries = $this->export_cm($course, $data)['mod_settings']['example_entries'];

        $this->assertCount(2, $entries);
        $first = $this->values_by_name($entries[0]);
        $this->assertSame('Cien años ⟦tema⟧', $first['Titulo']);
        $this->assertSame('Novela', $first['Genero']);
        // Choices come back comma separated: that is what insert_content reads.
        $this->assertSame('A, C', $first['Tags']);
        $this->assertSame('1967-05-30', $first['Fecha']);
        $this->assertSame('https://example.org/uno', $first['Enlace']);
        $this->assertSame('1.5, -2.25', $first['Lugar']);
        $this->assertSame('<p>Resumen ⟦texto⟧</p>', $first['Resumen']);
        $this->assertSame('42.5', $first['Precio']);
        // A picture (like a file) cannot be seeded back, so it never travels.
        $this->assertArrayNotHasKey('Foto', $first);
        // Values keep the field order of the mold.
        $this->assertSame(
            ['Titulo', 'Genero', 'Tags', 'Fecha', 'Enlace', 'Lugar', 'Resumen', 'Precio'],
            array_keys($first)
        );
        $this->assertSame('Segunda ⟦tema⟧', $this->values_by_name($entries[1])['Titulo']);
    }

    /**
     * A url field carries TWO authored things - the address in data_content.content
     * and the visible link text in content1 - so both travel: the address as
     * value, the link text as value1. Shipping only the address left the
     * generated entry showing a raw url where the mold shows a label.
     */
    public function test_data_exports_a_url_link_text_as_a_second_value(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $data] = $this->make_data_mold();
        $ids = $this->create_typed_fields($data);
        $this->create_entry_with($data, $ids, ['https://example.org/uno', 'Sitio oficial']);

        $entries = $this->export_cm($course, $data)['mod_settings']['example_entries'];
        $enlace = $this->value_row($entries[0], 'Enlace');

        $this->assertSame(
            ['field_name' => 'Enlace', 'value' => 'https://example.org/uno', 'value1' => 'Sitio oficial'],
            $enlace
        );
    }

    /**
     * A url with no link text ships no value1 at all.
     *
     * The absence of the key is the message: it tells the consumer to leave
     * content1 alone, and it keeps every payload produced before url learned to
     * carry a link text byte-identical. Latlong keeps its pair in one comma
     * separated value, so it declares no value1 either.
     */
    public function test_data_url_without_link_text_exports_no_second_value(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $data] = $this->make_data_mold();
        $ids = $this->create_typed_fields($data);
        $this->create_entry_with($data, $ids, ['https://example.org/dos', '']);

        $entries = $this->export_cm($course, $data)['mod_settings']['example_entries'];
        $enlace = $this->value_row($entries[0], 'Enlace');

        $this->assertSame('https://example.org/dos', $enlace['value']);
        $this->assertArrayNotHasKey('value1', $enlace);
        $this->assertSame('1.5, -2.25', $this->value_row($entries[0], 'Lugar')['value']);
        $this->assertArrayNotHasKey('value1', $this->value_row($entries[0], 'Lugar'));
    }

    /**
     * A database nobody has built yet still exports: it simply has no
     * collections to declare, and an empty mod_settings would only make
     * create_mod_service log a discarded-settings warning.
     */
    public function test_data_without_fields_or_entries_exports_no_mod_settings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('data', ['name' => 'Empty database']);

        $this->assertSame('Empty database', $params['name']);
        $this->assertSame('', $params['defaultsortfield']);
        $this->assertArrayNotHasKey('mod_settings', $params);
    }

    /**
     * Identity columns never travel - defaultsort above all, since it is a row
     * id of THIS database.
     */
    public function test_data_export_omits_identity_columns(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('data', ['intro' => 'Plain', 'introformat' => FORMAT_HTML]);

        foreach (['id', 'course', 'timemodified', 'introformat', 'config', 'defaultsort'] as $column) {
            $this->assertArrayNotHasKey($column, $params);
        }
    }

    /**
     * Create a database mold and return it with its course.
     *
     * @param array $options Module generator options.
     * @return array [course, data instance]
     */
    private function make_data_mold(array $options = []): array {
        $course = $this->getDataGenerator()->create_course();
        $data = $this->getDataGenerator()->create_module('data', $options + ['course' => $course->id]);

        return [$course, $data];
    }

    /**
     * Export the parameters of an already built module.
     *
     * @param \stdClass $course The course holding it.
     * @param \stdClass $instance The module instance (needs ->cmid).
     * @return array Exported parameters.
     */
    private function export_cm(\stdClass $course, \stdClass $instance): array {
        return template_activity_export::parameters_for(get_fast_modinfo($course)->get_cm($instance->cmid));
    }

    /**
     * Create one field of every seedable type, plus an unseedable picture.
     *
     * @param \stdClass $data The database instance.
     * @return array<string, int> Field id keyed by field name, in creation order.
     */
    private function create_typed_fields(\stdClass $data): array {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_data');
        $specs = [
            ['type' => 'text', 'name' => 'Titulo'],
            ['type' => 'menu', 'name' => 'Genero', 'param1' => "Novela\nEnsayo"],
            ['type' => 'multimenu', 'name' => 'Tags', 'param1' => "A\nB\nC"],
            ['type' => 'date', 'name' => 'Fecha'],
            ['type' => 'url', 'name' => 'Enlace'],
            ['type' => 'latlong', 'name' => 'Lugar'],
            ['type' => 'textarea', 'name' => 'Resumen'],
            ['type' => 'number', 'name' => 'Precio'],
            ['type' => 'picture', 'name' => 'Foto'],
        ];
        $ids = [];
        foreach ($specs as $spec) {
            $field = $generator->create_field((object) $spec, $data);
            $ids[$spec['name']] = (int) $field->field->id;
        }
        return $ids;
    }

    /**
     * Create one entry filling every field, the url one as given.
     *
     * The mod_data generator walks every field of the database and reads its
     * value out of the map, so a partial map errors out before anything is
     * stored - only the url pair actually varies between these cases.
     *
     * @param \stdClass $data The database instance.
     * @param array<string, int> $ids Field id keyed by field name.
     * @param array $enlace The url field's [address, link text] pair.
     */
    private function create_entry_with(\stdClass $data, array $ids, array $enlace): void {
        $this->getDataGenerator()->get_plugin_generator('mod_data')->create_entry($data, [
            $ids['Titulo'] => 'Cien años',
            $ids['Genero'] => 'Novela',
            $ids['Tags'] => ['A'],
            $ids['Fecha'] => '30-05-1967',
            $ids['Enlace'] => $enlace,
            $ids['Lugar'] => ['1.5', '-2.25'],
            $ids['Resumen'] => '<p>Resumen</p>',
            $ids['Precio'] => '42.5',
            $ids['Foto'] => ['sample.png', ''],
        ]);
    }

    /**
     * One exported entry's whole value row for a field, keys included.
     *
     * @param array $entry One exported example entry.
     * @param string $fieldname The field whose row is wanted.
     * @return array|null The row, or null when the field shipped no value.
     */
    private function value_row(array $entry, string $fieldname): ?array {
        foreach ($entry['values'] as $pair) {
            if ($pair['field_name'] === $fieldname) {
                return $pair;
            }
        }
        return null;
    }

    /**
     * Flatten one exported entry into field name => value, order preserved.
     *
     * @param array $entry One exported example entry.
     * @return array<string, string>
     */
    private function values_by_name(array $entry): array {
        $values = [];
        foreach ($entry['values'] as $pair) {
            $values[$pair['field_name']] = $pair['value'];
        }
        return $values;
    }

    /**
     * Types without their own export still ship the minimal pair, so adding
     * the URL branch cannot have changed them.
     */
    public function test_other_types_still_export_only_name_and_section(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('page', ['name' => 'A page']);

        $this->assertSame(['name' => 'A page', 'section' => 0], $params);
    }

    /**
     * The lesson branch keeps shipping its settings and ordered pages.
     */
    public function test_lesson_still_exports_settings_and_pages(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('lesson', ['name' => 'A lesson', 'progressbar' => 1]);

        $this->assertSame('A lesson', $params['name']);
        $this->assertSame(1, (int) $params['progressbar']);
        $this->assertArrayHasKey('pages', $params['mod_settings']);
    }
}
