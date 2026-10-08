// A tiny fake of the feed of a run: enough of the DOM for its entries, their messages and the count badge.

/** A tiny element: enough of the DOM for the feed, its entries, their messages and the count badge. */
export const makeElement = (className = '') => {
    const element = {
        className,
        dataset: {},
        attributes: {},
        children: [],
        childNodes: [],
        textContent: '',
        classList: {contains: (name) => element.className.split(/\s+/).includes(name)},
        setAttribute(name, value) {
            element.attributes[name] = value;
        },
        appendChild(child) {
            element.children.push(child);
            element.childNodes.push(child);
            return child;
        },
        querySelector(selector) {
            const wanted = selector.replace('.', '');
            const all = [];
            const walk = (node) => {
                node.children.forEach((child) => {
                    all.push(child);
                    walk(child);
                });
            };
            walk(element);
            return all.find((node) => node.classList.contains(wanted)) || null;
        },
    };
    Object.defineProperty(element, 'lastElementChild', {
        get: () => element.children[element.children.length - 1] || null,
    });
    Object.defineProperty(element, 'ownerDocument', {value: {createElement: () => makeElement()}});
    return element;
};

/** One step of the feed, in the markup the log draws: an entry holding its message as one text node. */
export const makeStep = (text, className = 'cg-log-entry cg-log-entry--ai cg-log-entry--turn-ai') => {
    const entry = makeElement(className);
    const message = makeElement('cg-log-msg');
    message.textContent = text;
    message.childNodes.push({nodeValue: text, textContent: text});
    entry.appendChild(message);
    return entry;
};

export const makeFeed = (...steps) => {
    const feed = makeElement('cg-log cg-thread-feed');
    steps.forEach((step) => feed.appendChild(step));
    return feed;
};
