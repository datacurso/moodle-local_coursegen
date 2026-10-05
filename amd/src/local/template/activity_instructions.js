// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Bind the optional per-activity AI instruction fields.
 *
 * @module local_coursegen/local/template/activity_instructions
 * @copyright 2026 Wilber Narvaez
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Selectors from 'local_coursegen/local/template/selectors';
import {ACTION} from 'local_coursegen/local/template/constants';

/**
 * Show the instruction editor only while its activity is set to AI modification.
 *
 * @param {HTMLElement} container The rendered course structure.
 * @param {number} cmid Course module ID.
 */
const syncInstructionVisibility = (container, cmid) => {
    const actionSelector = Selectors.regions.activityActionSelect + '[data-id="' + cmid + '"]';
    const rowSelector = Selectors.regions.activityInstructionRow + '[data-id="' + cmid + '"]';
    const action = container.querySelector(actionSelector);
    const row = container.querySelector(rowSelector);
    if (!action || !row) {
        return;
    }
    row.classList.toggle('d-none', action.value !== ACTION.TEMPLATE);
};

/**
 * Keep prompt state in sync with the optional textarea.
 *
 * @param {Object} state The template wizard state.
 * @param {HTMLElement} input The activity instruction field.
 * @param {Function} markDirty
 */
const bindInstructionInput = (state, input, markDirty) => {
    const cmid = Number(input.dataset.id);
    if (!cmid) {
        return;
    }
    input.value = state.activityPrompt[cmid] || input.value || '';
    const onInput = handleInstructionInput.bind(null, state, input, cmid, markDirty);
    input.addEventListener('input', onInput);
};

/**
 * Store a changed activity instruction.
 *
 * @param {Object} state
 * @param {HTMLElement} input
 * @param {number} cmid
 * @param {Function} markDirty
 */
const handleInstructionInput = (state, input, cmid, markDirty) => {
    state.activityPrompt[cmid] = input.value;
    markDirty();
};

/**
 * Keep one instruction row's visibility in sync with its selected action.
 *
 * @param {HTMLElement} container
 * @param {number} cmid
 */
const handleActionChange = (container, cmid) => {
    syncInstructionVisibility(container, cmid);
};

/**
 * Bind the row's instruction fields and keep their visibility in sync.
 *
 * @param {HTMLElement} container The rendered course structure.
 * @param {Object} state The template wizard state.
 * @param {Function} markDirty Marks the wizard as changed.
 */
export const bindActivityInstructions = (container, state, markDirty) => {
    const inputs = container.querySelectorAll(Selectors.regions.activityInstruction);
    for (const input of inputs) {
        bindInstructionInput(state, input, markDirty);
        const cmid = Number(input.dataset.id);
        syncInstructionVisibility(container, cmid);
    }
    const actions = container.querySelectorAll(Selectors.regions.activityActionSelect);
    for (const action of actions) {
        const cmid = Number(action.dataset.id);
        const onActionChange = handleActionChange.bind(null, container, cmid);
        action.addEventListener('change', onActionChange);
    }
};
