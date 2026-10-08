// A recorder in place of the waiting line and the paused state: it keeps what the event handlers ask the page to show.
export const shown = [];
// Whether the run is settled (a finished run leaves the last step as a check, not a spinner), kept apart so the
// sequence of waiting calls above stays what it was.
export const settledStates = [];

export const reset = () => {
    shown.length = 0;
    settledStates.length = 0;
};

export const showWaiting = (seconds) => shown.push(['wait', seconds]);
export const clearWaiting = () => shown.push(['clear']);
export const setPaused = (paused) => shown.push(['paused', paused]);
export const setSettled = (settled) => settledStates.push(settled);
