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

namespace local_coursegen\local\preview\scorm;

use moodle_url;

/**
 * The description and the links the page makes to the player and to the
 * package's own pages, kept apart from view.php only because together they
 * crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait scorm_links {
    /**
     * The description formatted as format_module_intro() formats it, with the context handed in.
     *
     * @return string
     */
    protected function format_module_intro(): string {
        $options = ['noclean' => true, 'para' => false, 'filter' => true, 'context' => $this->context, 'overflowdiv' => true];
        $intro = file_rewrite_pluginfile_urls((string) $this->scorm->intro, 'pluginfile.php', $this->context->id,
            'mod_scorm', 'intro', null);
        return trim(format_text($intro, (int) $this->scorm->introformat, $options, null));
    }

    /**
     * Where the page sent the reader into the player (mod/scorm/player.php), the preview instead.
     *
     * @param string $query The player's own query string, if any.
     * @return moodle_url
     */
    protected function player_url(string $query = ''): moodle_url {
        $url = new moodle_url($this->here);
        $url->param('player', 1);
        if ($query !== '') {
            parse_str($query, $params);
            foreach ($params as $name => $value) {
                $url->param($name, $value);
            }
        }
        return $url;
    }

    /**
     * The button that previews the package, as a link to the page it launches.
     *
     * The launch object names its page relative to the package, and the
     * payload carries the package's unpacked files in mod_scorm's content
     * area, so the page is found among them by that path. A package the answer has
     * not produced yet has no pages to open: the button is shown, because the
     * page has it, and disabled, because there is nothing behind it.
     *
     * @param mixed $launchsco The id of the object the package launches with.
     * @return string
     */
    protected function browse_link($launchsco): string {
        global $OUTPUT;
        return $OUTPUT->render_from_template('local_coursegen/preview_browse_button', [
            'label' => get_string('browse', 'scorm'),
            'url' => $this->browse_url($launchsco),
        ]);
    }

    /**
     * The url of the file the payload carries for a package's launch object,
     * when there is one.
     *
     * @param mixed $launchsco The id of the object the package launches with.
     * @return string|null
     */
    protected function browse_url($launchsco): ?string {
        $sco = false;
        if ($launchsco) {
            $sco = $this->scorm_get_sco($launchsco);
        }
        $launch = '';
        if ($sco) {
            $launch = trim((string) ($sco->launch ?? ''));
        }
        if ($launch === '' || $this->files === null) {
            return null;
        }

        $query = '';
        $path = $launch;
        if (($at = strpos($launch, '?')) !== false) {
            $query = substr($launch, $at);
            $path = substr($launch, 0, $at);
        }
        $parameters = trim((string) ($sco->parameters ?? ''));
        if ($parameters !== '') {
            $separator = '&';
            if ($query === '') {
                $separator = '?';
            }
            $query .= $separator . $parameters;
        }

        $wanted = '/' . ltrim($path, '/');
        // mod_scorm keeps the unpacked package under one item, whatever
        // the package's revision says, so the area is read whole.
        foreach ($this->files->get_area_files($this->context->id, 'mod_scorm', 'content', false, 'filepath, filename', false) as $file) {
            if ($file->get_filepath() . $file->get_filename() === $wanted && $file->get_url()) {
                return $file->get_url() . $query;
            }
        }
        return null;
    }
}
