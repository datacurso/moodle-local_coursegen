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

use local_coursegen\local\placeholder\marker_scanner;
use local_coursegen\local\models\template;
use local_coursegen\local\service\template_keep_copier;
use local_coursegen\local\service\template_schedule_builder;

/**
 * The schedule table a page of a generated course gets in place of the schedule marker.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_schedule_builder
 * @covers     \local_coursegen\local\placeholder\marker_scanner
 *
 * @runTestsInSeparateProcesses
 */
final class template_schedule_builder_test extends \advanced_testcase {
    /** @var string The marker the author writes in a page of the template. */
    private const MARKER = '[[coursegen:schedule]]';

    /**
     * A course: 1 Intro, 2 Unit A with subsections 3 and 4 (flexsections), 5 Unit B.
     *
     * @param string $format
     * @return \stdClass
     */
    private function create_course(string $format = 'flexsections'): \stdClass {
        $course = $this->getDataGenerator()->create_course(['format' => $format, 'numsections' => 0]);
        $courseformat = course_get_format($course);
        $tree = [1 => [0, 'Intro'], 2 => [0, 'Unit A'], 3 => [2, 'Resources'], 4 => [2, 'Work'], 5 => [0, 'Unit B']];
        foreach ($tree as $number => [$parent, $name]) {
            $section = course_create_section($course, $number);
            if ($format === 'flexsections') {
                $courseformat->update_section_format_options(['id' => $section->id, 'parent' => $parent]);
            }
            course_update_section($course, $section, ['name' => $name]);
        }
        rebuild_course_cache($course->id, true);
        return get_course($course->id);
    }

    /**
     * Add an activity.
     *
     * @param \stdClass $course
     * @param string $module
     * @param int $section
     * @param string $name
     * @param array $extra
     * @return \stdClass
     */
    private function add(\stdClass $course, string $module, int $section, string $name, array $extra = []): \stdClass {
        return $this->getDataGenerator()->create_module(
            $module,
            ['course' => $course->id, 'section' => $section, 'name' => $name] + $extra
        );
    }

    /**
     * A page holding the marker, in a section.
     *
     * @param \stdClass $course
     * @param int $section
     * @param string|null $content
     * @return \stdClass
     */
    private function add_schedule_page(\stdClass $course, int $section = 1, ?string $content = null): \stdClass {
        return $this->add($course, 'page', $section, 'Schedule', ['content' => $content ?? '<p>' . self::MARKER . '</p>']);
    }

    /**
     * The content of a page as stored.
     *
     * @param \stdClass $page
     * @return string
     */
    private function content_of(\stdClass $page): string {
        global $DB;
        return (string) $DB->get_field('page', 'content', ['id' => $page->id]);
    }

