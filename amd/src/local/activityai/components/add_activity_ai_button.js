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

/**
 * Add Activity AI button component.
 *
 * @module     local_coursegen/local/activityai/components/add_activity_ai_button
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {BaseComponent} from 'core/reactive';

export default class extends BaseComponent {
    create(descriptor) {
        this.name = 'local_coursegen_activityai_add_button';
        this.courseid = Number(descriptor.courseid) || 0;
        this.sectionnum = descriptor.element?.dataset?.sectionnum ? Number(descriptor.element.dataset.sectionnum) : null;
        this.beforemod = descriptor.element?.dataset?.beforemod ? Number(descriptor.element.dataset.beforemod) : null;
    }

    stateReady() {
        this.addEventListener(this.element, 'click', this._handleClick);
    }

    _handleClick(event) {
        event.preventDefault();
        this.reactive.dispatch('openModal', {
            courseid: this.courseid,
            sectionnum: this.sectionnum,
            beforemod: this.beforemod,
        });
    }
}
