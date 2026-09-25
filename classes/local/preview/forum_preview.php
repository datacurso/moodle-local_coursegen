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

use local_coursegen\local\preview\forum\view;

/**
 * A forum, drawn by mod_forum's own view code run against the payload.
 *
 * Discussions are the readers', so a forum previews in the state it is in
 * before anyone posts. A single simple discussion's opening post is written
 * with the forum, and is not in the payload, so that type previews the same
 * way.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forum_preview extends ported_preview {
    /** @var view|null */
    protected ?view $view = null;

    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'forum';
    }

    /**
     * A draft replaces the description and the type.
     *
     * @param json_store $store
     */
    protected function overlay(json_store $store): void {
        $rows = $store->get_records('forum');
        if (!$rows) {
            return;
        }
        $row = reset($rows);
        $intro = $this->parameters['introeditor'] ?? null;
        if (is_array($intro)) {
            $intro = $intro['text'] ?? null;
        }
        if (is_string($intro) && trim($intro) !== '') {
            $store->set('forum', $row->id, 'intro', $intro);
        }
        if (!empty($this->parameters['type'])) {
            $store->set('forum', $row->id, 'type', (string) $this->parameters['type']);
        }
    }

    /**
     * The module's view, built once.
     *
     * @return view|null
     */
    protected function view(): ?view {
        if ($this->view !== null) {
            return $this->view;
        }
        $forum = $this->instance();
        if ($forum === null) {
            return null;
        }
        $course = $this->course();
        $cmid = (int) ($this->source['cmid'] ?? 0);
        $modinfo = get_fast_modinfo($course);
        if (!$cmid || !isset($modinfo->cms[$cmid])) {
            return null;
        }
        $this->view = new view($forum, $modinfo->get_cm($cmid), $course, $this->context(), $this->url_to());
        return $this->view;
    }

    /**
     * The forum page, as mod/forum/view.php draws it.
     *
     * @return string
     */
    public function render(): string {
        $view = $this->view();
        if ($view === null) {
            return $this->nothing_yet();
        }
        return $view->page();
    }

    /**
     * mod/forum/view.php: a single simple discussion shows its post, not its description.
     *
     * @return string
     */
    public function header_description(): string {
        $forum = $this->instance();
        if ($forum === null || (string) ($forum->type ?? '') === 'single') {
            return '';
        }
        return parent::header_description();
    }
}
