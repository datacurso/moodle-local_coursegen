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

/**
 * Shared fixtures and markup-extraction helpers for the sections_config
 * render test suites (course_sections_layout_test, course_sections_actions_
 * test, course_sections_saved_config_test) — extracted so those files never
 * duplicate the same course fixture or select-extraction logic.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

/**
 * Fixture and extraction helpers, mixed into an \advanced_testcase subclass.
 */
trait sections_config_fixture_trait {
    /**
     * Create a 2-section course with a page in section 1 and a forum plus an
     * LTI external tool in section 2.
     *
     * Page and forum are both in template_content_generator::AI_SUPPORTED_TYPES;
     * lti is NOT — it is the "unsupported type" fixture for the rows that
     * must not offer (nor default to) the "template" action.
     *
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass,3:\stdClass,4:\stdClass} Course, page, forum, lti and label records.
     */
    private function create_course_fixture(): array {
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'section' => 2]);
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id, 'section' => 2]);
        // A label has no view URL of its own — the "name renders as plain
        // text, never a dead link" fixture.
        $label = $this->getDataGenerator()->create_module('label', ['course' => $course->id, 'section' => 2]);
        return [$course, $page, $forum, $lti, $label];
    }

    /**
     * Extract one activity row's action <select> markup (opening tag up to
     * its closing tag) so per-row option assertions cannot accidentally
     * match a different row's options.
     *
     * @param string $html Full rendered review.
     * @param int $cmid Course module id the select belongs to.
     * @return string The select markup, without the closing tag.
     */
    private function extract_action_select(string $html, int $cmid): string {
        return $this->extract_select($html, 'data-region="activity-action" data-id="' . $cmid . '"');
    }

    /**
     * Extract one activity row's clickable "Template" tag markup (opening
     * tag through its closing tag), so per-row assertions on its visibility
     * or label cannot accidentally match a different row's tag.
     *
     * @param string $html Full rendered review.
     * @param int $cmid Course module id the tag belongs to.
     * @return string The tag's full markup, including its closing tag.
     */
    private function extract_template_tag(string $html, int $cmid): string {
        $marker = 'data-region="template-tag" data-id="' . $cmid . '"';
        $markerpos = strpos($html, $marker);
        $this->assertNotFalse($markerpos, 'No template tag rendered matching: ' . $marker);
        $tagstart = strrpos(substr($html, 0, $markerpos), '<button');
        $this->assertNotFalse($tagstart, 'Unopened template tag matching: ' . $marker);
        $tagend = strpos($html, '</button>', $tagstart);
        $this->assertNotFalse($tagend, 'Unterminated template tag matching: ' . $marker);
        return substr($html, $tagstart, $tagend + strlen('</button>') - $tagstart);
    }

    /**
     * Extract one section's behavior <select> markup, same scoping idea as
     * extract_action_select().
     *
     * @param string $html Full rendered review.
     * @param int $sectionid Base course section id the select belongs to.
     * @return string The select markup, without the closing tag.
     */
    private function extract_behavior_select(string $html, int $sectionid): string {
        return $this->extract_select($html, 'data-region="section-behavior" data-sid="' . $sectionid . '"');
    }

    /**
     * Extract the markup of a <select> identified by a unique marker string.
     *
     * @param string $html Full rendered review.
     * @param string $marker A substring that appears once, inside the opening <select> tag.
     * @return string The select markup, without the closing tag.
     */
    private function extract_select(string $html, string $marker): string {
        $start = strpos($html, $marker);
        $this->assertNotFalse($start, 'No select rendered matching: ' . $marker);
        $end = strpos($html, '</select>', $start);
        $this->assertNotFalse($end, 'Unterminated select matching: ' . $marker);
        return substr($html, $start, $end - $start);
    }
}
