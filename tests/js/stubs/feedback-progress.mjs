// The "working" indicator of the left column, reduced to a record: it is shown, or it is hidden.
export const state = {shown: false, text: ''};
export const showWorkingIndicator = (options, text) => {
    state.shown = true;
    state.text = text;
};
export const hideWorkingIndicator = () => {
    state.shown = false;
};