    /**
     * The body rows of the table, each as unit, activity, type and date, whatever the rowspan of the unit cell.
     *
     * @param string $html
     * @return array[]
     */
    private function rows_of(string $html): array {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $rows = [];
        $unit = '';
        foreach ($dom->getElementsByTagName('tr') as $tr) {
            if ($tr->parentNode->nodeName === 'thead') {
                continue;
            }
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof \DOMElement) {
                    $cells[] = trim($cell->textContent);
                }
            }
            if (count($cells) === 4) {
                $unit = $cells[0];
            } else {
                array_unshift($cells, $unit);
            }
            $rows[] = $cells;
        }
        return $rows;
    }

    /**
     * The date cell of each activity row, by activity name.
     *
     * @param \stdClass $page
     * @return string[]
     */
    private function dates_of(\stdClass $page): array {
        $dates = [];
        foreach ($this->rows_of($this->content_of($page)) as $row) {
            $dates[$row[1]] = $row[3];
        }
        return $dates;
    }

    /**
     * An availability with date conditions.
     *
     * @param string $op The operator of the group.
     * @param array $conditions Each [direction, time].
     * @return string
     */
    private function availability(string $op, array $conditions): string {
        $children = [];
        foreach ($conditions as [$direction, $time]) {
            $children[] = ['type' => 'date', 'd' => $direction, 't' => $time];
        }
        $group = ['op' => $op, 'c' => $children];
        if ($op === '|') {
            $group['show'] = true;
        } else {
            $group['showc'] = array_fill(0, count($children), true);
        }
        return json_encode($group);
    }

    /**
     * Units and activities come in order, with the type and the dash when no date applies.
     */
    public function test_lists_the_units_and_their_activities_in_order(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $this->add($course, 'forum', 3, 'Forum one');
        $this->add($course, 'assign', 4, 'Task one');
        $this->add($course, 'page', 2, 'Unit A page');
        $this->add($course, 'quiz', 5, 'Final quiz');
        $schedule = $this->add_schedule_page($course, 1);
        $this->add($course, 'page', 1, 'Welcome');

        template_schedule_builder::apply((int) $course->id);

        $html = $this->content_of($schedule);
        $this->assertStringContainsString('<table class="generaltable', $html);
        $rows = $this->rows_of($html);
        $this->assertSame(['Welcome', 'Unit A page', 'Forum one', 'Task one', 'Final quiz'], array_column($rows, 1));
        $this->assertSame(['Intro', 'Unit A', 'Unit A', 'Unit A', 'Unit B'], array_column($rows, 0));
        $this->assertSame(get_string('modulename', 'mod_forum'), $rows[2][2]);
        $this->assertSame(get_string('modulename', 'mod_assign'), $rows[3][2]);
        $this->assertSame('—', $rows[3][3]);
        $this->assertStringNotContainsString('coursegen:', $html);
    }

    /**
     * The name of an activity links to its page in the course.
     */
    public function test_the_activity_name_links_to_the_activity(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $forum = $this->add($course, 'forum', 3, 'Linked forum');
        $schedule = $this->add_schedule_page($course);

        template_schedule_builder::apply((int) $course->id);

        $cm = get_fast_modinfo($course)->get_cm($forum->cmid);
        $this->assertStringContainsString('href="' . s($cm->url->out(false)) . '"', $this->content_of($schedule));
    }

    /**
     * Hidden activities and labels are left out; a unit with no activity keeps its row.
     */
    public function test_leaves_out_hidden_activities_and_labels_but_keeps_empty_units(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $this->add($course, 'forum', 3, 'Shown forum');
        $this->add($course, 'forum', 3, 'Hidden forum', ['visible' => 0]);
        $this->add($course, 'label', 3, 'Label text', ['intro' => '<p>Label text</p>']);
        $schedule = $this->add_schedule_page($course);

        template_schedule_builder::apply((int) $course->id);

        $html = $this->content_of($schedule);
        $this->assertStringContainsString('Shown forum', $html);
        $this->assertStringNotContainsString('Hidden forum', $html);
        $this->assertStringNotContainsString('Label text', $html);
        $this->assertContains('Unit B', array_column($this->rows_of($html), 0));
    }

    /**
     * The opening date is the one of the activity, else the one of its section or the unit it hangs under.
     */
    public function test_shows_the_date_the_activity_opens(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $own = mktime(12, 0, 0, 3, 10, 2030);
        $unit = mktime(12, 0, 0, 4, 20, 2030);
        $this->add($course, 'forum', 3, 'Own date', ['availability' => $this->availability('&', [['>=', $own]])]);
        $this->add($course, 'forum', 3, 'Unit date');
        $this->add($course, 'forum', 5, 'No date');
        $this->add($course, 'forum', 5, 'Closing only', ['availability' => $this->availability('&', [['<', $own]])]);
        $DB->set_field(
            'course_sections',
            'availability',
            $this->availability('&', [['>=', $unit]]),
            ['course' => $course->id, 'section' => 2]
        );
        rebuild_course_cache($course->id, true);
        $schedule = $this->add_schedule_page($course);

        template_schedule_builder::apply((int) $course->id);

        $dates = $this->dates_of($schedule);
        $format = get_string('strftimedatefullshort', 'langconfig');
        $this->assertSame(userdate($own, $format), $dates['Own date']);
        $this->assertSame(userdate($unit, $format), $dates['Unit date']);
        $this->assertSame('—', $dates['No date']);
        $this->assertSame('—', $dates['Closing only']);
    }

    /**
     * An any-of restriction opens at its earliest date and an all-of one at its latest.
     */
    public function test_combined_dates_follow_the_logic_of_the_restriction(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $early = mktime(12, 0, 0, 3, 1, 2030);
        $late = mktime(12, 0, 0, 5, 1, 2030);
        $both = [['>=', $early], ['>=', $late]];
        $this->add($course, 'forum', 3, 'All of', ['availability' => $this->availability('&', $both)]);
        $this->add($course, 'forum', 3, 'Any of', ['availability' => $this->availability('|', $both)]);
        $schedule = $this->add_schedule_page($course);

        template_schedule_builder::apply((int) $course->id);

        $dates = $this->dates_of($schedule);
        $format = get_string('strftimedatefullshort', 'langconfig');
        $this->assertSame(userdate($late, $format), $dates['All of']);
        $this->assertSame(userdate($early, $format), $dates['Any of']);
    }

    /**
     * Names are escaped.
     */
    public function test_escapes_names(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $this->add($course, 'forum', 3, '<script>alert(1)</script> & "q"');
        $schedule = $this->add_schedule_page($course);

        template_schedule_builder::apply((int) $course->id);

        $html = $this->content_of($schedule);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;q&quot;', $html);
    }

    /**
     * Both dialects of the marker, alone in a paragraph or inside text, are replaced and none is left.
     */
    public function test_replaces_every_form_of_the_marker(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $this->add($course, 'forum', 3, 'A forum');
        $first = $this->add_schedule_page($course, 1, '<h3>Heading</h3><p>&nbsp;[[ coursegen:schedule ]]&nbsp;</p><p>End</p>');
        $second = $this->add_schedule_page($course, 1, 'Before ⟦coursegen:schedule⟧ after ' . self::MARKER);

        template_schedule_builder::apply((int) $course->id);

        foreach ([$first, $second] as $page) {
            $html = $this->content_of($page);
            $this->assertStringContainsString('<table', $html);
            $this->assertStringNotContainsString('coursegen', $html);
            $this->assertStringNotContainsString('⟦', $html);
        }
        $html = $this->content_of($first);
        $this->assertStringContainsString('<h3>Heading</h3>', $html);
        $this->assertStringContainsString('<p>End</p>', $html);
        $this->assertStringNotContainsString('<p><table', $html);
    }

    /**
     * A page without the marker is not touched, nor is its revision.
     */
    public function test_a_page_without_the_marker_is_left_as_it_is(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $page = $this->add($course, 'page', 1, 'Plain', ['content' => '<p>Nothing here</p>']);
        $before = $DB->get_record('page', ['id' => $page->id]);

        template_schedule_builder::apply((int) $course->id);

        $this->assertEquals($before, $DB->get_record('page', ['id' => $page->id]));
    }

    /**
     * A page that is rewritten gets a new revision, so a cached copy is not served.
     */
    public function test_a_rewritten_page_gets_a_new_revision(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $page = $this->add_schedule_page($course);
        $before = (int) $DB->get_field('page', 'revision', ['id' => $page->id]);

        template_schedule_builder::apply((int) $course->id);

        $this->assertSame($before + 1, (int) $DB->get_field('page', 'revision', ['id' => $page->id]));
    }

    /**
     * A course with no unit gets a short note in place of the table.
     */
    public function test_a_course_with_nothing_to_list_gets_a_note(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 0]);
        $schedule = $this->add_schedule_page($course, 0);

        template_schedule_builder::apply((int) $course->id);

        $html = $this->content_of($schedule);
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringContainsString(get_string('schedule_empty', 'local_coursegen'), $html);
        $this->assertStringNotContainsString('coursegen:', $html);
    }

    /**
     * A flat format lists its sections as the units.
     */
    public function test_a_flat_format_uses_its_sections_as_units(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course('topics');
        $this->add($course, 'forum', 2, 'Topic forum');
        $this->add($course, 'forum', 3, 'Sub forum');
        $schedule = $this->add_schedule_page($course);

        template_schedule_builder::apply((int) $course->id);

        $rows = $this->rows_of($this->content_of($schedule));
        // The section of the schedule page has nothing else to list, so it keeps only its row.
        $this->assertSame(['—', 'Topic forum', 'Sub forum'], array_slice(array_column($rows, 1), 0, 3));
        $this->assertSame(['Intro', 'Unit A', 'Resources'], array_slice(array_column($rows, 0), 0, 3));
    }

    /**
     * The column headings are the strings of the plugin.
     */
    public function test_the_headings_are_the_plugin_strings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $this->add($course, 'forum', 3, 'A forum');
        $schedule = $this->add_schedule_page($course);

        template_schedule_builder::apply((int) $course->id);

        $html = $this->content_of($schedule);
        foreach (['unit', 'activity', 'type', 'from'] as $column) {
            $this->assertStringContainsString('>' . get_string('schedule_column_' . $column, 'local_coursegen') . '<', $html);
        }
    }

    /**
     * A page of the template kept as it is reaches the new course with its marker, and the builder writes the table
     * with the activities that are there when it runs.
     */
    public function test_a_kept_page_of_the_template_gets_the_table_in_the_new_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->create_course();
        $this->add_schedule_page($source, 1, '<h3>Cronograma</h3><p>' . self::MARKER . '</p>');
        $template = new template(0, (object) ['name' => 'Schedule', 'courseid' => $source->id]);
        $template->create();
        $target = $this->create_course();
        $this->add($target, 'forum', 3, 'Generated forum');

        template_keep_copier::copy_into((int) $template->get('id'), (int) $target->id);
        $copied = $DB->get_record('page', ['course' => $target->id], '*', MUST_EXIST);
        $this->assertStringContainsString(self::MARKER, $copied->content);

        template_schedule_builder::apply((int) $target->id);

        $html = $this->content_of($copied);
        $this->assertStringContainsString('<h3>Cronograma</h3>', $html);
        $this->assertContains('Generated forum', array_column($this->rows_of($html), 1));
        $this->assertStringNotContainsString('coursegen', $html);
        // The template's own course is not touched.
        $this->assertStringContainsString(self::MARKER, (string) $DB->get_field('page', 'content', ['course' => $source->id]));
    }

    /**
     * The marker is a known one for the scanner: not a placeholder and not a problem, unless it carries text.
     */
    public function test_the_scanner_accepts_the_marker(): void {
        $scan = marker_scanner::scan('<p>' . self::MARKER . '</p><p>⟦coursegen:schedule⟧</p>');
        $this->assertSame(0, $scan->placeholders);
        $this->assertSame([], $scan->problems);
        $bad = marker_scanner::scan('<p>[[coursegen:schedule: with text]]</p>');
        $this->assertNotSame([], $bad->problems);
    }
}
