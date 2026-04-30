// This file is part of Moodle - http://moodle.org/

/**
 * Context step UI facade.
 *
 * @module     local_coursegen/local/wizard/ui-context
 */

import {setupContextSection} from 'local_coursegen/local/wizard/context_section';

export const createContextUi = (deps) => setupContextSection(deps);
