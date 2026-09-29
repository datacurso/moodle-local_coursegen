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

namespace local_coursegen\mod_export;

/**
 * Class scorm_export
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scorm_export extends base_export {
    /** @var string[] Every mod_scorm instance setting worth reproducing on the generated activity. */
    private const SCORM_SETTINGS_COLUMNS = [
        'popup', 'width', 'height',
        'skipview', 'hidebrowse', 'hidetoc', 'nav', 'navpositionleft', 'navpositiontop',
        'displayattemptstatus', 'displaycoursestructure',
        'timeopen', 'timeclose',
        'grademethod', 'maxgrade', 'maxattempt', 'whatgrade',
        'forcenewattempt', 'lastattemptlock', 'forcecompleted',
        'auto', 'autocommit', 'masteryoverride',
        'completionstatusrequired', 'completionscorerequired', 'completionstatusallscos',
        'updatefreq',
    ];

    /** @var string The literal our own generator's index.html always carries. */
    private const SCORM_OURS_MARKER = 'const CONFIG';

    /**
     * A SCORM's raw description, every instance setting and its mold package.
     *
     * A SCORM written by our own AI service is always the same five zip
     * entries - imsmanifest.xml, index.html and the three skeleton assets -
     * with ALL of its content injected as JSON inside index.html. The service
     * therefore rebuilds the whole package from its own skeleton once the
     * markers are filled, and the plugin needs no rebuild code of its own: the
     * generated package arrives through the ordinary package download path.
     * So only the ONE text entry travels, under moldscorm.
     *
     * Three traps of mod_scorm's schema shape this branch:
     *
     * - gradepass is NOT a scorm column. As in mod_quiz it lives in
     *   grade_items, so it travels through the grades API.
     * - options is a PACKED string that cannot round trip, the same trap as
     *   the quiz review bitmasks: scorm_add_instance() runs its payload
     *   through scorm_option2text(), which REBUILDS that column out of the six
     *   window checkboxes and would overwrite anything sent in it. It
     *   therefore travels decoded into those six fields (see
     *   popup_options()), and never as the packed string.
     * - version, reference, sha1hash, md5hash, revision and launch are DERIVED
     *   by the package parser from the uploaded file. They describe the mold's
     *   own package, never the one the service is about to write, so they are
     *   left out along with the usual identity columns.
     *
     * scormtype travels only when it is local, the one kind a generated
     * activity can reproduce: an external or AICC URL mold points at somebody
     * else's hosting and owns no package to be a mold of.
     *
     * @return array
     */
    public function parameters(): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/scorm/lib.php');

        $scorm = $DB->get_record('scorm', ['id' => $this->cm->instance]);
        if (!$scorm) {
            return $this->minimal_parameters();
        }

        $parameters = array_merge(
            $this->settings_columns($scorm),
            $this->popup_options($scorm),
            [
                'name' => $this->cm->name,
                'section' => (int) $this->cm->sectionnum,
                'intro' => $scorm->intro ?? '',
                'gradepass' => $this->grade_pass(),
                // Always reported, null included: an absent key and a package
                // this activity does not have mean different things to the
                // service, and only one of them is true here.
                'moldscorm' => $this->mold_package($scorm),
            ]
        );

        if ((string) ($scorm->scormtype ?? '') === SCORM_TYPE_LOCAL) {
            $parameters['scormtype'] = SCORM_TYPE_LOCAL;
        }

        return $parameters;
    }

    /**
     * The mold's popup window chrome, expanded into the six form fields.
     *
     * mod_scorm stores that chrome PACKED into the options column, as a
     * "scrollbars=1,directories=0,..." string, but nothing ever reads it back:
     * scorm_option2text() recomputes the column from six individual
     * properties, so an activity created from a payload carrying only options
     * would silently come out with every chrome setting off. The packed string
     * is therefore parsed here and travels as the six fields the scorm
     * mod_form posts (its winoptgrp checkbox group).
     *
     * A framed activity - anything with popup != 1 - stores an EMPTY options
     * column, so all six travel as 0. That is exactly what scorm_option2text()
     * substitutes for a checkbox the form did not post, and a complete payload
     * beats a partial one: an absent field would leave the generated activity
     * on the site default rather than on the mold's own chrome.
     *
     * The key list comes from scorm_get_popup_options_array(), the same source
     * the mod_form and scorm_option2text() read it from, so a chrome option
     * added to mod_scorm travels without a change here.
     *
     * @param \stdClass $scorm The scorm row.
     * @return array<string, int>
     */
    private function popup_options($scorm): array {
        global $CFG;

        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        $packed = [];
        foreach (explode(',', (string) ($scorm->options ?? '')) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $packed[trim($parts[0])] = (int) $parts[1];
            }
        }

        $options = [];
        foreach (array_keys(scorm_get_popup_options_array()) as $name) {
            $options[$name] = (int) ($packed[$name] ?? 0);
        }

        return $options;
    }

    /**
     * The mod_scorm settings worth reproducing on the generated activity.
     *
     * options is deliberately absent: it cannot round trip, and the six fields
     * popup_options() expands it into replace it.
     *
     * Identity columns (id, course, name, timemodified, introformat) and the
     * parser-derived ones are left out on purpose - they describe THIS
     * activity and THIS package, never the new ones.
     *
     * @param \stdClass $scorm
     * @return array
     */
    private function settings_columns($scorm): array {
        return $this->whitelisted_settings($scorm, self::SCORM_SETTINGS_COLUMNS);
    }

    /**
     * The one text entry of the mold's own package, plus its identity.
     *
     * index.html travels RAW: it is the marker-bearing string the service
     * fills in, and the JSON it carries (CONFIG and QUESTIONS) is written in a
     * format the service owns, so parsing it here would only duplicate that
     * knowledge on the wrong side. The other four entries never travel: they
     * are the fixed skeleton the service rebuilds the package from.
     *
     * ours says whether the package came from our own generator, which is what
     * decides whether the service can rebuild it at all. A package somebody
     * else published (Storyline, iSpring, ...) is reported WITHOUT its text
     * rather than dropped or thrown over: the service decides what to do with
     * a mold it cannot reproduce, and the rest of the template export survives.
     *
     * A null mold means there is no local package to speak of: an external or
     * AICC URL activity, a deleted file, or a zip that cannot be opened.
     *
     * @param \stdClass $scorm The scorm row.
     * @return array|null
     */
    private function mold_package($scorm): ?array {
        if ((string) ($scorm->scormtype ?? '') !== SCORM_TYPE_LOCAL) {
            return null;
        }

        $file = $this->mold_package_file('mod_scorm');
        if ($file === null) {
            return null;
        }

        $entries = $this->package_entries($file, ['index.html']);
        if ($entries === null) {
            return null;
        }

        $indexhtml = $entries['index.html'];
        $ours = is_string($indexhtml) && strpos($indexhtml, self::SCORM_OURS_MARKER) !== false;

        return [
            'filename' => $file->get_filename(),
            'indexhtml' => $ours ? $indexhtml : '',
            'ours' => $ours,
        ];
    }
}
