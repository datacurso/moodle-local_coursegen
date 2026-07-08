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

use core\hook\after_config;

/**
 * Hook to add the AI course button in My courses header actions without JS.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mycourses_header_hook {
    /**
     * Hook entrypoint: called after config, set up output buffer to inject the
     * AI course button into the My courses header actions.
     *
     * @param after_config $hook Hook object.
     */
    public static function after_config(after_config $hook): void {
        global $PAGE;

        if (!self::is_my_courses_page($PAGE)) {
            return;
        }

        if (!self::user_can_see_button()) {
            return;
        }

        $buttonhtmlfragment = self::render_button_html();

        ob_start(function (string $htmlbuffer) use ($buttonhtmlfragment): string {
            return self::inject_button_into_buffer($htmlbuffer, $buttonhtmlfragment);
        }, 0, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE);
    }

    /**
     * Determine if the current page is the My courses page.
     *
     * @param \moodle_page $page Current page instance.
     * @return bool
     */
    private static function is_my_courses_page(\moodle_page $page): bool {
        return $page->url->get_path() === '/my/courses.php';
    }

    /**
     * Check whether the current user has the capabilities required to see the
     * AI course button.
     *
     * @return bool
     */
    private static function user_can_see_button(): bool {
        $systemcontext = \context_system::instance();

        return has_all_capabilities([
            'moodle/course:create',
            'local/coursegen:createcoursewithai',
        ], $systemcontext);
    }

    /**
     * Render the AI button HTML using the Mustache template.
     *
     * @return string
     */
    private static function render_button_html(): string {
        global $OUTPUT;

        $url = (new \moodle_url('/local/coursegen/aicoursecreation.php'))->out(false);

        return $OUTPUT->render_from_template('local_coursegen/add_ai_course_button', [
            'url' => $url,
        ]);
    }

    /**
     * Inject the AI button into the rendered page buffer.
     *
     * Tries the header button group first (user has enrolled courses), then
     * falls back to the empty state action bar (user has no enrolled courses).
     *
     * @param string $htmlbuffer Full page HTML buffer.
     * @param string $buttonhtmlfragment Pre-rendered button HTML.
     * @return string Modified buffer with the button injected, or original
     *     buffer when no target container can be located.
     */
    private static function inject_button_into_buffer(string $htmlbuffer, string $buttonhtmlfragment): string {
        // Route 1: user has enrolled courses — inject into the header button group.
        $headergroupstart = self::find_header_button_group_start($htmlbuffer);
        if ($headergroupstart !== null) {
            return self::insert_into_buffer($htmlbuffer, $buttonhtmlfragment, $headergroupstart);
        }

        // Route 2: no enrolled courses — inject into the "You're not enrolled"
        // empty state action bar. Wrap in singlebutton to keep it inline.
        $emptystatebarstart = self::find_empty_state_action_bar_start($htmlbuffer);
        if ($emptystatebarstart !== null) {
            $wrappedbutton = \html_writer::div($buttonhtmlfragment, 'singlebutton');
            return self::insert_into_buffer($htmlbuffer, $wrappedbutton, $emptystatebarstart);
        }

        return $htmlbuffer;
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
        $classmarker = 'my-action-buttons my-action-buttons-right';
        $classposition = strpos($htmlbuffer, $classmarker);
        if ($classposition === false) {
            return null;
        }

        $tagclose = strpos($htmlbuffer, '>', $classposition);
        return ($tagclose === false) ? null : $tagclose + 1;
    }

    /**
     * Find where the "action_bar" div inside the empty enrollment state starts.
     *
     * This div is rendered by block_myoverview/zero-state.mustache when the
     * user has no enrolled courses.
     *
     * Returns the byte offset right after the opening <div ...> tag, or null
     * when the empty state action bar is not present on the page.
     *
     * @param string $htmlbuffer Full page HTML buffer.
     * @return int|null
     */
    private static function find_empty_state_action_bar_start(string $htmlbuffer): ?int {
        $idmarker = 'id="action_bar"';
        $idposition = strpos($htmlbuffer, $idmarker);
        if ($idposition === false) {
            return null;
        }

        $tagclose = strpos($htmlbuffer, '>', $idposition);
        return ($tagclose === false) ? null : $tagclose + 1;
    }

    /**
     * Insert a string fragment into the buffer at the given byte offset.
     *
     * @param string $htmlbuffer Full page HTML buffer.
     * @param string $fragment HTML to insert.
     * @param int|null $position Byte offset for insertion. Null returns the
     *     original buffer unchanged.
     * @return string Modified buffer.
     */
    private static function insert_into_buffer(string $htmlbuffer, string $fragment, ?int $position): string {
        if ($position === null) {
            return $htmlbuffer;
        }

        return substr($htmlbuffer, 0, $position) . $fragment . substr($htmlbuffer, $position);
    }
}
