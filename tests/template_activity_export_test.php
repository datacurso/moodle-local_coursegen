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

use local_coursegen\local\service\create_mod_service;
use local_coursegen\local\service\template_activity_export;
use local_coursegen\mod_settings\quiz_settings;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/resourcelib.php');
require_once($CFG->dirroot . '/mod/wiki/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/question/type/numerical/questiontype.php');
require_once(__DIR__ . '/fixtures/h5p_package_fixture.php');

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

    /** @var string The repeat marker the real quiz mold carries in its question text. */
    private const MARKED_QUESTIONTEXT = '[[coursegen:repeat: genera la pregunta por cada unidad '
        . 'del temario del sílabo con sus respuestas correspondientes]]';

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
     * An H5P mold ships its raw description, every instance setting it owns and
     * the grade to pass its grade item carries.
     *
     * mod_h5pactivity has NO maxattempts column: the "attempt options" fieldset
     * is enabletracking/grademethod/reviewmode and nothing else.
     */
    public function test_h5pactivity_exports_intro_settings_and_grade_pass(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('h5pactivity', [
            'name' => 'Game map',
            'intro' => self::MARKED_INTRO,
            'introformat' => FORMAT_HTML,
            // A packed int with several bits set; it travels verbatim.
            'displayoptions' => 12,
            'enabletracking' => 1,
            'grademethod' => 2,
            'reviewmode' => 2,
            'grade' => 80,
            'gradepass' => 55.5,
        ]);

        $this->assertSame('Game map', $params['name']);
        $this->assertSame(self::MARKED_INTRO, $params['intro']);
        $this->assertSame(12, (int) $params['displayoptions']);
        $this->assertSame(1, (int) $params['enabletracking']);
        $this->assertSame(2, (int) $params['grademethod']);
        $this->assertSame(2, (int) $params['reviewmode']);
        $this->assertSame(80, (int) $params['grade']);
        $this->assertSame(55.5, (float) $params['gradepass']);
        // mod_h5pactivity owns no attempt limit at all.
        $this->assertArrayNotHasKey('maxattempts', $params);
    }

    /**
     * The mold's own package is what the service reads its text out of: the
     * main library with its versions, and BOTH text entries raw, markers
     * included. The bytes of everything else never travel.
     */
    public function test_h5pactivity_exports_its_mold_package_raw(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('h5pactivity', ['course' => $course->id]);
        $cm = get_fast_modinfo($course)->get_cm($module->cmid);
        $this->attach_h5p_package($cm, h5p_package_fixture::mold_bytes(), 'mapa-del-juego.h5p');

        $params = template_activity_export::parameters_for($cm);

        $this->assertIsArray($params['moldh5p']);
        $this->assertSame((int) $cm->id, $params['moldh5p']['cmid']);
        $this->assertSame('H5P.Fixture', $params['moldh5p']['mainlibrary']);
        $this->assertSame(1, $params['moldh5p']['majorversion']);
        $this->assertSame(5, $params['moldh5p']['minorversion']);
        $this->assertSame('mapa-del-juego.h5p', $params['moldh5p']['filename']);
        // Byte for byte: these two texts carry the markers the service fills in.
        $this->assertSame(h5p_package_fixture::MOLD_H5P_JSON, $params['moldh5p']['h5pjson']);
        $this->assertSame(h5p_package_fixture::MOLD_CONTENT_JSON, $params['moldh5p']['contentjson']);
        $this->assertStringContainsString('⟦coursegen:tema 1 del silabo⟧', $params['moldh5p']['contentjson']);
    }

    /**
     * An H5P activity whose package is gone still exports: it simply declares
     * that it carries no mold package, rather than throwing.
     */
    public function test_h5pactivity_without_a_package_exports_a_null_mold(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('h5pactivity', ['course' => $course->id]);
        $cm = get_fast_modinfo($course)->get_cm($module->cmid);
        get_file_storage()->delete_area_files($cm->context->id, 'mod_h5pactivity', 'package', 0);

        $params = template_activity_export::parameters_for($cm);

        $this->assertArrayHasKey('moldh5p', $params);
        $this->assertNull($params['moldh5p']);
        // The rest of the activity still travels: only the package is missing.
        $this->assertSame($module->name, $params['name']);
        $this->assertArrayHasKey('enabletracking', $params);
    }

    /**
     * Identity columns never travel: the generated activity lives in another
     * course and owns its own identity.
     */
    public function test_h5pactivity_export_omits_identity_columns(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('h5pactivity', ['intro' => 'Plain', 'introformat' => FORMAT_HTML]);

        foreach (['id', 'course', 'timecreated', 'timemodified', 'introformat'] as $column) {
            $this->assertArrayNotHasKey($column, $params);
        }
    }

    /**
     * Replace an H5P activity's package with the given bytes.
     *
     * @param \cm_info $cm The H5P activity.
     * @param string $bytes The package bytes.
     * @param string $filename Package name, extension included.
     */
    private function attach_h5p_package(\cm_info $cm, string $bytes, string $filename): void {
        $fs = get_file_storage();
        $fs->delete_area_files($cm->context->id, 'mod_h5pactivity', 'package', 0);
        $fs->create_file_from_string([
            'contextid' => $cm->context->id,
            'component' => 'mod_h5pactivity',
            'filearea' => 'package',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], $bytes);
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
     * A Quiz mold ships its raw description and every instance setting.
     */
    public function test_quiz_exports_intro_and_every_instance_setting(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('quiz', [
            'name' => 'Cuestionario plantilla prueba',
            'intro' => self::MARKED_INTRO,
            'introformat' => FORMAT_HTML,
            'timeopen' => 1700000000,
            'timeclose' => 1700600000,
            'timelimit' => 3000,
            'overduehandling' => 'graceperiod',
            'graceperiod' => 600,
            'attempts' => 3,
            'attemptonlast' => 1,
            'delay1' => 60,
            'delay2' => 120,
            'grademethod' => QUIZ_GRADEHIGHEST,
            'grade' => 10.0,
            'decimalpoints' => 1,
            'questiondecimalpoints' => 2,
            'preferredbehaviour' => 'immediatefeedback',
            'canredoquestions' => 1,
            'shuffleanswers' => 0,
            'questionsperpage' => 2,
            'navmethod' => 'seq',
            'showuserpicture' => 1,
            'showblocks' => 1,
            'quizpassword' => 'la contraseña',
            'subnet' => '10.0.0.0/8',
            'browsersecurity' => 'securewindow',
            'completionattemptsexhausted' => 0,
            'completionminattempts' => 2,
            'allowofflineattempts' => 1,
        ]);

        $this->assertSame('Cuestionario plantilla prueba', $params['name']);
        // Raw: the service parses those markers itself.
        $this->assertSame(self::MARKED_INTRO, $params['intro']);
        $this->assertSame(1700000000, (int) $params['timeopen']);
        $this->assertSame(1700600000, (int) $params['timeclose']);
        $this->assertSame(3000, (int) $params['timelimit']);
        $this->assertSame('graceperiod', $params['overduehandling']);
        $this->assertSame(600, (int) $params['graceperiod']);
        $this->assertSame(3, (int) $params['attempts']);
        $this->assertSame(1, (int) $params['attemptonlast']);
        $this->assertSame(60, (int) $params['delay1']);
        $this->assertSame(120, (int) $params['delay2']);
        $this->assertSame((int) QUIZ_GRADEHIGHEST, (int) $params['grademethod']);
        $this->assertSame(10.0, (float) $params['grade']);
        $this->assertSame(1, (int) $params['decimalpoints']);
        $this->assertSame(2, (int) $params['questiondecimalpoints']);
        $this->assertSame('immediatefeedback', $params['preferredbehaviour']);
        $this->assertSame(1, (int) $params['canredoquestions']);
        $this->assertSame(0, (int) $params['shuffleanswers']);
        $this->assertSame(2, (int) $params['questionsperpage']);
        $this->assertSame('seq', $params['navmethod']);
        $this->assertSame(1, (int) $params['showuserpicture']);
        $this->assertSame(1, (int) $params['showblocks']);
        $this->assertSame('10.0.0.0/8', $params['subnet']);
        $this->assertSame('securewindow', $params['browsersecurity']);
        $this->assertSame(0, (int) $params['completionattemptsexhausted']);
        $this->assertSame(2, (int) $params['completionminattempts']);
        $this->assertSame(1, (int) $params['allowofflineattempts']);
        // The column is 'password', but quiz_process_options() reads the form's
        // 'quizpassword' and copies it over, so both names travel.
        $this->assertSame('la contraseña', $params['password']);
        $this->assertSame('la contraseña', $params['quizpassword']);
    }

    /**
     * The mold's pass mark travels, even though the quiz table does not hold it.
     *
     * gradepass lives in grade_items, which is where mod/quiz/view.php reads
     * it from; without this the mold's pass mark was silently lost.
     */
    public function test_quiz_exports_the_grade_to_pass_from_its_grade_item(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('quiz', ['grade' => 10.0, 'gradepass' => 7.0]);

        $this->assertSame(7.0, $params['gradepass']);
        $this->assertSame(10.0, (float) $params['grade']);
    }

    /**
     * A quiz with no pass mark set still exports one, as zero.
     */
    public function test_quiz_without_a_pass_mark_exports_zero(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('quiz', ['grade' => 10.0]);

        $this->assertSame(0.0, $params['gradepass']);
    }

    /**
     * The eight review columns are bitmasks; they travel as the 32 booleans
     * quiz_process_options() packs them from.
     *
     * add_moduleinfo() cannot consume the columns: quiz_process_options()
     * rebuilds each one out of <field><whenname> checkboxes, so shipping the
     * packed integer lost every review setting of the mold.
     */
    public function test_quiz_decodes_the_review_bitmasks_into_their_form_booleans(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('quiz', [
            'marksduring' => 0,
            'marksimmediately' => 1,
            'marksopen' => 0,
            'marksclosed' => 1,
        ]);

        // The reviewmarks column kept exactly two of the four times.
        $this->assertSame(0, $params['marksduring']);
        $this->assertSame(1, $params['marksimmediately']);
        $this->assertSame(0, $params['marksopen']);
        $this->assertSame(1, $params['marksclosed']);
        // Core's two forced invariants are reported as stored, not fought.
        $this->assertSame(1, $params['attemptduring']);
        $this->assertSame(0, $params['overallfeedbackduring']);
        // All 32 booleans travel, and no packed column does.
        foreach (self::review_option_keys() as $key) {
            $this->assertArrayHasKey($key, $params);
            $this->assertIsInt($params[$key]);
        }
        $this->assertCount(32, self::review_option_keys());
    }

    /**
     * Every review boolean quiz_process_options() expects.
     *
     * @return string[]
     */
    private static function review_option_keys(): array {
        $keys = [];
        $fields = ['attempt', 'correctness', 'maxmarks', 'marks',
            'specificfeedback', 'generalfeedback', 'rightanswer', 'overallfeedback'];
        foreach ($fields as $field) {
            foreach (['during', 'immediately', 'open', 'closed'] as $whenname) {
                $keys[] = $field . $whenname;
            }
        }
        return $keys;
    }

    /**
     * A multichoice mold question travels in the shape save_question() reads.
     */
    public function test_quiz_exports_a_multichoice_question(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $quiz] = $this->make_quiz_mold();
        $this->add_mold_question($quiz, 'multichoice', 'two_of_four', [
            'name' => 'Pregunta plantilla',
            'questiontext' => ['text' => self::MARKED_QUESTIONTEXT, 'format' => FORMAT_HTML],
            'generalfeedback' => ['text' => 'Retro ⟦tema⟧', 'format' => FORMAT_HTML],
            'single' => '1',
            'answernumbering' => '123',
            'correctfeedback' => ['text' => 'Correcto ⟦tema⟧', 'format' => FORMAT_HTML],
            'answer' => self::MULTICHOICE_MOLD_ANSWERS,
            'fraction' => ['1.0', '0.0', '0.0', '0.0', '0.0'],
        ], 1, 2.0);

        $question = $this->exported_questions($course, $quiz)[0];

        $this->assertSame('multichoice', $question['qtype']);
        $this->assertSame('Pregunta plantilla', $question['name']);
        // Byte for byte: those markers are what the service expands.
        $this->assertSame(self::MARKED_QUESTIONTEXT, $question['questiontext']['text']);
        $this->assertSame((int) FORMAT_HTML, $question['questiontext']['format']);
        $this->assertSame('Retro ⟦tema⟧', $question['generalfeedback']['text']);
        $this->assertSame(1.0, $question['defaultmark']);
        $this->assertSame(1, $question['single']);
        $this->assertSame(1, $question['shuffleanswers']);
        $this->assertSame('123', $question['answernumbering']);
        $this->assertSame(1, $question['shownumcorrect']);
        $this->assertSame(0, $question['showstandardinstruction']);
        $this->assertSame('Correcto ⟦tema⟧', $question['correctfeedback']['text']);
        $this->assertSame((int) FORMAT_HTML, $question['correctfeedback']['format']);
        $this->assertArrayHasKey('partiallycorrectfeedback', $question);
        $this->assertArrayHasKey('incorrectfeedback', $question);
        // The three authored answers, in id order, raw.
        $this->assertSame(
            ['[[ respuesta 1 ]]', '[[ respuesta 2]]', '[[ respuesta 3 ]]'],
            array_column($question['answer'], 'text')
        );
        $this->assertSame([1.0, 0.0, 0.0], $question['fraction']);
        $this->assertSame('One is odd.', $question['feedback'][0]['text']);
        $this->assertSame(['Hint 1.', 'Hint 2.'], array_column($question['hint'], 'text'));
        // The per-hint grading options are part of multichoice's own form shape.
        $this->assertSame([0, 1], $question['hintclearwrong']);
        $this->assertSame([1, 1], $question['hintshownumcorrect']);
        $this->assertSame(1, $question['page']);
        $this->assertSame(2.0, $question['maxmark']);
    }

    /** @var array The three marker-bearing answers of the real mold, plus the two blanks. */
    private const MULTICHOICE_MOLD_ANSWERS = [
        ['text' => '[[ respuesta 1 ]]', 'format' => FORMAT_HTML],
        ['text' => '[[ respuesta 2]]', 'format' => FORMAT_HTML],
        ['text' => '[[ respuesta 3 ]]', 'format' => FORMAT_HTML],
        ['text' => '', 'format' => FORMAT_HTML],
        ['text' => '', 'format' => FORMAT_HTML],
    ];

    /**
     * A truefalse mold question derives its correct answer, both ways round.
     *
     * The two question_answers rows hold the LOCALISED "True"/"False" labels,
     * which must never travel as text: the qtype writes them itself from
     * get_string(). What the payload needs is the 0|1 the mold was authored
     * with, and a wrong constant there silently mis-grades every attempt.
     */
    public function test_quiz_exports_a_truefalse_question_with_a_derived_correct_answer(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $quiz] = $this->make_quiz_mold();
        $this->add_mold_question($quiz, 'truefalse', 'true', [
            'name' => 'La verdadera',
            'questiontext' => ['text' => 'Es verdadero ⟦tema⟧', 'format' => FORMAT_HTML],
            'correctanswer' => '1',
            'feedbacktrue' => ['text' => 'Bien ⟦tema⟧', 'format' => FORMAT_HTML],
            'feedbackfalse' => ['text' => 'Mal ⟦tema⟧', 'format' => FORMAT_HTML],
            'showstandardinstruction' => 1,
        ], 1, 1.0);
        $this->add_mold_question($quiz, 'truefalse', 'true', [
            'name' => 'La falsa',
            'correctanswer' => '0',
        ], 1, 1.0);

        [$true, $false] = $this->exported_questions($course, $quiz);

        $this->assertSame('truefalse', $true['qtype']);
        $this->assertSame('Es verdadero ⟦tema⟧', $true['questiontext']['text']);
        $this->assertSame(1, $true['correctanswer']);
        $this->assertSame('Bien ⟦tema⟧', $true['feedbacktrue']['text']);
        $this->assertSame('Mal ⟦tema⟧', $true['feedbackfalse']['text']);
        $this->assertSame(1, $true['showstandardinstruction']);
        // The other direction: fraction 1 sits on the false row.
        $this->assertSame(0, $false['correctanswer']);
        // The localised labels of those two rows never travel.
        $this->assertArrayNotHasKey('answer', $true);
        $this->assertArrayNotHasKey('fraction', $true);
    }

    /**
     * A shortanswer mold question ships plain answers, wildcards intact.
     */
    public function test_quiz_exports_a_shortanswer_question(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $quiz] = $this->make_quiz_mold();
        $this->add_mold_question($quiz, 'shortanswer', 'frogtoad', [
            'name' => 'Respuesta corta',
            'questiontext' => ['text' => 'Nombra un anfibio ⟦tema⟧', 'format' => FORMAT_HTML],
            'usecase' => 1,
        ], 1, 1.0);

        $question = $this->exported_questions($course, $quiz)[0];

        $this->assertSame('shortanswer', $question['qtype']);
        $this->assertSame('Nombra un anfibio ⟦tema⟧', $question['questiontext']['text']);
        $this->assertSame(1, $question['usecase']);
        // Plain strings, and the '*' wildcard survives as an answer.
        $this->assertSame(['frog', 'toad', '*'], $question['answer']);
        $this->assertSame([1.0, 0.8, 0.0], $question['fraction']);
        $this->assertSame('Frog is a very good answer.', $question['feedback'][0]['text']);
        $this->assertSame((int) FORMAT_HTML, $question['feedback'][0]['format']);
    }

    /**
     * A numerical mold question ships its per-answer tolerance and unit options.
     */
    public function test_quiz_exports_a_numerical_question(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $quiz] = $this->make_quiz_mold();
        $this->add_mold_question($quiz, 'numerical', 'pi', [
            'name' => 'Numérica',
            'questiontext' => ['text' => '¿Cuánto es pi? ⟦tema⟧', 'format' => FORMAT_HTML],
        ], 1, 1.0);

        $question = $this->exported_questions($course, $quiz)[0];

        $this->assertSame('numerical', $question['qtype']);
        $this->assertSame('¿Cuánto es pi? ⟦tema⟧', $question['questiontext']['text']);
        $this->assertSame(['3.14', '3.142', '3.1', '3', '*'], $question['answer']);
        $this->assertSame([1.0, 0.0, 0.0, 0.0, 0.0], $question['fraction']);
        // One tolerance per answer, parallel to them: it lives in its own table.
        $this->assertSame(['0', '0', '0', '0', '0'], $question['tolerance']);
        $this->assertSame('Very good.', $question['feedback'][0]['text']);
        $this->assertSame((int) \qtype_numerical::UNITNONE, $question['showunits']);
        $this->assertSame(0, $question['unitsleft']);
        $this->assertSame(0, $question['unitgradingtype']);
        $this->assertSame(0.1, $question['unitpenalty']);
    }

    /**
     * An essay mold question ships its editor options and its word-limit gates.
     *
     * qtype_essay writes minwordlimit/maxwordlimit only when the matching
     * minwordenabled/maxwordenabled gate is SET, so the limits have to travel
     * with their gates or the generated question would drop them.
     */
    public function test_quiz_exports_an_essay_question_with_its_word_limit_gates(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $quiz] = $this->make_quiz_mold();
        $this->add_mold_question($quiz, 'essay', 'editor', [
            'name' => 'Ensayo',
            'questiontext' => ['text' => 'Redacta ⟦tema⟧', 'format' => FORMAT_HTML],
            'responseformat' => 'editor',
            'responserequired' => 0,
            'responsefieldlines' => 20,
            'minwordenabled' => 1,
            'minwordlimit' => 50,
            'maxwordenabled' => 1,
            'maxwordlimit' => 200,
            'attachments' => 2,
            'attachmentsrequired' => 1,
            'maxbytes' => 1024,
            'filetypeslist' => '.pdf',
            'graderinfo' => ['text' => 'Para el corrector ⟦tema⟧', 'format' => FORMAT_HTML],
            'responsetemplate' => ['text' => 'Plantilla ⟦tema⟧', 'format' => FORMAT_HTML],
        ], 1, 1.0);

        $question = $this->exported_questions($course, $quiz)[0];

        $this->assertSame('essay', $question['qtype']);
        $this->assertSame('Redacta ⟦tema⟧', $question['questiontext']['text']);
        $this->assertSame('editor', $question['responseformat']);
        $this->assertSame(0, $question['responserequired']);
        $this->assertSame(20, $question['responsefieldlines']);
        $this->assertSame(1, $question['minwordenabled']);
        $this->assertSame(50, $question['minwordlimit']);
        $this->assertSame(1, $question['maxwordenabled']);
        $this->assertSame(200, $question['maxwordlimit']);
        $this->assertSame(2, $question['attachments']);
        $this->assertSame(1, $question['attachmentsrequired']);
        $this->assertSame(1024, $question['maxbytes']);
        $this->assertSame('.pdf', $question['filetypeslist']);
        $this->assertSame('Para el corrector ⟦tema⟧', $question['graderinfo']['text']);
        $this->assertSame((int) FORMAT_HTML, $question['graderinfo']['format']);
        $this->assertSame('Plantilla ⟦tema⟧', $question['responsetemplate']['text']);
    }

    /**
     * An essay without word limits ships neither the limits nor their gates.
     *
     * The gate is read with isset(), so shipping minwordenabled => 0 would
     * still make the consumer store a limit the mold does not have.
     */
    public function test_quiz_essay_without_word_limits_ships_no_gates(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $quiz] = $this->make_quiz_mold();
        $this->add_mold_question($quiz, 'essay', 'editor', ['name' => 'Ensayo libre'], 1, 1.0);

        $question = $this->exported_questions($course, $quiz)[0];

        $this->assertArrayNotHasKey('minwordenabled', $question);
        $this->assertArrayNotHasKey('minwordlimit', $question);
        $this->assertArrayNotHasKey('maxwordenabled', $question);
        $this->assertArrayNotHasKey('maxwordlimit', $question);
    }

    /**
     * A description mold question is only its two texts: it owns no answer and
     * Moodle forces its mark to zero.
     */
    public function test_quiz_exports_a_description_question(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $quiz] = $this->make_quiz_mold();
        $this->add_mold_question($quiz, 'description', 'info', [
            'name' => 'Aviso',
            'questiontext' => ['text' => 'Lee esto ⟦tema⟧', 'format' => FORMAT_HTML],
            'generalfeedback' => ['text' => 'Y esto ⟦tema⟧', 'format' => FORMAT_HTML],
        ], 1);

        $question = $this->exported_questions($course, $quiz)[0];

        $this->assertSame('description', $question['qtype']);
        $this->assertSame('Lee esto ⟦tema⟧', $question['questiontext']['text']);
        $this->assertSame('Y esto ⟦tema⟧', $question['generalfeedback']['text']);
        $this->assertSame(0.0, $question['defaultmark']);
        $this->assertSame(0.0, $question['maxmark']);
        $this->assertArrayNotHasKey('answer', $question);
        $this->assertArrayNotHasKey('fraction', $question);
    }

    /**
     * A gapselect mold question decodes its choices out of question_answers.
     *
     * The qtype stores a choice's GROUP number in the feedback column and
     * always leaves fraction at 0 (see qtype_gapselect_base), so a naive
     * answers export would ship a group as feedback and lose the groups.
     */
    public function test_quiz_exports_a_gapselect_question_with_decoded_choice_groups(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $quiz] = $this->make_quiz_mold();
        $this->add_mold_question($quiz, 'gapselect', 'missingchoiceno', [
            'name' => 'Huecos',
            'questiontext' => ['text' => 'La [[1]] contiene el [[2]] ⟦tema⟧.', 'format' => FORMAT_HTML],
            'choices' => [
                ['answer' => 'célula', 'choicegroup' => '1'],
                ['answer' => 'núcleo', 'choicegroup' => '2'],
                ['answer' => 'ribosoma', 'choicegroup' => '2'],
            ],
            'shuffleanswers' => 1,
            'shownumcorrect' => 1,
        ], 1, 1.0);

        $question = $this->exported_questions($course, $quiz)[0];

        $this->assertSame('gapselect', $question['qtype']);
        // The [[n]] placeholders are positional and travel raw.
        $this->assertSame('La [[1]] contiene el [[2]] ⟦tema⟧.', $question['questiontext']['text']);
        $this->assertSame(1, $question['shuffleanswers']);
        $this->assertSame(1, $question['shownumcorrect']);
        $this->assertArrayHasKey('correctfeedback', $question);
        $this->assertSame([
            ['answer' => 'célula', 'choicegroup' => 1],
            ['answer' => 'núcleo', 'choicegroup' => 2],
            ['answer' => 'ribosoma', 'choicegroup' => 2],
        ], $question['choices']);
        // The answers table is not shipped as answers: it holds the choices.
        $this->assertArrayNotHasKey('answer', $question);
        $this->assertArrayNotHasKey('fraction', $question);
        $this->assertArrayNotHasKey('feedback', $question);
    }

    /**
     * A calculated mold question ships its formula plus every wildcard.
     *
     * The wildcards span three tables, and the definition packs its whole
     * range into one string - "<distribution>:<min>:<max>:<decimals>" - so it
     * travels decoded into the four parts import_datasets() reads back.
     */
    public function test_quiz_exports_a_calculated_question_with_its_datasets(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $quiz] = $this->make_quiz_mold();
        $this->seed_calculated_question($quiz, 'calculated');

        $question = $this->exported_questions($course, $quiz)[0];

        $this->assertSame('calculated', $question['qtype']);
        $this->assertSame('¿Cuánto es {a} + {b}? ⟦tema⟧', $question['questiontext']['text']);
        $this->assertSame(['{a} + {b}'], $question['answer']);
        $this->assertSame([1.0], $question['fraction']);
        $this->assertSame(['0.01'], $question['tolerance']);
        $this->assertSame([1], $question['tolerancetype']);
        $this->assertSame([2], $question['correctanswerlength']);
        $this->assertSame([1], $question['correctanswerformat']);
        $this->assertSame('Correcto ⟦tema⟧', $question['feedback'][0]['text']);
        $this->assertSame(0, $question['synchronize']);
        $this->assertSame('abc', $question['answernumbering']);
        $this->assertSame(1, $question['shuffleanswers']);
        $this->assertSame((int) \qtype_numerical::UNITNONE, $question['showunits']);
        $this->assertSame(0.1, $question['unitpenalty']);
        // The consumer only creates the dataset items on the import path.
        $this->assertTrue($question['import_process']);

        $datasets = $question['dataset'];
        $this->assertCount(2, $datasets);
        $this->assertSame(['a', 'b'], array_column($datasets, 'name'));
        $this->assertSame('uniform', $datasets[0]['distribution']);
        $this->assertSame('1', $datasets[0]['min']);
        $this->assertSame('10', $datasets[0]['max']);
        $this->assertSame('1', $datasets[0]['length']);
        $this->assertSame('private', $datasets[0]['status']);
        $this->assertSame(2, $datasets[0]['itemcount']);
        $this->assertSame(2, $datasets[0]['number_of_items']);
        $this->assertSame([
            ['itemnumber' => 1, 'value' => '3.0'],
            ['itemnumber' => 2, 'value' => '5.0'],
        ], $datasets[0]['datasetitem']);
    }

    /**
     * A calculatedmulti mold question adds the combined feedback trio and its
     * single/shownumcorrect pair, and its answers travel in editor shape.
     */
    public function test_quiz_exports_a_calculatedmulti_question(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $quiz] = $this->make_quiz_mold();
        $this->seed_calculated_question($quiz, 'calculatedmulti');

        $question = $this->exported_questions($course, $quiz)[0];

        $this->assertSame('calculatedmulti', $question['qtype']);
        $this->assertSame(1, $question['single']);
        $this->assertSame(0, $question['shownumcorrect']);
        $this->assertSame('Bien hecho ⟦tema⟧', $question['correctfeedback']['text']);
        $this->assertArrayHasKey('partiallycorrectfeedback', $question);
        $this->assertArrayHasKey('incorrectfeedback', $question);
        // A calculatedmulti answer is a real editor field, not a bare formula.
        $this->assertSame([['text' => '{a} + {b}', 'format' => (int) FORMAT_HTML]], $question['answer']);
        $this->assertCount(2, $question['dataset']);
    }

    /**
     * Questions travel in slot order, each carrying the page and the mark of
     * its own slot.
     *
     * The mark lives on quiz_slots.maxmark, never on the question, and the
     * page is the mold's layout: both are lost if only the question is read.
     */
    public function test_quiz_exports_its_questions_in_slot_order_with_page_and_mark(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $quiz] = $this->make_quiz_mold(['questionsperpage' => 0]);
        $this->add_mold_question($quiz, 'truefalse', 'true', ['name' => 'Primera'], 1, 3.0);
        $this->add_mold_question($quiz, 'shortanswer', 'frogtoad', ['name' => 'Segunda'], 2, 5.0);
        $this->add_mold_question($quiz, 'essay', 'editor', ['name' => 'Tercera'], 2, 7.0);

        $questions = $this->exported_questions($course, $quiz);

        $this->assertSame(['Primera', 'Segunda', 'Tercera'], array_column($questions, 'name'));
        $this->assertSame([1, 2, 2], array_column($questions, 'page'));
        $this->assertSame([3.0, 5.0, 7.0], array_column($questions, 'maxmark'));
    }

    /**
     * A random slot is skipped, and says so.
     *
     * A random slot draws from a question bank CATEGORY of the template's own
     * course, which does not exist in the generated one, and
     * quiz_add_quiz_question() throws on random questions anyway - so it cannot
     * travel. Skipping it silently just handed the admin a shorter quiz with no
     * explanation, which is the one thing worse than skipping it.
     */
    public function test_quiz_random_slot_is_skipped_with_a_warning(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $quiz] = $this->make_quiz_mold(['name' => 'Cuestionario molde', 'questionsperpage' => 0]);
        $this->add_mold_question($quiz, 'truefalse', 'true', ['name' => 'Soportada'], 1, 1.0);
        $this->add_random_mold_slot($quiz);

        $questions = $this->exported_questions($course, $quiz);

        $this->assertDebuggingCalled(
            'local_coursegen: quiz "Cuestionario molde" slot 2 holds a random question, which draws from a '
                . 'question bank category of the template course; it cannot be reproduced in the generated '
                . 'course and is not exported.',
            DEBUG_DEVELOPER
        );
        $this->assertSame(['Soportada'], array_column($questions, 'name'));
    }

    /**
     * A question type outside the supported nine is skipped, and says so too.
     */
    public function test_quiz_unsupported_question_type_is_skipped_with_a_warning(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $quiz] = $this->make_quiz_mold(['name' => 'Cuestionario molde', 'questionsperpage' => 0]);
        $this->add_mold_question($quiz, 'truefalse', 'true', ['name' => 'Soportada'], 1, 1.0);
        $this->add_mold_question($quiz, 'match', 'foursubq', ['name' => 'No soportada'], 1, 1.0);

        $questions = $this->exported_questions($course, $quiz);

        $this->assertDebuggingCalled(
            'local_coursegen: quiz "Cuestionario molde" slot 2 holds a "match" question, which the AI '
                . 'service has no schema for; it is not exported.',
            DEBUG_DEVELOPER
        );
        $this->assertSame(['Soportada'], array_column($questions, 'name'));
    }

    /**
     * A quiz nobody has filled yet still exports: it simply declares no
     * questions, and an empty mod_settings would only make create_mod_service
     * log a discarded-settings warning.
     */
    public function test_quiz_without_questions_exports_no_mod_settings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('quiz', ['name' => 'Cuestionario vacío']);

        $this->assertSame('Cuestionario vacío', $params['name']);
        $this->assertArrayNotHasKey('mod_settings', $params);
    }

    /**
     * Identity columns never travel - nor the packed review bitmasks, which
     * add_moduleinfo() cannot consume.
     */
    public function test_quiz_export_omits_identity_columns(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('quiz', ['intro' => 'Plain', 'introformat' => FORMAT_HTML]);

        $columns = [
            'id', 'course', 'sumgrades', 'timecreated', 'timemodified', 'introformat',
            'reviewattempt', 'reviewcorrectness', 'reviewmaxmarks', 'reviewmarks',
            'reviewspecificfeedback', 'reviewgeneralfeedback', 'reviewrightanswer',
            'reviewoverallfeedback',
        ];
        foreach ($columns as $column) {
            $this->assertArrayNotHasKey($column, $params);
        }
    }

    /**
     * The exported payload is exactly what quiz_settings can consume: fed back
     * into a brand new quiz it rebuilds the mold, slots included.
     */
    public function test_quiz_export_round_trips_through_quiz_settings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $mold] = $this->make_quiz_mold([
            'name' => 'Cuestionario plantilla prueba',
            'questionsperpage' => 0,
        ]);
        $this->add_mold_question($mold, 'multichoice', 'two_of_four', [
            'name' => 'Opción múltiple',
            'questiontext' => ['text' => self::MARKED_QUESTIONTEXT, 'format' => FORMAT_HTML],
            'single' => '1',
            'answer' => self::MULTICHOICE_MOLD_ANSWERS,
            'fraction' => ['1.0', '0.0', '0.0', '0.0', '0.0'],
        ], 1, 3.0);
        $this->add_mold_question($mold, 'truefalse', 'true', [
            'name' => 'Verdadero o falso',
            'correctanswer' => '0',
        ], 2, 5.0);

        $exported = $this->export_cm($course, $mold);

        $clone = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'name' => 'Cuestionario generado',
            'questionsperpage' => 0,
        ]);
        $settings = new quiz_settings(
            (object) ['coursemodule' => $clone->cmid, 'instance' => $clone->id],
            $exported['mod_settings']
        );
        $settings->add_settings();

        $rebuilt = $this->export_cm($course, $clone)['mod_settings']['questions'];
        $original = $exported['mod_settings']['questions'];

        // The whole payload survives the round trip, key for key.
        $this->assertSame($original, $rebuilt);
        // And the things a lost round trip would quietly break.
        $this->assertSame(['multichoice', 'truefalse'], array_column($rebuilt, 'qtype'));
        $this->assertSame([1, 2], array_column($rebuilt, 'page'));
        $this->assertSame([3.0, 5.0], array_column($rebuilt, 'maxmark'));
        $this->assertSame([1.0, 0.0, 0.0], $rebuilt[0]['fraction']);
        $this->assertSame(0, $rebuilt[1]['correctanswer']);
        $this->assertSame(self::MARKED_QUESTIONTEXT, $rebuilt[0]['questiontext']['text']);
    }

    /**
     * A hint's two grading options travel with it, and survive the round trip.
     *
     * multichoice saves its hints with parts (save_hints($q, true)), so
     * "clear incorrect responses" and "show the number of correct responses"
     * are settings of the mold like any other: shipping only the hint text
     * silently reset both on the generated quiz.
     */
    public function test_quiz_multichoice_hint_flags_survive_the_round_trip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $mold] = $this->make_quiz_mold(['questionsperpage' => 0]);
        $this->add_mold_question($mold, 'multichoice', 'one_of_four', [
            'name' => 'Con pistas',
            'hint' => [
                ['text' => 'Pista 1 ⟦tema⟧', 'format' => FORMAT_HTML],
                ['text' => 'Pista 2 ⟦tema⟧', 'format' => FORMAT_HTML],
            ],
            'hintclearwrong' => [0, 1],
            'hintshownumcorrect' => [1, 0],
        ], 1, 2.0);

        $exported = $this->export_cm($course, $mold);
        $question = $exported['mod_settings']['questions'][0];

        $this->assertSame(['Pista 1 ⟦tema⟧', 'Pista 2 ⟦tema⟧'], array_column($question['hint'], 'text'));
        // Parallel to the hints, one entry each, exactly as save_hints() reads them.
        $this->assertSame([0, 1], $question['hintclearwrong']);
        $this->assertSame([1, 0], $question['hintshownumcorrect']);

        $clone = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'name' => 'Cuestionario generado',
            'questionsperpage' => 0,
        ]);
        $settings = new quiz_settings(
            (object) ['coursemodule' => $clone->cmid, 'instance' => $clone->id],
            $exported['mod_settings']
        );
        $settings->add_settings();

        $rebuilt = $this->export_cm($course, $clone)['mod_settings']['questions'][0];

        $this->assertSame($question, $rebuilt);
        $this->assertSame([0, 1], $rebuilt['hintclearwrong']);
        $this->assertSame([1, 0], $rebuilt['hintshownumcorrect']);
    }

    /**
     * A question the author wrote no hint for ships no hint key at all.
     *
     * Inventing empty arrays would make the consumer create blank hints the
     * mold does not have, and turn a hintless question into a three-key
     * payload that no longer matches the mold.
     */
    public function test_quiz_question_without_hints_exports_no_hint_keys(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $quiz] = $this->make_quiz_mold();
        $this->add_mold_question($quiz, 'multichoice', 'one_of_four', [
            'name' => 'Sin pistas',
            'hint' => [
                ['text' => '', 'format' => FORMAT_HTML],
                ['text' => '', 'format' => FORMAT_HTML],
            ],
            'hintclearwrong' => [0, 0],
            'hintshownumcorrect' => [0, 0],
        ], 1, 1.0);

        $question = $this->exported_questions($course, $quiz)[0];

        $this->assertArrayNotHasKey('hint', $question);
        $this->assertArrayNotHasKey('hintclearwrong', $question);
        $this->assertArrayNotHasKey('hintshownumcorrect', $question);
    }

    /**
     * A gapselect hint travels with its two part flags, exactly like multichoice.
     *
     * qtype_gapselect_base::save_question_options() calls save_hints($q, true),
     * so "clear incorrect responses" and "show the number of correct responses"
     * belong to its form shape too. The hints used to leave the export only
     * through the multichoice branch, so every other hint-bearing mold lost
     * them whole.
     */
    public function test_quiz_gapselect_hints_travel_with_their_part_flags(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $mold] = $this->make_quiz_mold(['questionsperpage' => 0]);
        $this->add_mold_question($mold, 'gapselect', 'missingchoiceno', [
            'name' => 'Huecos con pistas',
            'hint' => [
                ['text' => 'Pista 1 ⟦tema⟧', 'format' => FORMAT_HTML],
                ['text' => 'Pista 2', 'format' => FORMAT_HTML],
            ],
            'hintclearwrong' => [1, 0],
            'hintshownumcorrect' => [0, 1],
        ], 1, 2.0);

        $exported = $this->export_cm($course, $mold);
        $question = $exported['mod_settings']['questions'][0];

        $this->assertSame(['Pista 1 ⟦tema⟧', 'Pista 2'], array_column($question['hint'], 'text'));
        $this->assertSame([1, 0], $question['hintclearwrong']);
        $this->assertSame([0, 1], $question['hintshownumcorrect']);

        $rebuilt = $this->round_trip_mod_settings($course, $exported)['questions'][0];

        $this->assertSame($question, $rebuilt);
    }

    /**
     * A shortanswer hint travels alone: its qtype has no parts.
     *
     * qtype_shortanswer calls save_hints() without the $withparts flag, so
     * hintclearwrong and hintshownumcorrect are not part of its form shape and
     * the columns stay null. Shipping them anyway would invent two settings the
     * mold cannot express.
     */
    public function test_quiz_shortanswer_hints_travel_without_the_part_flags(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $mold] = $this->make_quiz_mold(['questionsperpage' => 0]);
        $this->add_mold_question($mold, 'shortanswer', 'frogtoad', [
            'name' => 'Respuesta corta con pistas',
            'hint' => [
                ['text' => 'Pista 1 ⟦tema⟧', 'format' => FORMAT_HTML],
                ['text' => 'Pista 2', 'format' => FORMAT_HTML],
            ],
        ], 1, 1.0);

        $exported = $this->export_cm($course, $mold);
        $question = $exported['mod_settings']['questions'][0];

        $this->assertSame(['Pista 1 ⟦tema⟧', 'Pista 2'], array_column($question['hint'], 'text'));
        $this->assertArrayNotHasKey('hintclearwrong', $question);
        $this->assertArrayNotHasKey('hintshownumcorrect', $question);

        $rebuilt = $this->round_trip_mod_settings($course, $exported)['questions'][0];

        $this->assertSame($question, $rebuilt);
    }

    /**
     * An essay carries no hints at all, however many the payload would allow.
     *
     * qtype_essay::save_question_options() never calls save_hints(), so the
     * hint fields are not on its form: a hint key would be written by nobody.
     */
    public function test_quiz_essay_never_exports_hint_keys(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $quiz] = $this->make_quiz_mold();
        $this->add_mold_question($quiz, 'essay', 'editor', ['name' => 'Ensayo'], 1, 1.0);

        $question = $this->exported_questions($course, $quiz)[0];

        $this->assertArrayNotHasKey('hint', $question);
    }

    /**
     * A question's tags are authored content and travel with it.
     *
     * A mold's author classifies the bank with tags; without them the generated
     * questions land unclassified and the mold's taxonomy is lost.
     */
    public function test_quiz_question_tags_travel_and_survive_the_round_trip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $mold] = $this->make_quiz_mold(['questionsperpage' => 0]);
        $question = $this->add_mold_question($mold, 'truefalse', 'true', ['name' => 'Etiquetada'], 1, 1.0);
        \core_tag_tag::set_item_tags(
            'core_question',
            'question',
            $question->id,
            \context_module::instance($mold->cmid),
            ['Álgebra', 'Unidad 1']
        );

        $exported = $this->export_cm($course, $mold);

        $this->assertSame(['Álgebra', 'Unidad 1'], $exported['mod_settings']['questions'][0]['tags']);

        $rebuilt = $this->round_trip_mod_settings($course, $exported)['questions'][0];

        $this->assertSame(['Álgebra', 'Unidad 1'], $rebuilt['tags']);
    }

    /**
     * A question nobody tagged ships no tag key at all.
     */
    public function test_quiz_untagged_question_exports_no_tag_key(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $quiz] = $this->make_quiz_mold();
        $this->add_mold_question($quiz, 'truefalse', 'true', ['name' => 'Sin etiquetas'], 1, 1.0);

        $question = $this->exported_questions($course, $quiz)[0];

        $this->assertArrayNotHasKey('tags', $question);
    }

    /**
     * The two authored slot columns travel and are applied back.
     *
     * requireprevious gates a question behind the previous one and displaynumber
     * overrides the number the student sees; both live on quiz_slots, and
     * quiz_add_quiz_question() takes neither, so without this the mold's
     * numbering and its navigation gate were silently dropped.
     */
    public function test_quiz_slot_display_number_and_dependency_round_trip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $mold] = $this->make_quiz_mold(['questionsperpage' => 0]);
        $this->add_mold_question($mold, 'truefalse', 'true', ['name' => 'Primera'], 1, 1.0);
        $this->add_mold_question($mold, 'shortanswer', 'frogtoad', ['name' => 'Segunda'], 2, 1.0);

        $structure = \mod_quiz\structure::create_for_quiz(\mod_quiz\quiz_settings::create($mold->id));
        $secondslotid = $structure->get_slot_id_for_slot(2);
        $structure->update_slot_display_number($secondslotid, 'A1');
        $structure->update_question_dependency($secondslotid, true);

        $exported = $this->export_cm($course, $mold);
        [$first, $second] = $exported['mod_settings']['questions'];

        // The first slot carries neither, so it ships neither.
        $this->assertArrayNotHasKey('displaynumber', $first);
        $this->assertArrayNotHasKey('requireprevious', $first);
        $this->assertSame('A1', $second['displaynumber']);
        $this->assertSame(1, $second['requireprevious']);

        $rebuilt = $this->round_trip_mod_settings($course, $exported)['questions'];

        $this->assertSame($exported['mod_settings']['questions'], $rebuilt);
    }

    /**
     * A mold's section headings and per-section shuffle travel and are rebuilt.
     *
     * The sections have to be rebuilt after the questions are in place: adding a
     * question on a given page makes quiz_add_quiz_question() shift the
     * firstslot of every section after it.
     */
    public function test_quiz_sections_round_trip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $mold] = $this->make_quiz_mold(['questionsperpage' => 0]);
        $this->add_mold_question($mold, 'truefalse', 'true', ['name' => 'Primera'], 1, 1.0);
        $this->add_mold_question($mold, 'shortanswer', 'frogtoad', ['name' => 'Segunda'], 2, 1.0);

        $structure = \mod_quiz\structure::create_for_quiz(\mod_quiz\quiz_settings::create($mold->id));
        $moldsections = $structure->get_sections();
        $default = reset($moldsections);
        $structure->set_section_heading($default->id, 'Primera parte ⟦tema⟧');
        $structure->set_section_shuffle($default->id, 1);
        $structure->add_section_heading(2, 'Segunda parte');

        $exported = $this->export_cm($course, $mold);

        $this->assertSame([
            ['heading' => 'Primera parte ⟦tema⟧', 'shufflequestions' => 1, 'firstslot' => 1],
            ['heading' => 'Segunda parte', 'shufflequestions' => 0, 'firstslot' => 2],
        ], $exported['mod_settings']['sections']);

        $rebuilt = $this->round_trip_mod_settings($course, $exported);

        // The default section of the generated quiz was updated, not duplicated.
        $this->assertSame($exported['mod_settings']['sections'], $rebuilt['sections']);
    }

    /**
     * A quiz nobody split into sections ships no sections key.
     *
     * Every quiz is created with one empty default section, so reporting it
     * would make every single mold carry a section it never had.
     */
    public function test_quiz_with_only_the_default_section_exports_no_sections_key(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $quiz] = $this->make_quiz_mold();
        $this->add_mold_question($quiz, 'truefalse', 'true', ['name' => 'Unica'], 1, 1.0);

        $params = $this->export_cm($course, $quiz);

        $this->assertArrayNotHasKey('sections', $params['mod_settings']);
    }

    /**
     * The overall feedback bands travel as the two top-level arrays the form owns.
     *
     * quiz_feedback is NOT a mod_settings collection: the quiz mod_form takes
     * the bands as the repeated feedbacktext[] editors and feedbackboundaries[]
     * strings, which quiz_process_options()/quiz_after_add_or_update() turn into
     * rows. Shipping them in any other shape would need a consumer change.
     */
    public function test_quiz_overall_feedback_bands_round_trip_through_add_moduleinfo(): void {
        global $DB, $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $mold] = $this->make_quiz_mold([
            'name' => 'Cuestionario con retroalimentación',
            'grade' => 10.0,
            'questionsperpage' => 0,
        ]);
        // The bands as quiz_after_add_or_update() writes them: highest first,
        // the top one reaching grade + 1 and the lowest one starting at zero.
        $this->add_mold_feedback_band($mold, '<p>Excelente ⟦tema⟧</p>', 5.0, 11.0);
        $this->add_mold_feedback_band($mold, '<p>A repasar</p>', 0.0, 5.0);
        $this->add_mold_question($mold, 'truefalse', 'true', ['name' => 'Primera'], 1, 1.0);

        $exported = $this->export_cm($course, $mold);

        // Top level, never under mod_settings.
        $this->assertSame(['5'], $exported['feedbackboundaries']);
        $this->assertSame(
            ['<p>Excelente ⟦tema⟧</p>', '<p>A repasar</p>'],
            array_column($exported['feedbacktext'], 'text')
        );
        $this->assertArrayNotHasKey('feedbacktext', $exported['mod_settings']);

        // moodleform_mod reads the section off the GLOBAL $COURSE rather than off
        // the course it is handed (course/moodleform_mod.php:532), which a web
        // request always has set and a test does not. Without this the form
        // resolves the section against the site course and dies on a null.
        $PAGE->set_course($course);

        $newcm = create_mod_service::create_from_ai_result(
            [
                'resource_type' => 'quiz',
                'parameters' => array_merge($exported, [
                    'modulename' => 'quiz',
                    'visible' => 1,
                    'cmidnumber' => '',
                    // The export ships `intro` RAW and no `introformat` on
                    // purpose: the description carries markers and it is the AI
                    // service that hands the consumer a filled `introeditor`.
                    // add_moduleinfo() derives intro/introformat from that, and
                    // quiz_after_add_or_update() needs introformat to build the
                    // calendar event. Mirror the real payload here.
                    'introeditor' => [
                        'text' => $exported['intro'],
                        'format' => FORMAT_HTML,
                        'itemid' => 0,
                    ],
                ]),
            ],
            $course,
            0
        );

        $bands = array_values($DB->get_records('quiz_feedback', ['quizid' => $newcm->instance], 'mingrade DESC'));

        $this->assertCount(2, $bands);
        $this->assertSame('<p>Excelente ⟦tema⟧</p>', $bands[0]->feedbacktext);
        $this->assertSame(5.0, (float) $bands[0]->mingrade);
        $this->assertSame(11.0, (float) $bands[0]->maxgrade);
        $this->assertSame('<p>A repasar</p>', $bands[1]->feedbacktext);
        $this->assertSame(0.0, (float) $bands[1]->mingrade);
        $this->assertSame(5.0, (float) $bands[1]->maxgrade);
    }

    /**
     * A quiz with no overall feedback ships no band keys.
     */
    public function test_quiz_without_overall_feedback_exports_no_bands(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = $this->export_module('quiz', ['name' => 'Sin retroalimentación', 'grade' => 10.0]);

        $this->assertArrayNotHasKey('feedbacktext', $params);
        $this->assertArrayNotHasKey('feedbackboundaries', $params);
        $this->assertArrayNotHasKey('boundary_repeats', $params);
    }

    /**
     * Add one overall feedback band to a quiz mold.
     *
     * @param \stdClass $quiz The quiz mold.
     * @param string $text The band's message.
     * @param float $mingrade The grade the band starts at, inclusive.
     * @param float $maxgrade The grade the band ends at, exclusive.
     */
    private function add_mold_feedback_band(\stdClass $quiz, string $text, float $mingrade, float $maxgrade): void {
        global $DB;

        $DB->insert_record('quiz_feedback', (object) [
            'quizid' => $quiz->id,
            'feedbacktext' => $text,
            'feedbacktextformat' => FORMAT_HTML,
            'mingrade' => $mingrade,
            'maxgrade' => $maxgrade,
        ]);
    }

    /**
     * Feed one exported mold back into a brand new quiz and export that again.
     *
     * @param \stdClass $course The course to build the generated quiz in.
     * @param array $exported The mold's exported parameters.
     * @return array The generated quiz's own mod_settings.
     */
    private function round_trip_mod_settings(\stdClass $course, array $exported): array {
        $clone = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'name' => 'Cuestionario generado',
            'questionsperpage' => 0,
        ]);
        $settings = new quiz_settings(
            (object) ['coursemodule' => $clone->cmid, 'instance' => $clone->id],
            $exported['mod_settings']
        );
        $settings->add_settings();

        return $this->export_cm($course, $clone)['mod_settings'];
    }

    /**
     * Create a quiz mold and return it with its course.
     *
     * @param array $options Module generator options.
     * @return array [course, quiz instance]
     */
    private function make_quiz_mold(array $options = []): array {
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', $options + ['course' => $course->id]);

        return [$course, $quiz];
    }

    /**
     * Create one question in the quiz's own category and give it a slot.
     *
     * @param \stdClass $quiz The quiz mold.
     * @param string $qtype Question type to create.
     * @param string|null $which Which example of it (see each qtype's test helper).
     * @param array $overrides Form data overriding that example's.
     * @param int $page The page of the quiz to put it on.
     * @param float|null $maxmark The slot's mark, null for the question's own default.
     * @return \stdClass The created question.
     */
    private function add_mold_question(
        \stdClass $quiz,
        string $qtype,
        ?string $which = null,
        array $overrides = [],
        int $page = 1,
        ?float $maxmark = null
    ): \stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category([
            'contextid' => \context_module::instance($quiz->cmid)->id,
        ]);
        $question = $generator->create_question($qtype, $which, $overrides + ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $quiz, $page, $maxmark);

        return $question;
    }

    /**
     * Add one random slot to a quiz mold.
     *
     * @param \stdClass $quiz The quiz mold.
     */
    private function add_random_mold_slot(\stdClass $quiz): void {
        $category = $this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question_category(['contextid' => \context_module::instance($quiz->cmid)->id]);
        $structure = \mod_quiz\structure::create_for_quiz(\mod_quiz\quiz_settings::create($quiz->id));
        $structure->add_random_questions(1, 1, [
            'filter' => [
                'category' => [
                    'jointype' => \core_question\local\bank\condition::JOINTYPE_DEFAULT,
                    'values' => [$category->id],
                    'filteroptions' => ['includesubcategories' => false],
                ],
            ],
        ]);
    }

    /**
     * Seed one calculated-family question through the plugin's own import path.
     *
     * The core generator's form data carries no dataset at all (save_question()
     * is a multi-page wizard for these types), so the only way to build a mold
     * with real wildcard values is the import path quiz_settings already uses.
     *
     * @param \stdClass $quiz The quiz mold.
     * @param string $qtype Either 'calculated' or 'calculatedmulti'.
     */
    private function seed_calculated_question(\stdClass $quiz, string $qtype): void {
        $dataset = static function (string $name): array {
            return [
                'name' => $name,
                'distribution' => 'uniform',
                'min' => '1',
                'max' => '10',
                'length' => '1',
                'datasetitem' => [
                    ['itemnumber' => 1, 'value' => '3.0'],
                    ['itemnumber' => 2, 'value' => '5.0'],
                ],
            ];
        };
        $ismulti = $qtype === 'calculatedmulti';
        $payload = [
            'qtype' => $qtype,
            'name' => 'Calculada plantilla',
            'questiontext' => ['text' => '¿Cuánto es {a} + {b}? ⟦tema⟧', 'format' => FORMAT_HTML],
            'generalfeedback' => ['text' => '', 'format' => FORMAT_HTML],
            'defaultmark' => 1,
            'penalty' => 0.3333333,
            'synchronize' => 0,
            'answernumbering' => 'abc',
            'shuffleanswers' => 1,
            'showunits' => \qtype_numerical::UNITNONE,
            'unitpenalty' => 0.1,
            'unitgradingtype' => 0,
            'answer' => [$ismulti ? ['text' => '{a} + {b}', 'format' => FORMAT_HTML] : '{a} + {b}'],
            'fraction' => ['1.0'],
            'tolerance' => ['0.01'],
            'tolerancetype' => ['1'],
            'correctanswerlength' => ['2'],
            'correctanswerformat' => ['1'],
            'feedback' => [['text' => 'Correcto ⟦tema⟧', 'format' => FORMAT_HTML]],
            'dataset' => [$dataset('a'), $dataset('b')],
        ];
        if ($ismulti) {
            $payload['single'] = 1;
            $payload['shownumcorrect'] = 0;
            $payload['correctfeedback'] = ['text' => 'Bien hecho ⟦tema⟧', 'format' => FORMAT_HTML];
            $payload['partiallycorrectfeedback'] = ['text' => 'Casi', 'format' => FORMAT_HTML];
            $payload['incorrectfeedback'] = ['text' => 'Mal', 'format' => FORMAT_HTML];
        }

        $cm = (object) ['coursemodule' => $quiz->cmid, 'instance' => $quiz->id];
        (new quiz_settings($cm, ['questions' => [$payload]]))->add_settings();
    }

    /**
     * The questions one quiz mold exports.
     *
     * @param \stdClass $course The course holding it.
     * @param \stdClass $quiz The quiz instance.
     * @return array
     */
    private function exported_questions(\stdClass $course, \stdClass $quiz): array {
        return $this->export_cm($course, $quiz)['mod_settings']['questions'];
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
