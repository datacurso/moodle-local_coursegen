// A minimal page for the tests that read and drive toggles and scroll positions.

export const toggle = (open, onClick = () => undefined) => {
    const classes = new Set();
    if (open) {
        classes.add('open');
    }
    const element = {
        attributes: {'aria-expanded': String(open)},
        classes,
        clicks: 0,
        getAttribute(name) {
            if (this.attributes[name] === undefined) {
                return null;
            }
            return this.attributes[name];
        },
        querySelector(selector) {
            if (selector === '.cg-activity-chevron--open' && this.classes.has('open')) {
                return {};
            }
            return null;
        },
        click() {
            this.clicks++;
            onClick(this);
        },
    };
    return element;
};

export const scroller = (top, clientHeight, scrollHeight) => ({scrollTop: top, clientHeight, scrollHeight});

export const page = ({lists = {}, scrollers = {}}) => ({
    querySelectorAll: (selector) => lists[selector] || [],
    getElementById: (id) => scrollers[id] || null,
});

export const textField = (value = '') => ({
    value,
    events: [],
    dispatchEvent(event) {
        this.events.push(event.type);
        return true;
    },
});
