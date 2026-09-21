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

use html_writer;
use stdClass;

/**
 * Renders the table of contents tree, kept apart from view.php only because
 * together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait scorm_toc_tree {
    /**
     * Ported from scorm_format_toc_for_treeview(), with the player's links made to the preview.
     *
     * @param array $scoes
     * @param array $usertracks
     * @param int $toclink
     * @param string $currentorg
     * @param string|int $attempt
     * @param bool $play
     * @param stdClass|null $organizationsco
     * @param bool $children
     * @return stdClass
     */
    protected function scorm_format_toc_for_treeview(array $scoes, array $usertracks, int $toclink = TOCJSLINK,
            string $currentorg = '', $attempt = '', bool $play = false, ?stdClass $organizationsco = null,
            bool $children = false): stdClass {
        $scorm = $this->scorm;
        $result = new stdClass();
        $result->prerequisites = true;
        $result->incomplete = true;
        $result->toc = '';

        if (!$children) {
            $attemptsmade = $this->scorm_get_attempt_count();
            $result->attemptleft = $scorm->maxattempt == 0 ? 1 : $scorm->maxattempt - $attemptsmade;
        }

        if (!$children) {
            $result->toc = html_writer::start_tag('ul');

            if (!$play && !empty($organizationsco)) {
                $result->toc .= html_writer::start_tag('li').$organizationsco->title.html_writer::end_tag('li');
            }
        }

        $prevsco = '';
        if (!empty($scoes)) {
            foreach ($scoes as $sco) {

                if ($sco->isvisible === 'false') {
                    continue;
                }

                $result->toc .= html_writer::start_tag('li');
                $scoid = $sco->id;

                $score = '';

                if (!empty($sco->prereq)) {
                    if ($sco->id == $scoid) {
                        $result->prerequisites = true;
                    }

                    if (!empty($prevsco) && scorm_version_check($scorm->version, SCORM_13) && !empty($prevsco->hidecontinue)) {
                        if ($sco->scormtype == 'sco') {
                            $result->toc .= html_writer::span($sco->statusicon.'&nbsp;'.format_string($sco->title));
                        } else {
                            $result->toc .= html_writer::span('&nbsp;'.format_string($sco->title));
                        }
                    } else if ($toclink == TOCFULLURL) {
                        $url = $this->player_url($sco->url)->out(false);
                        if (!empty($sco->launch)) {
                            if ($sco->scormtype == 'sco') {
                                $result->toc .= $sco->statusicon.'&nbsp;';
                                $result->toc .= html_writer::link($url, format_string($sco->title)).$score;
                            } else {
                                $result->toc .= '&nbsp;'.html_writer::link($url, format_string($sco->title),
                                                                            ['data-scoid' => $sco->id]).$score;
                            }
                        } else {
                            if ($sco->scormtype == 'sco') {
                                $result->toc .= $sco->statusicon.'&nbsp;'.format_string($sco->title).$score;
                            } else {
                                $result->toc .= '&nbsp;'.format_string($sco->title).$score;
                            }
                        }
                    } else {
                        if (!empty($sco->launch)) {
                            if ($sco->scormtype == 'sco') {
                                $result->toc .= html_writer::tag('a', $sco->statusicon.'&nbsp;'.
                                                                    format_string($sco->title).'&nbsp;'.$score,
                                                                    ['data-scoid' => $sco->id, 'title' => $sco->url]);
                            } else {
                                $result->toc .= html_writer::tag('a', '&nbsp;'.format_string($sco->title).'&nbsp;'.$score,
                                                                    ['data-scoid' => $sco->id, 'title' => $sco->url]);
                            }
                        } else {
                            if ($sco->scormtype == 'sco') {
                                $result->toc .= html_writer::span($sco->statusicon.'&nbsp;'.format_string($sco->title));
                            } else {
                                $result->toc .= html_writer::span('&nbsp;'.format_string($sco->title));
                            }
                        }
                    }
                } else {
                    if ($play) {
                        if ($sco->scormtype == 'sco') {
                            $result->toc .= html_writer::span($sco->statusicon.'&nbsp;'.format_string($sco->title));
                        } else {
                            $result->toc .= '&nbsp;'.format_string($sco->title).html_writer::end_span();
                        }
                    } else {
                        if ($sco->scormtype == 'sco') {
                            $result->toc .= $sco->statusicon.'&nbsp;'.format_string($sco->title);
                        } else {
                            $result->toc .= '&nbsp;'.format_string($sco->title);
                        }
                    }
                }

                if (!empty($sco->children)) {
                    $result->toc .= html_writer::start_tag('ul');
                    $childresult = $this->scorm_format_toc_for_treeview($sco->children, $usertracks, $toclink, $currentorg,
                        $attempt, $play, $organizationsco, true);

                    // Is any of the children incomplete?
                    $sco->incomplete = $childresult->incomplete;
                    $result->toc .= $childresult->toc;
                    $result->toc .= html_writer::end_tag('ul');
                    $result->toc .= html_writer::end_tag('li');
                } else {
                    $result->toc .= html_writer::end_tag('li');
                }
                $prevsco = $sco;
            }
            $result->incomplete = $sco->incomplete;
        }

        if (!$children) {
            $result->toc .= html_writer::end_tag('ul');
        }

        return $result;
    }
}
