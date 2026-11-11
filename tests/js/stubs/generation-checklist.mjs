// The checklist of the left column, reduced to a record of what the run told it.
export const events = [];
export const resetChecklist = () => events.push(['reset']);
export const openChecklist = (progress) => events.push(['open', progress]);
export const addActivity = (data) => events.push(['add', data]);
export const closeActivity = (uid, progress) => events.push(['close', uid, progress]);
export const showRepeatProgress = (uid, summary) => events.push(['repeat', uid, summary]);
export const showWrittenChecklist = (written) => events.push(['written', written]);
