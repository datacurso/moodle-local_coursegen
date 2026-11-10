// A page reduced to what the modules touch: elements by id (made on demand), created elements, classes, events.
export const makeElement = (id = '') => {
    const classes = new Set();
    const element = {
        id,
        hidden: false,
        disabled: false,
        checked: false,
        style: {},
        dataset: {},
        attrs: {},
        textContent: '',
        innerHTML: '',
        value: '',
        children: [],
        handlers: {},
        classList: {
            add: (...names) => names.forEach((name) => classes.add(name)),
            remove: (...names) => names.forEach((name) => classes.delete(name)),
            contains: (name) => classes.has(name),
            toggle: (name, force) => {
                const on = force === undefined ? !classes.has(name) : Boolean(force);
                if (on) {
                    classes.add(name);
                } else {
                    classes.delete(name);
                }
                return on;
            },
        },
        get className() {
            return [...classes].join(' ');
        },
        set className(value) {
            classes.clear();
            String(value).split(/\s+/).filter(Boolean).forEach((name) => classes.add(name));
        },
        setAttribute(name, value) {
            element.attrs[name] = String(value);
        },
        removeAttribute(name) {
            delete element.attrs[name];
        },
        getAttribute: (name) => (name in element.attrs ? element.attrs[name] : null),
        addEventListener(type, handler) {
            (element.handlers[type] = element.handlers[type] || []).push(handler);
        },
        removeEventListener(type, handler) {
            element.handlers[type] = (element.handlers[type] || []).filter((h) => h !== handler);
        },
        dispatch(type) {
            (element.handlers[type] || []).forEach((handler) => handler({preventDefault() {}, target: element}));
        },
        click() {
            if (element.disabled) {
                return;
            }
            element.dispatch('click');
        },
        append(...nodes) {
            element.children.push(...nodes);
        },
        appendChild(node) {
            element.children.push(node);
            return node;
        },
        remove() {
            element.removed = true;
            if (element.parent) {
                element.parent.children = element.parent.children.filter((child) => child !== element);
            }
        },
        replaceChildren(...nodes) {
            element.children = [...nodes];
        },
        scrollIntoView() {},
        querySelector: () => null,
        querySelectorAll: () => [],
        closest: () => null,
        focus() {},
    };
    const originalAppend = element.append;
    element.append = (...nodes) => {
        nodes.forEach((node) => {
            if (node && typeof node === 'object') {
                node.parent = element;
            }
        });
        originalAppend(...nodes);
    };
    element.appendChild = (node) => {
        element.append(node);
        return node;
    };
    return element;
};

export const makeDocument = () => {
    const elements = {};
    const body = makeElement('body');
    return {
        elements,
        body,
        createElement: (tag) => Object.assign(makeElement(''), {tag}),
        getElementById: (id) => (elements[id] = elements[id] || makeElement(id)),
        querySelector: () => null,
        querySelectorAll: () => [],
        addEventListener() {},
    };
};

/** Every node under a root (the root included) that the predicate accepts. */
export const findAll = (root, predicate) => {
    const found = [];
    const walk = (node) => {
        if (!node || typeof node !== 'object') {
            return;
        }
        if (predicate(node)) {
            found.push(node);
        }
        (node.children || []).forEach(walk);
    };
    walk(root);
    return found;
};

export const byText = (root, text) => findAll(root, (node) => node.textContent === text);
export const buttons = (root) => findAll(root, (node) => node.tag === 'button');
export const settle = async() => {
    for (let i = 0; i < 12; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
};
