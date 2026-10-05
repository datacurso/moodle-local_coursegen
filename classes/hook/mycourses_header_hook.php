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

namespace local_coursegen\hook;

use core\hook\output\before_http_headers;

/**
 * Hook to add the "Create with AI" button to the My courses page.
 *
 * The button is spliced server side into the rendered page next to core's
 * course action buttons, so it is present in the first HTML the browser
 * receives. The container holding those buttons differs between Moodle
 * versions (page header on 4.5/5.0, Course overview block on 5.2, empty-state
 * action bar when the user has no courses), so the splice tries each known
 * container in turn.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mycourses_header_hook {
    /** @var string Form id rendered by the button template; used as the idempotency marker. */
    private const BUTTON_FORM_ID = 'id="local_coursegen_aicourseform"';

    /** @var string Class marker of the header button group on Moodle 4.5/5.0. */
    private const HEADER_GROUP_MARKER = 'my-action-buttons my-action-buttons-right';

    /** @var string Attribute marker of the Course overview block on Moodle 5.2. */
    private const MYOVERVIEW_MARKER = 'data-region="myoverview"';

    /** @var string Attribute marker of the filter row that follows the block's course actions. */
    private const MYOVERVIEW_FILTER_MARKER = 'data-region="filter"';

    /** @var string Id marker of the empty-state action bar. */
    private const ZERO_STATE_MARKER = 'id="action_bar"';

    /**
     * Hook entrypoint: start an output buffer that splices the button into the page.
     *
     * before_http_headers is dispatched at the top of core_renderer::header(),
     * once the page script has set the URL and context and require_login() has
     * run, and before core starts its own buffer for the layout file. Every
     * byte of the page from this point on passes through the buffer callback
     * at the end of the request.
     *
     * @param before_http_headers $hook Hook object.
     */
    public static function before_http_headers(before_http_headers $hook): void {
        if (AJAX_SCRIPT || WS_SERVER) {
            return;
        }

        $page = $hook->renderer->get_page();

        if (!self::should_inject($page)) {
            return;
        }

        $buttonhtmlfragment = $hook->renderer->render_from_template('local_coursegen/add_ai_course_button', [
            'url' => (new \moodle_url('/local/coursegen/aicoursecreation.php'))->out(false),
        ]);

        ob_start(function (string $htmlbuffer) use ($buttonhtmlfragment): string {
            return self::inject_button_into_buffer($htmlbuffer, $buttonhtmlfragment);
        }, 0, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE);
    }

    /**
     * Whether the button should be injected into the given page for the current user.
     *
     * @param \moodle_page $page The page being rendered.
     * @return bool
     */
    public static function should_inject(\moodle_page $page): bool {
        return self::is_my_courses_page($page) && self::user_can_see_button();
    }

    /**
     * Inject the button fragment into the rendered page buffer.
     *
     * The first matching container wins: the header button group (Moodle
     * 4.5/5.0), the course actions of the Course overview block (Moodle 5.2),
     * then the empty-state action bar (all versions). The buffer is returned
     * unchanged when no container is found or the button is already present.
     *
     * @param string $htmlbuffer Full page HTML buffer.
     * @param string $buttonhtmlfragment Pre-rendered button HTML.
     * @return string Modified buffer, or the original buffer when nothing matched.
     */
    public static function inject_button_into_buffer(string $htmlbuffer, string $buttonhtmlfragment): string {
        if (strpos($htmlbuffer, self::BUTTON_FORM_ID) !== false) {
            return $htmlbuffer;
        }

        // Route 1: Moodle 4.5/5.0, user has enrolled courses — header button group.
        $headergroupstart = self::find_header_button_group_start($htmlbuffer);
        if ($headergroupstart !== null) {
            return self::insert_into_buffer($htmlbuffer, $buttonhtmlfragment, $headergroupstart);
        }

        // Route 2: Moodle 5.2, user has enrolled courses — after the "Create course"
        // form inside the Course overview block.
        $blockformend = self::find_myoverview_create_course_form_end($htmlbuffer);
        if ($blockformend !== null) {
            return self::insert_into_buffer($htmlbuffer, $buttonhtmlfragment, $blockformend);
        }

        // Route 3: no enrolled courses — empty-state action bar. Wrap in
        // singlebutton to keep it inline with core's button.
        $emptystatebarstart = self::find_empty_state_action_bar_start($htmlbuffer);
        if ($emptystatebarstart !== null) {
            $wrappedbutton = \html_writer::div($buttonhtmlfragment, 'singlebutton');
            return self::insert_into_buffer($htmlbuffer, $wrappedbutton, $emptystatebarstart);
        }

        return $htmlbuffer;
    }

    /**
     * Determine if the given page is the My courses page.
     *
     * @param \moodle_page $page The page being rendered.
     * @return bool
     */
    private static function is_my_courses_page(\moodle_page $page): bool {
        if (!$page->has_set_url()) {
            return false;
        }

        return str_ends_with($page->url->get_path(), '/my/courses.php');
    }

    /**
     * Check whether the current user has the capabilities required to see the
     * AI course button.
     *
     * @return bool
     */
    private static function user_can_see_button(): bool {
        $systemcontext = \context_system::instance();

        if (!has_capability('moodle/course:create', $systemcontext)) {
            return false;
        }
        $creationmodes = ['local/coursegen:createfreecoursewithai', 'local/coursegen:createtemplatecoursewithai'];
        return has_any_capability($creationmodes, $systemcontext);
    }

    /**
     * Find where the "my-action-buttons-right" div content starts.
     *
     * Returns the byte offset right after the opening <div ...> tag, or null
     * when the header button group is not present on the page.
     *
     * @param string $htmlbuffer Full page HTML buffer.
     * @return int|null
     */
    private static function find_header_button_group_start(string $htmlbuffer): ?int {
        return self::find_opening_tag_end($htmlbuffer, self::HEADER_GROUP_MARKER);
    }

    /**
     * Find where the "Create course" form of the Course overview block ends.
     *
     * The block (blocks/myoverview/templates/main.mustache on Moodle 5.2)
     * renders the course action forms right after its opening tag and before
     * the filter row, so the search is bounded to that window. Returns the byte
     * offset right after the form's closing </form> tag, or null when the
     * block or the form is not present on the page.
     *
     * @param string $htmlbuffer Full page HTML buffer.
     * @return int|null
     */
    private static function find_myoverview_create_course_form_end(string $htmlbuffer): ?int {
        $blockposition = strpos($htmlbuffer, self::MYOVERVIEW_MARKER);
        if ($blockposition === false) {
            return null;
        }

        $windowend = strpos($htmlbuffer, self::MYOVERVIEW_FILTER_MARKER, $blockposition);
        if ($windowend === false) {
            $windowend = strlen($htmlbuffer);
        }
        $window = substr($htmlbuffer, $blockposition, $windowend - $blockposition);

        $pattern = '~<form\b[^>]*\baction="[^"]*/course/edit\.php[^"]*"[^>]*>~i';
        if (!preg_match($pattern, $window, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $formclose = stripos($htmlbuffer, '</form>', $blockposition + $matches[0][1]);
        return ($formclose === false) ? null : $formclose + strlen('</form>');
    }

    /**
     * Find where the "action_bar" div inside the empty enrollment state starts.
     *
     * This div is rendered by block_myoverview/zero-state.mustache when the
     * user has no enrolled courses. Returns the byte offset right after the
     * opening <div ...> tag, or null when it is not present on the page.
     *
     * @param string $htmlbuffer Full page HTML buffer.
     * @return int|null
     */
    private static function find_empty_state_action_bar_start(string $htmlbuffer): ?int {
        return self::find_opening_tag_end($htmlbuffer, self::ZERO_STATE_MARKER);
    }

    /**
     * Find the byte offset right after the end of the opening tag containing the marker.
     *
     * @param string $htmlbuffer Full page HTML buffer.
     * @param string $marker Attribute text that identifies the opening tag.
     * @return int|null Offset after the tag's closing '>', or null when the marker is absent.
     */
    private static function find_opening_tag_end(string $htmlbuffer, string $marker): ?int {
        $markerposition = strpos($htmlbuffer, $marker);
        if ($markerposition === false) {
            return null;
        }

        $tagclose = strpos($htmlbuffer, '>', $markerposition);
        return ($tagclose === false) ? null : $tagclose + 1;
    }

    /**
     * Insert a string fragment into the buffer at the given byte offset.
     *
     * @param string $htmlbuffer Full page HTML buffer.
     * @param string $fragment HTML to insert.
     * @param int $position Byte offset for insertion.
     * @return string Modified buffer.
     */
    private static function insert_into_buffer(string $htmlbuffer, string $fragment, int $position): string {
        return substr($htmlbuffer, 0, $position) . $fragment . substr($htmlbuffer, $position);
    }
}
