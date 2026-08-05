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
 * Detailed planning UI — thin composition root.
 *
 * @module     local_coursegen/local/courseai/ui-detailed
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import DeleteCancelModal from 'core/modal_delete_cancel';
import ModalEvents from 'core/modal_events';
import {createTextPanel} from 'local_coursegen/local/courseai/ui/panel';
import {focusChange, markRemoving} from 'local_coursegen/local/courseai/ui/highlight';
import {normalizeInitialSections} from './detailed/normalize';
import {
    initDetailedPlanView,
    finalizePlanView,
    handleDetailedPlanField,
    handleDetailedPlanActivity,
    syncDetailedStructureFromSections,
    enableAllActionControls,
    reconcilePlan,
} from './detailed/view';
import {updateDetailedHeaderStats} from './detailed/badges';
import {markProposalTargetPending} from './detailed/pending';

/**
 * Build the confirmDelete helper bound to DeleteCancelModal.
 *
 * @param {Object} options
 * @param {string} options.title
 * @param {string} options.body
 * @returns {Promise<boolean>}
 */
const buildConfirmDelete = async({title, body}) => {
    const modal = await DeleteCancelModal.create({title, body});

    return await new Promise((resolve) => {
        let resolved = false;

        modal.getRoot().on(ModalEvents.delete, () => {
            resolved = true;
            resolve(true);
        });

        modal.getRoot().on(ModalEvents.hidden, () => {
            if (!resolved) {
                resolve(false);
            }
            modal.destroy();
        });

        modal.show();
    });
};

/**
 * Create detailed planning helpers.
 *
 * @param {Object} deps
 * @param {Object} deps.state
 * @param {Object} deps.elements
 * @param {Object} deps.activityLabels
 * @param {Function} deps.getActivityIconUrl
 * @param {Function} deps.escapeHtml
 * @param {Function} deps.switchPlanMode
 * @param {Function} deps.setProgress
 * @param {Object} deps.texts
 * @param {Function} deps.formatTemplate
 * @param {Function} deps.runPlanAction
 * @param {Function} [deps.emitLog]
 * @returns {Object}
 */
export const createDetailedUi = (deps) => {
    const {
        state,
        elements,
        activityLabels,
        getActivityIconUrl,
        escapeHtml,
        switchPlanMode,
        setProgress,
        texts,
        formatTemplate,
        runPlanAction,
        emitLog,
    } = deps;

    /**
     * Emit a log entry if emitLog is wired.
     *
     * @param {Object} params
     */
    const log = (params) => {
        if (typeof emitLog === 'function') {
            emitLog(params);
        }
    };

    /** Shared context object threaded through all module functions. */
    const ctx = {
        state,
        elements,
        activityLabels,
        getActivityIconUrl,
        escapeHtml,
        switchPlanMode,
        setProgress,
        texts,
        formatTemplate,
        runPlanAction,
        log,
        confirmDelete: buildConfirmDelete,
        createTextPanel,
        focusChange,
        markRemoving,
    };

    return {
        normalizeInitialSections: (sections) => normalizeInitialSections(ctx, sections),
        initDetailedPlanView: (data) => initDetailedPlanView(ctx, data),
        finalizePlanView: () => finalizePlanView(ctx),
        handleDetailedPlanField: (data) => handleDetailedPlanField(ctx, data),
        handleDetailedPlanActivity: (data) => handleDetailedPlanActivity(ctx, data),
        syncDetailedStructureFromSections: (sections) => syncDetailedStructureFromSections(ctx, sections),
        updateDetailedHeaderStats: () => updateDetailedHeaderStats(ctx),
        enableAllActionControls: () => enableAllActionControls(ctx),
        reconcilePlan: (currentPlan) => reconcilePlan(ctx, currentPlan),
        markProposalTargetPending: (intent) => markProposalTargetPending(ctx, intent),
    };
};
