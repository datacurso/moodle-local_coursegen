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

use local_coursegen\local\backup\structure_array_processor;

/**
 * Unit tests for structure_array_processor.
 *
 * These drive pre_process_nested_element()/process_final_element()/
 * post_process_nested_element() directly, in the same call order
 * backup_nested_element::process() uses (open, read finals, read children,
 * close - confirmed against backup/util/structure/backup_nested_element.class.php),
 * to prove the processor's own stack - push on open, pop on close, a final
 * element's value always going into the innermost currently-open element -
 * builds the right tree and never writes into the wrong node.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\backup\structure_array_processor
 */
final class structure_array_processor_test extends \basic_testcase {
    /**
     * backup_nested_element/backup_final_element are not autoloaded classes;
     * this is the same include activity_reader.php requires before using them.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
    }

    /**
     * A final element's value is written into the element that is open when
     * it is processed.
     */
    public function test_final_element_value_is_written_into_open_element(): void {
        $processor = new structure_array_processor();
        $page = new \backup_nested_element('page', ['id']);

        $processor->pre_process_nested_element($page);
        $processor->process_final_element($this->final('title', 'Hello'));
        $processor->post_process_nested_element($page);

        $this->assertSame(['title' => 'Hello'], $processor->get_result());
    }

    /**
     * A final element that was never given a value (is_set() false) is left
     * out of the tree rather than written as null.
     */
    public function test_final_element_with_no_value_set_is_skipped(): void {
        $processor = new structure_array_processor();
        $page = new \backup_nested_element('page');

        $processor->pre_process_nested_element($page);
        $processor->process_final_element(new \backup_final_element('title'));
        $processor->post_process_nested_element($page);

        $this->assertSame([], $processor->get_result());
    }

    /**
     * A final element processed with nothing open yet (no matching
     * pre_process_nested_element call) is ignored rather than writing into a
     * stack slot that does not exist.
     */
    public function test_final_element_before_anything_is_open_is_ignored(): void {
        $processor = new structure_array_processor();

        $processor->process_final_element($this->final('title', 'Orphan'));

        $this->assertSame([], $processor->get_result());
    }

    /**
     * A value processed while an inner element is open lands in that inner
     * element's own node, never in the outer element that is still open
     * around it - proving the stack's "last opened" slot, not some fixed
     * position, decides where a value goes.
     */
    public function test_value_lands_in_the_innermost_open_element(): void {
        $processor = new structure_array_processor();
        $lesson = new \backup_nested_element('lesson', ['id']);
        $page = new \backup_nested_element('page', ['id']);

        $processor->pre_process_nested_element($lesson);
        $processor->process_final_element($this->final('name', 'My Lesson'));
        $processor->pre_process_nested_element($page);
        $processor->process_final_element($this->final('title', 'Page Title'));
        $processor->post_process_nested_element($page);
        $processor->post_process_nested_element($lesson);

        $result = $processor->get_result();
        $this->assertSame('My Lesson', $result['name']);
        $this->assertArrayNotHasKey('title', $result, 'A child value must never leak into its parent node');
        $this->assertSame('Page Title', $result['page'][0]['title']);
    }

    /**
     * Two final elements of the same open element both survive - the second
     * does not overwrite the first, since they are written under their own
     * distinct names.
     */
    public function test_multiple_final_elements_on_the_same_element_all_appear(): void {
        $processor = new structure_array_processor();
        $page = new \backup_nested_element('page');

        $processor->pre_process_nested_element($page);
        $processor->process_final_element($this->final('title', 'Hello'));
        $processor->process_final_element($this->final('contents', 'Body text'));
        $processor->post_process_nested_element($page);

        $this->assertSame(['title' => 'Hello', 'contents' => 'Body text'], $processor->get_result());
    }

