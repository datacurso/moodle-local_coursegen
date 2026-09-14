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
 * Inline-rename an instance row's name: visually the same
 * core/inplace_editable component every other renameable title in Moodle
 * uses (same "inplaceeditingon"/"quickeditlink"/"editinstructions" classes,
 * see template_instance_row.mustache and styles/templates-instances.css),
 * but never core/inplace_editable itself — that module always commits over
 * an immediate AJAX call, while this row's name (like every other field on
 * this page) is only ever persisted later, by the wizard's own "Save".
 *
 * @module     local_coursegen/local/template/template_instance_name_edit
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * @param {HTMLElement} wrapper data-region="instance-name-editable".
 * @returns {HTMLInputElement}
 */
const inputOf = (wrapper) => wrapper.querySelector('[data-region="instance-name-input"]');

/**
 * Open the input, seeded with the wrapper's current committed value.
 *
 * @param {HTMLElement} wrapper
 */
export const startNameEdit = (wrapper) => {
    if (wrapper.classList.contains('inplaceeditingon')) {
        return;
    }
    const input = inputOf(wrapper);
    input.value = wrapper.dataset.value;
    wrapper.classList.add('inplaceeditingon');
    input.focus();
    input.select();
};

/**
 * Read the input's current value back into the wrapper — whether it is
 * still open (an in-progress rename, e.g. the wizard's own "Save" was
 * clicked before Enter/blur) or already committed.
 *
 * @param {HTMLElement} wrapper
 * @returns {string}
 */
export const currentName = (wrapper) => {
    if (wrapper.classList.contains('inplaceeditingon')) {
        return inputOf(wrapper).value;
    }
    return wrapper.dataset.value;
};

/**
 * Commit the input's value as the row's new name and close it.
 *
 * @param {HTMLElement} wrapper
 */
const commitNameEdit = (wrapper) => {
    if (!wrapper.classList.contains('inplaceeditingon')) {
        return;
    }
    const value = inputOf(wrapper).value;
    wrapper.dataset.value = value;
    wrapper.querySelector('[data-region="instance-name-display"]').textContent = value;
    wrapper.classList.remove('inplaceeditingon');
};

/**
 * Close the input without touching the row's committed name.
 *
 * @param {HTMLElement} wrapper
 */
const cancelNameEdit = (wrapper) => {
    wrapper.classList.remove('inplaceeditingon');
};

/**
 * Bind the pencil trigger and the input's own Enter/Escape/blur handling.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {Function} markDirty Marks the wizard as having unsaved changes.
 */
export const bindNameEditing = (container, markDirty) => {
    container.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-region="instance-name-edit-trigger"]');
        if (trigger) {
            e.preventDefault();
            startNameEdit(trigger.closest('[data-region="instance-name-editable"]'));
        }
    });

    container.addEventListener('keydown', (e) => {
        if (!e.target.matches('[data-region="instance-name-input"]')) {
            return;
        }
        const wrapper = e.target.closest('[data-region="instance-name-editable"]');
        if (e.key === 'Enter') {
            e.preventDefault();
            commitNameEdit(wrapper);
            markDirty();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            cancelNameEdit(wrapper);
        }
    });

    container.addEventListener('focusout', (e) => {
        if (!e.target.matches('[data-region="instance-name-input"]')) {
            return;
        }
        commitNameEdit(e.target.closest('[data-region="instance-name-editable"]'));
        markDirty();
    });
};
