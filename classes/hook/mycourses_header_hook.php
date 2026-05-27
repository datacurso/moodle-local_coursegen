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
        if (self::is_non_web_runtime()) {
            return;
        }

        if (!self::is_my_courses_request()) {
            return;
        }

        if (!self::user_can_see_button()) {
            return;
        }

        $buttonhtmlfragment = self::render_button_html();

        ob_start(function (string $htmlbuffer) use ($buttonhtmlfragment): string {
            return self::inject_button_into_buffer($htmlbuffer, $buttonhtmlfragment);
        }, 0, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE | PHP_OUTPUT_HANDLER_REMOVABLE);
    }

    /**
     * Determine if the current page is the My courses page.
     *
     * @param \moodle_page $page Current page instance.
     * @return bool
     */
    private static function is_my_courses_page(\moodle_page $page): bool {
        if (!$page->has_set_url()) {
            return false;
        }

        return $page->url->get_path() === '/my/courses.php';
    }

    /**
     * Determine whether current runtime should skip page-level output hooks.
     *
     * @return bool
     */
    private static function is_non_web_runtime(): bool {
        if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
            return true;
        }

        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the current HTTP request targets /my/courses.php.
     *
     * @return bool
     */
    private static function is_my_courses_request(): bool {
        $scriptname = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptname !== '' && str_ends_with($scriptname, '/my/courses.php')) {
            return true;
        }

        $requesturi = (string)($_SERVER['REQUEST_URI'] ?? '');
        if ($requesturi === '') {
            return false;
        }

        $path = parse_url($requesturi, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return false;
        }

        return str_ends_with($path, '/my/courses.php');
    }

    /**
     * Check whether the current user has the capabilities required to see the
     * AI course button.
     *
     * @return bool
     */
    private static function user_can_see_button(): bool {
        $systemcontext = \context_system::instance();

        return \has_all_capabilities([
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
        $url = (new \moodle_url('/local/coursegen/aicoursecreation.php'))->out(false);
        $label = \get_string('createwithai', 'local_coursegen');

        $html = '';
        $html .= '<form action="' . \s($url) . '" method="get" id="local_coursegen_aicourseform">';
        $html .= '<button type="submit" name="addcourseai"';
        $html .= ' class="btn btn-outline-secondary m-1 w-100 datacurso-ai-button"';
        $html .= ' data-action="local_coursegen/add_ai_course">';
        $html .= '<i class="fa fa-magic" aria-hidden="true"></i> ';
        $html .= \s($label);
        $html .= '</button>';
        $html .= '</form>';

        return $html;
    }

    /**
     * Inject the rendered button HTML into the My courses header actions
     * container inside the output buffer.
     *
     * @param string $htmlbuffer Full page HTML buffer.
     * @param string $buttonhtmlfragment Pre-rendered button HTML.
     * @return string Modified buffer with the button injected, or original
     *     buffer when the target container cannot be located.
     */
    private static function inject_button_into_buffer(string $htmlbuffer, string $buttonhtmlfragment): string {
        $insertionposition = self::resolve_insert_position($htmlbuffer);

        return self::apply_insertion($htmlbuffer, $buttonhtmlfragment, $insertionposition);
    }

    /**
     * Locate the position in the buffer where the header actions container
     * (my-action-buttons-right) is defined.
     *
     * @param string $htmlbuffer Full page HTML buffer.
     * @return int|null Byte offset of the class marker, or null when not
     *     present.
     */
    private static function get_actions_container_position(string $htmlbuffer): ?int {
        $actionscontainerclass = 'my-action-buttons my-action-buttons-right';
        $actionscontainerposition = strpos($htmlbuffer, $actionscontainerclass);

        if ($actionscontainerposition === false) {
            return null;
        }

        return $actionscontainerposition;
    }

    /**
     * Determine the insertion position just after the opening div that holds
     * the header actions container.
     *
     * @param string $htmlbuffer Full page HTML buffer.
     * @param int $actionscontainerposition Position where the class marker was found.
     * @return int|null Byte offset where the button HTML should be inserted,
     *     or null when the closing '>' cannot be located.
     */
    private static function get_insert_position(string $htmlbuffer, int $actionscontainerposition): ?int {
        $closingdivposition = strpos($htmlbuffer, '>', $actionscontainerposition);

        if ($closingdivposition === false) {
            return null;
        }

        return $closingdivposition + 1;
    }

    /**
     * Resolve the final insertion position in the buffer, or null when the
     * target container cannot be located.
     *
     * @param string $htmlbuffer Full page HTML buffer.
     * @return int|null Byte offset for insertion, or null if not applicable.
     */
    private static function resolve_insert_position(string $htmlbuffer): ?int {
        $actionscontainerposition = self::get_actions_container_position($htmlbuffer);

        if ($actionscontainerposition === null) {
            return null;
        }

        return self::get_insert_position($htmlbuffer, $actionscontainerposition);
    }

    /**
     * Apply the HTML insertion at the given position, returning the original
     * buffer unchanged when the position is null.
     *
     * @param string $htmlbuffer Full page HTML buffer.
     * @param string $buttonhtmlfragment Pre-rendered button HTML.
     * @param int|null $insertionposition Calculated insertion byte offset.
     * @return string
     */
    private static function apply_insertion(string $htmlbuffer, string $buttonhtmlfragment, ?int $insertionposition): string {
        if ($insertionposition === null) {
            return $htmlbuffer;
        }

        return substr($htmlbuffer, 0, $insertionposition)
            . $buttonhtmlfragment
            . substr($htmlbuffer, $insertionposition);
    }
}