    /**
     * An attribute (built when the element opens) and a final element added
     * afterwards coexist in the same node without either overwriting the
     * other, since pre_process_nested_element() and process_final_element()
     * write to different points in time onto the same array.
     */
    public function test_final_elements_coexist_with_attributes(): void {
        $processor = new structure_array_processor();
        $page = new \backup_nested_element('page', ['id']);
        $page->fill_values(['id' => 7]);

        $processor->pre_process_nested_element($page);
        $processor->process_final_element($this->final('name', 'X'));
        $processor->post_process_nested_element($page);

        $this->assertSame(['id' => 7, 'name' => 'X'], $processor->get_result());
    }

    /**
     * Two occurrences of the same repeated child element both survive, as a
     * list under that child's own name, in the order they closed.
     */
    public function test_repeated_child_elements_accumulate_as_a_list(): void {
        $processor = new structure_array_processor();
        $lesson = new \backup_nested_element('lesson');
        $firstpage = new \backup_nested_element('page');
        $secondpage = new \backup_nested_element('page');

        $processor->pre_process_nested_element($lesson);

        $processor->pre_process_nested_element($firstpage);
        $processor->process_final_element($this->final('title', 'First'));
        $processor->post_process_nested_element($firstpage);

        $processor->pre_process_nested_element($secondpage);
        $processor->process_final_element($this->final('title', 'Second'));
        $processor->post_process_nested_element($secondpage);

        $processor->post_process_nested_element($lesson);

        $result = $processor->get_result();
        $this->assertCount(2, $result['page']);
        $this->assertSame('First', $result['page'][0]['title']);
        $this->assertSame('Second', $result['page'][1]['title']);
    }

    /**
     * Closing an element with nothing open (no matching pre_process call -
     * the exception-mid-walk case core itself does not guard against either)
     * does not throw: array_pop() on an empty stack is null, and the
     * processor treats that as nothing to fold in, rather than crashing or
     * silently folding into the wrong slot.
     */
    public function test_post_process_without_a_matching_pre_process_does_not_throw(): void {
        $processor = new structure_array_processor();

        $processor->post_process_nested_element(new \backup_nested_element('page'));

        $this->assertSame([], $processor->get_result());
    }

    /**
     * The result stays empty while the outermost element is still open, and
     * only reflects the built tree once it closes.
     */
    public function test_result_is_empty_until_the_outermost_element_closes(): void {
        $processor = new structure_array_processor();
        $page = new \backup_nested_element('page');

        $processor->pre_process_nested_element($page);
        $processor->process_final_element($this->final('title', 'Hello'));
        $this->assertSame([], $processor->get_result(), 'Nothing is finished while the element is still open');

        $processor->post_process_nested_element($page);
        $this->assertSame(['title' => 'Hello'], $processor->get_result());
    }

    /**
     * A value containing a pluginfile placeholder is resolved to a real
     * pluginfile.php address when the element that carries it declares a
     * file annotation - proving post_process_nested_element really does
     * hand the closing node to structure_node_resolver::with_file_addresses().
     */
    public function test_pluginfile_placeholder_is_resolved_on_close(): void {
        $processor = new structure_array_processor();
        $processor->set_var(\backup::VAR_CONTEXTID, 99);

        $page = new \backup_nested_element('page', null, ['contents']);
        $page->annotate_files('mod_lesson', 'page_contents', null);

        $processor->pre_process_nested_element($page);
        $processor->process_final_element($this->final('contents', '<img src="@@PLUGINFILE@@/x.png">'));
        $processor->post_process_nested_element($page);

        $contents = $processor->get_result()['contents'];
        $this->assertStringNotContainsString('@@PLUGINFILE@@', $contents);
        $this->assertStringContainsString('pluginfile.php', $contents);
        $this->assertStringContainsString('/99/', $contents);
        $this->assertStringContainsString('mod_lesson', $contents);
        $this->assertStringContainsString('page_contents', $contents);
    }

    /**
     * A ready-to-process backup_final_element carrying the given name/value.
     *
     * @param string $name
     * @param string $value
     * @return \backup_final_element
     */
    private function final(string $name, string $value): \backup_final_element {
        $final = new \backup_final_element($name);
        $final->set_value($value);
        return $final;
    }
}
