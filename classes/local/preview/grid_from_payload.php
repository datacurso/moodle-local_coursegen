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

namespace local_coursegen\local\preview;

use moodle_url;

/**
 * The same course, laid out the way a grid lays a course out.
 *
 * A course looks like its format makes it look, and for format_grid that is
 * most of what a teacher recognises about it: the sections are tiles with
 * their own pictures, and opening one shows what is in it. A preview that
 * dropped that would be a preview of a different course.
 *
 * The format's own template draws it, as with every other part of this page.
 * What is built here is the context that template reads, out of the payload:
 * the format's settings travelled with the course, and each section's picture
 * travelled as the address it can be read from.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grid_from_payload {
    /**
     * Whether this course is laid out by a format this can draw.
     *
     * @param array $payload
     * @return bool
     */
    public static function applies(array $payload): bool {
        global $CFG;

        return (($payload['course_configuration'] ?? [])['format'] ?? '') === 'grid'
            && file_exists($CFG->dirroot . '/course/format/grid/templates/local/content.mustache');
    }

    /**
     * The grid's own context, on top of the sections the course already has.
     *
     * @param array $content What core's own content template would be given.
     * @param array $payload
     * @param int $sessionid
     * @return array
     */
    public static function content(array $content, array $payload, int $sessionid): array {
        $settings = ($payload['course_configuration'] ?? [])['format_options'] ?? [];

        $tiles = [];
        $numbers = [];
        $popups = [];
        foreach ($content['sections'] as $section) {
            $info = self::section_info($payload, (int) $section['num']);
            $options = $info['format_options'] ?? [];

            $numbers[] = (int) $section['num'];
            $tiles[] = [
                'number' => (int) $section['num'],
                'sectionname' => $section['sectionname'],
                'sectionurl' => (new moodle_url('/local/coursegen/course_preview.php', [
                    'sessionid' => $sessionid,
                    'section' => (int) $section['num'],
                ]))->out(false),
                'sectionuservisible' => true,
                'iscurrent' => false,
                'sectionbreak' => !empty($options['sectionbreak']),
                'sectionbreakheading' => (string) ($options['sectionbreakheading'] ?? ''),
                // A section with no picture of its own is drawn with the one
                // the format makes up for it, which is why both are offered
                // and only one is ever set.
                'imageuri' => $info['image'] ?? false,
                'imagealttext' => (string) ($options['sectionimagealttext'] ?? ''),
                'generatedimageuri' => empty($info['image']) ? self::generated_image($section['sectionname']) : false,
                'sectioncompletionmarkup' => '',
            ];

            $popups[] = $section;
        }

        $showsinpopup = ((int) ($settings['popup'] ?? 0)) === 2;

        // A section shown as a tile is not also shown in the list above it:
        // the format draws both from what it is given, so giving it the same
        // sections twice is how the course came out drawn twice.
        $content['sections'] = [];
        $content['hassections'] = false;

        return $content + [
            'hasgridsections' => !empty($tiles),
            'gridsections' => $tiles,
            'gridsectionnumbers' => implode(',', $numbers),
            'gridjustification' => (string) ($settings['gridjustification'] ?? 'space-between'),
            'imageresizemethodcrop' => ((int) ($settings['imageresizemethod'] ?? 0)) === 2,
            'sectiontitleingridbox' => ((int) ($settings['sectiontitleingridbox'] ?? 0)) === 2,
            'sectionbadgeingridbox' => ((int) ($settings['sectionbadgeingridbox'] ?? 0)) === 2,
            'showcompletion' => false,
            // A grid can open a section in a dialog instead of on its own page,
            // and the dialog holds the same sections this page already built,
            // so what is in them is what the run is going to produce.
            'popup' => $showsinpopup,
            'popupsections' => $showsinpopup ? $popups : [],
            'coursestyles' => self::styles($settings),
        ];
    }

    /**
     * The tile size and shape the course was set up with.
     *
     * @param array $settings
     * @return array
     */
    private static function styles(array $settings): array {
        $width = (int) ($settings['imagecontainerwidth'] ?? 210);
        $ratio = (string) ($settings['imagecontainerratio'] ?? '3-2');
        [$across, $down] = array_pad(explode('-', $ratio), 2, 1);

        return [
            'imagecontainerwidth' => $width,
            'imagecontainerheight' => (int) round($width * ((int) $down / max(1, (int) $across))),
        ];
    }

    /**
     * The picture a format draws for a section that has none of its own.
     *
     * @param string $name
     * @return string
     */
    private static function generated_image(string $name): string {
        global $OUTPUT;
        $initial = \core_text::strtoupper(\core_text::substr(trim($name), 0, 1));
        $svg = $OUTPUT->render_from_template('local_coursegen/preview_initial_svg', ['initial' => $initial]);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * One section as the payload describes it.
     *
     * @param array $payload
     * @param int $number
     * @return array
     */
    private static function section_info(array $payload, int $number): array {
        foreach (($payload['sections_info'] ?? []) as $info) {
            if ((int) ($info['section'] ?? -1) === $number) {
                return $info;
            }
        }
        return [];
    }
}
