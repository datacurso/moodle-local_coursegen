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
 * Class wiki_export
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wiki_export extends base_export {
    /**
     * A Wiki's raw description, its four type settings and its pages.
     *
     * A wiki that nobody has opened yet owns no page at all: wiki_add_instance
     * creates neither the subwiki nor the first page, they appear on the first
     * view. Such a mold still travels, simply without mod_settings.
     *
     * @return array
     */
    public function parameters(): array {
        global $DB;

        $wiki = $DB->get_record('wiki', ['id' => $this->cm->instance]);
        if (!$wiki) {
            return $this->minimal_parameters();
        }

        $parameters = [
            'name' => $this->cm->name,
            'section' => (int) $this->cm->sectionnum,
            'intro' => $wiki->intro ?? '',
            'wikimode' => $wiki->wikimode ?? 'collaborative',
            'defaultformat' => $wiki->defaultformat ?? 'html',
            'forceformat' => (int) ($wiki->forceformat ?? 0),
            'firstpagetitle' => $wiki->firstpagetitle ?? '',
        ];

        $pages = $this->pages((int) $wiki->id, (string) ($wiki->firstpagetitle ?? ''));
        if ($pages) {
            $parameters['mod_settings'] = ['pages' => $pages];
        }

        return $parameters;
    }

    /**
     * Every page of one wiki, the first page ahead of the rest, as authored.
     *
     * Three traps of mod_wiki's schema shape this query:
     *
     * - Pages hang off a subwiki, never off the wiki itself. A mold is authored
     *   as a single collaborative wiki, which owns exactly one subwiki
     *   (groupid 0, userid 0), so the wiki's FIRST subwiki is the one carrying
     *   the authored pages.
     * - The authored text lives in wiki_versions.content of the CURRENT (highest)
     *   version. wiki_pages.cachedcontent is the parsed render that
     *   wiki_refresh_cachedcontent stores, so it would deliver markers already
     *   chewed by the wiki parser.
     * - There is no "is first" flag and no ordering column: the first page is
     *   the one whose title matches wiki.firstpagetitle (as wiki_get_first_page
     *   matches it), and id is the only stable sequence for the others.
     *
     * @param int $wikiid
     * @param string $firstpagetitle
     * @return array
     */
    private function pages(int $wikiid, string $firstpagetitle): array {
        global $DB;

        $sql = 'SELECT p.id, p.title, v.content
                  FROM {wiki_pages} p
                  JOIN {wiki_subwikis} s ON s.id = p.subwikiid
             LEFT JOIN {wiki_versions} v ON v.pageid = p.id
                       AND v.version = (SELECT MAX(v2.version)
                                          FROM {wiki_versions} v2
                                         WHERE v2.pageid = p.id)
                 WHERE s.wikiid = :wikiid
                   AND s.id = (SELECT MIN(s2.id)
                                 FROM {wiki_subwikis} s2
                                WHERE s2.wikiid = :subwikiid)
              ORDER BY p.id ASC';
        $records = $DB->get_records_sql($sql, ['wikiid' => $wikiid, 'subwikiid' => $wikiid]);

        $first = [];
        $rest = [];
        foreach ($records as $record) {
            $isfirst = $firstpagetitle !== '' && (string) $record->title === $firstpagetitle;
            $page = [
                'title' => $record->title,
                'content' => $record->content ?? '',
                'firstpage' => $isfirst,
            ];
            if ($isfirst) {
                $first[] = $page;
            } else {
                $rest[] = $page;
            }
        }

        return array_merge($first, $rest);
    }
}
