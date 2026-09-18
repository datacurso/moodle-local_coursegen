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
