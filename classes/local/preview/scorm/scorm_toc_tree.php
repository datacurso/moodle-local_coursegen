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
        global $OUTPUT;
        $scorm = $this->scorm;
        $result = new stdClass();
        $result->prerequisites = true;
        $result->incomplete = true;
        $result->nodes = [];
        $result->toc = '';

        if (!$children) {
            $attemptsmade = $this->scorm_get_attempt_count();
            $result->attemptleft = 1;
            if ($scorm->maxattempt != 0) {
                $result->attemptleft = $scorm->maxattempt - $attemptsmade;
            }
        }

        $prevsco = '';
        if (!empty($scoes)) {
            foreach ($scoes as $sco) {
                if ($sco->isvisible === 'false') {
                    continue;
                }

                // $result->prerequisites already defaults to true, and the
                // ported code's own check for whether it should be set here
                // compared a scoid to itself - always true, so always a
                // no-op. Nothing sets it to false anywhere in this function.
                $node = $this->scorm_toc_node($sco, $prevsco, $toclink, $play);

                if (!empty($sco->children)) {
                    $childresult = $this->scorm_format_toc_for_treeview($sco->children, $usertracks, $toclink, $currentorg,
                        $attempt, $play, $organizationsco, true);
                    // Is any of the children incomplete?
                    $sco->incomplete = $childresult->incomplete;
                    $node['haschildren'] = !empty($childresult->nodes);
                    $node['children'] = $childresult->nodes;
                } else {
                    $node['haschildren'] = false;
                    $node['children'] = [];
                }

                $result->nodes[] = $node;
                $prevsco = $sco;
            }
            $result->incomplete = $sco->incomplete;
        }

        if ($children) {
            return $result;
        }

        $orgtitle = '';
        if (!$play && !empty($organizationsco)) {
            $orgtitle = $organizationsco->title;
        }
        $result->toc = $OUTPUT->render_from_template('local_coursegen/preview_scorm_toc', [
            'orgtitle' => $orgtitle,
            'nodes' => $result->nodes,
        ]);
        return $result;
    }

    /**
     * One learning object's own row, ready for preview_scorm_toc_node.mustache.
     *
     * @param stdClass $sco
     * @param stdClass|string $prevsco
     * @param int $toclink
     * @param bool $play
     * @return array
     */
    protected function scorm_toc_node(stdClass $sco, $prevsco, int $toclink, bool $play): array {
        $label = $sco->statusicon . '&nbsp;' . format_string($sco->title);
        if ($sco->scormtype !== 'sco') {
            $label = '&nbsp;' . format_string($sco->title);
        }

        $node = [
            'label' => $label,
            'reallink' => false,
            'hooklink' => false,
            'spanwrap' => false,
            'bare' => false,
            'url' => '',
            'scoid' => $sco->id,
            'title' => $sco->url,
        ];

        $mode = $this->scorm_toc_node_mode($sco, $prevsco, $toclink, $play);
        $node[$mode] = true;
        if ($mode === 'reallink') {
            $node['url'] = $this->player_url($sco->url)->out(false);
        }
        return $node;
    }

    /**
     * Which of the four forms a learning object's row takes, mapped from
     * the same conditions scorm_format_toc_for_treeview() branched on.
     *
     * @param stdClass $sco
     * @param stdClass|string $prevsco
     * @param int $toclink
     * @param bool $play
     * @return string One of reallink/hooklink/spanwrap/bare.
     */
    protected function scorm_toc_node_mode(stdClass $sco, $prevsco, int $toclink, bool $play): string {
        if (empty($sco->prereq)) {
            if ($play) {
                return 'spanwrap';
            }
            return 'bare';
        }

        $hideleadingcontent = !empty($prevsco) && scorm_version_check($this->scorm->version, SCORM_13)
            && !empty($prevsco->hidecontinue);
        if ($hideleadingcontent) {
            return 'spanwrap';
        }

        if (empty($sco->launch)) {
            if ($toclink == TOCFULLURL) {
                return 'bare';
            }
            return 'spanwrap';
        }

        if ($toclink == TOCFULLURL) {
            return 'reallink';
        }
        return 'hooklink';
    }
}
