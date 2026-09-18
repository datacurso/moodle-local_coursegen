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

use context;
use html_writer;
use local_coursegen\local\preview\json_store;
use moodle_url;
use single_select;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/scorm/lib.php');
require_once($CFG->dirroot . '/mod/scorm/locallib.php');

/**
 * A SCORM package's view page, drawn by mod_scorm's own code against the payload.
 *
 * mod/scorm/view.php prints the description, then scorm_print_launch(): the
 * organisations to choose from when the package has more than one, the table
 * of contents when the package is set to show it, and the form that enters
 * the player; then the standing of the reader's attempts. Those functions are
 * copied here under their own names, with the learning objects read from the
 * payload's scoes rather than the database, the context handed in, and the
 * links the page makes to the player made to the preview.
 *
 * Attempts are the readers' own and a template carries none, so the reader
 * is shown as someone who has not started: every object not attempted, no
 * attempt made, no grade reported, and no attempts to delete.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view {
    /** @var stdClass The scorm row. */
    protected stdClass $scorm;

    /** @var stdClass The course module. */
    protected stdClass $cm;

    /** @var context The context the package's text is formatted in. */
    protected context $context;

    /** @var json_store The package's rows. */
    protected json_store $store;

    /** @var moodle_url The preview this is drawn on. */
    protected moodle_url $here;

    /** @var stdClass Who is reading. */
    protected stdClass $user;

    /**
     * Constructor.
     *
     * @param stdClass $scorm The scorm row.
     * @param stdClass $cm The course module.
     * @param context $context
     * @param json_store $store
     * @param moodle_url $here The preview page, which every link stays on.
     * @param stdClass $user The reader.
     */
    public function __construct(stdClass $scorm, stdClass $cm, context $context, json_store $store, moodle_url $here,
            stdClass $user) {
        $this->scorm = $scorm;
        $this->cm = $cm;
        $this->context = $context;
        $this->store = $store;
        $this->here = $here;
        $this->user = $user;
        foreach (['timeopen' => 0, 'timeclose' => 0, 'popup' => 0, 'options' => '', 'intro' => ''] as $field => $default) {
            if (!isset($this->scorm->{$field})) {
                $this->scorm->{$field} = $default;
            }
        }
    }

    /**
     * The page, as mod/scorm/view.php prints it after the header.
     *
     * The page may instead send the reader straight into the player when the
     * package is set to skip its own page; a preview is the page, so it is
     * printed.
     *
     * @return string
     */
    public function page(): string {
        global $OUTPUT;
        $scorm = $this->scorm;
        $output = '';

        $attemptstatus = '';
        if ($scorm->displayattemptstatus == SCORM_DISPLAY_ATTEMPTSTATUS_ALL ||
                 $scorm->displayattemptstatus == SCORM_DISPLAY_ATTEMPTSTATUS_ENTRY) {
            $attemptstatus = $this->scorm_get_attempt_status();
        }
        $output .= $OUTPUT->box($this->format_module_intro(), '', 'intro');

        // Check if SCORM available. No need to display warnings because activity dates are displayed at the top of the page.
        list($available, $warnings) = scorm_get_availability_status($scorm);
        if ($available) {
            $output .= $this->scorm_print_launch();
        }
        $output .= $OUTPUT->box($attemptstatus);

        if (!empty(get_config('scorm', 'forcejavascript'))) {
            $message = $OUTPUT->box(get_string("forcejavascriptmessage", "scorm"), "forcejavascriptmessage");
            $output .= html_writer::tag('noscript', $message);
        }
        return $output;
    }

    /**
     * Ported from mod/scorm/locallib.php scorm_print_launch(), returning rather than echoing.
     *
     * @return string
     */
    protected function scorm_print_launch(): string {
        global $CFG, $OUTPUT;
        $scorm = $this->scorm;
        $output = '';

        $organization = optional_param('organization', '', PARAM_INT);

        if ($scorm->displaycoursestructure == 1) {
            $output .= $OUTPUT->box_start('generalbox boxaligncenter toc', 'toc');
            $output .= html_writer::div(get_string('contents', 'scorm'), 'structurehead');
        }
        if (empty($organization)) {
            $organization = $scorm->launch;
        }
        $orgs = [];
        foreach ($this->store->get_records('scorm_scoes', ['scorm' => $scorm->id], 'sortorder, id') as $org) {
            if ((string) ($org->launch ?? '') === '' && (string) ($org->organization ?? '') !== '') {
                $orgs[$org->id] = $org->title;
            }
        }
        if ($orgs) {
            if (count($orgs) > 1) {
                $select = new single_select(new moodle_url($this->here), 'organization', $orgs, $organization, null);
                $select->label = get_string('organizations', 'scorm');
                $select->class = 'scorm-center';
                $output .= $OUTPUT->render($select);
            }
        }
        $orgidentifier = '';
        if ($sco = $this->scorm_get_sco($organization, SCO_ONLY)) {
            if (($sco->organization == '') && ($sco->launch == '')) {
                $orgidentifier = $sco->identifier;
            } else {
                $orgidentifier = $sco->organization;
            }
        }

        $scorm->version = strtolower(clean_param($scorm->version, PARAM_SAFEDIR));   // Just to be safe.
        if (!file_exists($CFG->dirroot.'/mod/scorm/datamodels/'.$scorm->version.'lib.php')) {
            $scorm->version = 'scorm_12';
        }
        require_once($CFG->dirroot.'/mod/scorm/datamodels/'.$scorm->version.'lib.php');

        $result = $this->scorm_get_toc(TOCFULLURL, $orgidentifier);
        $incomplete = $result->incomplete;
        // Get latest incomplete sco to launch first if force new attempt isn't set to always.
        if (!empty($result->sco->id) && $scorm->forcenewattempt != SCORM_FORCEATTEMPT_ALWAYS) {
            $launchsco = $result->sco->id;
        } else {
            // Use launch defined by SCORM package.
            $launchsco = $scorm->launch;
        }

        // Do we want the TOC to be displayed?
        if ($scorm->displaycoursestructure == 1) {
            $output .= $result->toc;
            $output .= $OUTPUT->box_end();
        }

        // Is this the first attempt ?
        $attemptcount = $this->scorm_get_attempt_count();

        // Do not give the player launch FORM if the SCORM object is locked after the final attempt.
        if ($scorm->lastattemptlock == 0 || $result->attemptleft > 0) {
                $output .= html_writer::start_div('scorm-center');
                $output .= html_writer::start_tag('form', ['id' => 'scormviewform',
                                                            'method' => 'post',
                                                            'action' => $this->player_url()->out(false)]);
            if ($scorm->hidebrowse == 0) {
                $output .= html_writer::tag('button', get_string('browse', 'scorm'),
                        ['class' => 'btn btn-secondary me-1', 'name' => 'mode',
                            'type' => 'submit', 'id' => 'b', 'value' => 'browse'])
                    . html_writer::end_tag('button');
            } else {
                $output .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'mode', 'value' => 'normal']);
            }
            $output .= html_writer::tag('button', get_string('enter', 'scorm'),
                    ['class' => 'btn btn-primary mx-1', 'name' => 'mode',
                        'type' => 'submit', 'id' => 'n', 'value' => 'normal'])
                 . html_writer::end_tag('button');

            if (!empty($scorm->forcenewattempt)) {
                if ($scorm->forcenewattempt == SCORM_FORCEATTEMPT_ALWAYS ||
                        ($scorm->forcenewattempt == SCORM_FORCEATTEMPT_ONCOMPLETE && $incomplete === false)) {
                    $output .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'newattempt', 'value' => 'on']);
                }
            } else if (!empty($attemptcount) && ($incomplete === false) &&
                    (($result->attemptleft > 0) || ($scorm->maxattempt == 0))) {
                $output .= html_writer::start_div('pt-1');
                $output .= html_writer::checkbox('newattempt', 'on', false, '', ['id' => 'a']);
                $output .= html_writer::label(get_string('newattempt', 'scorm'), 'a', true, ['class' => 'ps-1']);
                $output .= html_writer::end_div();
            }
            if (!empty($scorm->popup)) {
                $output .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'display', 'value' => 'popup']);
            }

            $output .= html_writer::empty_tag('br');
            $output .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'scoid', 'value' => $launchsco]);
            $output .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cm', 'value' => $this->cm->id]);
            $output .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'currentorg', 'value' => $orgidentifier]);
            $output .= html_writer::end_tag('form');
            $output .= html_writer::end_div();
        }
        return $output;
    }

    /**
     * Ported from scorm_get_attempt_status(), returning what it returns.
     *
     * @return string
     */
    protected function scorm_get_attempt_status(): string {
        global $OUTPUT;
        $scorm = $this->scorm;

        $attempts = $this->scorm_get_attempt_count(true);
        if (empty($attempts)) {
            $attemptcount = 0;
        } else {
            $attemptcount = count($attempts);
        }

        $result = html_writer::start_tag('p').get_string('noattemptsallowed', 'scorm').': ';
        if ($scorm->maxattempt > 0) {
            $result .= $scorm->maxattempt . html_writer::empty_tag('br');
        } else {
            $result .= get_string('unlimited').html_writer::empty_tag('br');
        }
        $result .= get_string('noattemptsmade', 'scorm').': ' . $attemptcount . html_writer::empty_tag('br');

        if ($scorm->maxattempt == 1) {
            switch ($scorm->grademethod) {
                case GRADEHIGHEST:
                    $grademethod = get_string('gradehighest', 'scorm');
                    break;
                case GRADEAVERAGE:
                    $grademethod = get_string('gradeaverage', 'scorm');
                    break;
                case GRADESUM:
                    $grademethod = get_string('gradesum', 'scorm');
                    break;
                case GRADESCOES:
                    $grademethod = get_string('gradescoes', 'scorm');
                    break;
            }
        } else {
            switch ($scorm->whatgrade) {
                case HIGHESTATTEMPT:
                    $grademethod = get_string('highestattempt', 'scorm');
                    break;
                case AVERAGEATTEMPT:
                    $grademethod = get_string('averageattempt', 'scorm');
                    break;
                case FIRSTATTEMPT:
                    $grademethod = get_string('firstattempt', 'scorm');
                    break;
                case LASTATTEMPT:
                    $grademethod = get_string('lastattempt', 'scorm');
                    break;
            }
        }

        // The grade of each attempt would follow here; there are no attempts.
        $calculatedgrade = $this->scorm_grade_user();
        if ($scorm->grademethod !== GRADESCOES && !empty($scorm->maxgrade)) {
            $calculatedgrade = $calculatedgrade / $scorm->maxgrade;
            $calculatedgrade = number_format($calculatedgrade * 100, 0) .'%';
        }
        $result .= get_string('grademethod', 'scorm'). ': ' . ($grademethod ?? '');
        if (empty($attempts)) {
            $result .= html_writer::empty_tag('br').get_string('gradereported', 'scorm').
                        ': '.get_string('none').html_writer::empty_tag('br');
        } else {
            $result .= html_writer::empty_tag('br').get_string('gradereported', 'scorm').
                        ': '.$calculatedgrade.html_writer::empty_tag('br');
        }
        $result .= html_writer::end_tag('p');
        if ($attemptcount >= $scorm->maxattempt && $scorm->maxattempt > 0) {
            $result .= html_writer::tag('p', get_string('exceededmaxattempts', 'scorm'), ['class' => 'exceededmaxattempts']);
        }
        // The button that deletes the reader's attempts is offered only to a
        // reader who has some; none are carried, so it is never offered.
        return $result;
    }

    /**
     * scorm_get_attempt_count(): the reader's attempts, which the payload does not carry.
     *
     * @param bool $returnobjects
     * @return array|int
     */
    protected function scorm_get_attempt_count(bool $returnobjects = false) {
        $attempts = $this->store->get_records('scorm_attempt',
            ['userid' => $this->user->id, 'scormid' => $this->scorm->id], 'attempt');
        if ($returnobjects) {
            $found = [];
            foreach ($attempts as $attempt) {
                $found[$attempt->attempt] = (object) ['attemptnumber' => $attempt->attempt];
            }
            return $found;
        }
        return count($attempts);
    }

    /**
     * scorm_grade_user(): the grade over attempts the payload does not carry.
     *
     * @return int
     */
    protected function scorm_grade_user(): int {
        return 0;
    }

    /**
     * scorm_get_last_attempt(): one, as it answers for a reader with no attempts.
     *
     * @return int
     */
    protected function scorm_get_last_attempt(): int {
        $last = 0;
        foreach ($this->store->get_records('scorm_attempt', ['userid' => $this->user->id, 'scormid' => $this->scorm->id]) as $a) {
            $last = max($last, (int) $a->attempt);
        }
        return $last ?: 1;
    }

    /**
     * Ported from scorm_get_sco(), over the store.
     *
     * @param int|string $id
     * @param int $what
     * @return stdClass|false
     */
    protected function scorm_get_sco($id, $what = SCO_ALL) {
        if ($sco = $this->store->get_record('scorm_scoes', ['id' => $id])) {
            $sco = ($what == SCO_DATA) ? new stdClass() : $sco;
            if (($what != SCO_ONLY) && ($scodatas = $this->store->get_records('scorm_scoes_data', ['scoid' => $id]))) {
                foreach ($scodatas as $scodata) {
                    $sco->{$scodata->name} = $scodata->value;
                }
            } else if (($what != SCO_ONLY) && (!($scodatas = $this->store->get_records('scorm_scoes_data', ['scoid' => $id])))) {
                $sco->parameters = '';
            }
            return $sco;
        } else {
            return false;
        }
    }

    /**
     * Ported from scorm_get_scoes(), over the store.
     *
     * @param string|false $organisation
     * @return array|false
     */
    protected function scorm_get_scoes($organisation = false) {
        $queryarray = ['scorm' => $this->scorm->id];
        if (!empty($organisation)) {
            $queryarray['organization'] = $organisation;
        }
        if ($scoes = $this->store->get_records('scorm_scoes', $queryarray, 'sortorder, id')) {
            // Drop keys so that it is a simple array as expected.
            $scoes = array_values($scoes);
            foreach ($scoes as $sco) {
                if ($scodatas = $this->store->get_records('scorm_scoes_data', ['scoid' => $sco->id])) {
                    foreach ($scodatas as $scodata) {
                        $sco->{$scodata->name} = $scodata->value;
                    }
                }
            }
            return $scoes;
        } else {
            return false;
        }
    }

    /**
     * Ported from scorm_get_toc(), for the view page's call: full links, no player, no header.
     *
     * @param int $toclink
     * @param string $currentorg
     * @return stdClass
     */
    protected function scorm_get_toc(int $toclink = TOCJSLINK, string $currentorg = ''): stdClass {
        $scorm = $this->scorm;
        $attempt = $this->scorm_get_last_attempt();
        $result = new stdClass();
        $organizationsco = null;

        if (!empty($currentorg)) {
            $organizationsco = $this->store->get_record('scorm_scoes', ['scorm' => $scorm->id, 'identifier' => $currentorg]);
        }

        $scoes = $this->scorm_get_toc_object($currentorg, '', 'normal', $attempt, false, $organizationsco);

        $treeview = $this->scorm_format_toc_for_treeview($scoes['scoes'][0]->children ?? [], $scoes['usertracks'],
            $toclink, $currentorg, $attempt, false, $organizationsco, false);

        $result->toc = $treeview->toc;

        $scoid = '';
        if (!empty($scoes['scoid'])) {
            $scoid = $scoes['scoid'];
        }

        if (empty($scoid)) {
            // If this is a normal package with an org sco and child scos get the first child.
            if (!empty($scoes['scoes'][0]->children)) {
                $result->sco = $scoes['scoes'][0]->children[0];
            } else { // This package only has one sco - it may be a simple external AICC package.
                $result->sco = $scoes['scoes'][0] ?? new stdClass();
            }
        } else {
            $result->sco = $this->scorm_get_sco($scoid);
        }

        $result->prerequisites = $treeview->prerequisites;
        $result->incomplete = $treeview->incomplete;
        $result->attemptleft = $treeview->attemptleft;

        return $result;
    }

    /**
     * Ported from scorm_get_toc_object(): every object as not attempted, because no tracks are carried.
     *
     * @param string $currentorg
     * @param string $scoid
     * @param string $mode
     * @param string|int $attempt
     * @param bool $play
     * @param stdClass|null $organizationsco
     * @return array
     */
    protected function scorm_get_toc_object(string $currentorg = '', $scoid = '', string $mode = 'normal', $attempt = '',
            bool $play = false, ?stdClass $organizationsco = null): array {
        global $OUTPUT;
        $scorm = $this->scorm;

        // Always pass the mode even if empty as that is what is done elsewhere and the urls have to match.
        $modestr = '&mode=';
        if ($mode != 'normal') {
            $modestr = '&mode='.$mode;
        }

        $result = [];
        $incomplete = false;

        if (!empty($organizationsco)) {
            $result[0] = $organizationsco;
            $result[0]->isvisible = 'true';
            $result[0]->statusicon = '';
            $result[0]->url = '';
        }

        $usertracks = [];
        if ($scoes = $this->scorm_get_scoes($currentorg)) {
            // The reader's tracks would be read here for each learning object; none are carried.
            foreach ($scoes as $sco) {
                if (!isset($sco->isvisible)) {
                    $sco->isvisible = 'true';
                }

                if (empty($sco->title)) {
                    $sco->title = $sco->identifier;
                }

                if (scorm_version_check($scorm->version, SCORM_13)) {
                    $sco->prereq = true;
                } else {
                    $sco->prereq = empty($sco->prerequisites) || scorm_eval_prerequisites($sco->prerequisites, $usertracks);
                }

                $statusicon = '';
                if ($sco->isvisible === 'true') {
                    if (!empty($sco->launch)) {
                        // Set first sco to launch if in browse/review mode.
                        if (empty($scoid) && ($mode != 'normal')) {
                            $scoid = $sco->id;
                        }
                        if (empty($scoid)) {
                            $scoid = $sco->id;
                        }
                        $incomplete = true;
                        if ($sco->scormtype == 'sco') {
                            $statusicon = $OUTPUT->pix_icon('notattempted', get_string('notattempted', 'scorm'), 'scorm');
                        } else {
                            $statusicon = $OUTPUT->pix_icon('asset', get_string('asset', 'scorm'), 'scorm');
                        }
                    }
                }

                if (empty($statusicon)) {
                    $sco->statusicon = $OUTPUT->pix_icon('notattempted', get_string('notattempted', 'scorm'), 'scorm');
                } else {
                    $sco->statusicon = $statusicon;
                }

                $sco->url = 'a='.$scorm->id.'&scoid='.$sco->id.'&currentorg='.$currentorg.$modestr.'&attempt='.$attempt;
                $sco->incomplete = $incomplete;

                if (!in_array($sco->id, array_keys($result))) {
                    $result[$sco->id] = $sco;
                }
            }
        }

        // Get the parent scoes!
        $result = $result ? scorm_get_toc_get_parent_child($result, $currentorg) : [];

        // Be safe, prevent warnings from showing up while returning array.
        if (!isset($scoid)) {
            $scoid = '';
        }

        return ['scoes' => $result, 'usertracks' => $usertracks, 'scoid' => $scoid];
    }

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
}
