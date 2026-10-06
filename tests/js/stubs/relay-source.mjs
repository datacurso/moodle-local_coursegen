// A scripted stand-in for the relay source: the test decides which events the pass receives and when.
const CONNECTING = 0;
const OPEN = 1;
const CLOSED = 2;

export default class FakeRelaySource {
    static CONNECTING = CONNECTING;
    static OPEN = OPEN;
    static CLOSED = CLOSED;
    static instances = [];

    constructor(url) {
        this.url = url;
        this.readyState = CONNECTING;
        this.onerror = null;
        this.closed = 0;
        this.listeners = new Map();
        FakeRelaySource.instances.push(this);
    }

    static reset() {
        FakeRelaySource.instances = [];
    }

    static last() {
        return FakeRelaySource.instances[FakeRelaySource.instances.length - 1];
    }

    addEventListener(type, listener) {
        const current = this.listeners.get(type) || [];
        current.push(listener);
        this.listeners.set(type, current);
    }

    close() {
        this.closed += 1;
        this.readyState = CLOSED;
    }

    emitMessage(payload) {
        this.readyState = OPEN;
        this.fire('message', {data: JSON.stringify(payload)});
    }

    emitDone() {
        this.fire('done', {});
    }

    emitError() {
        if (this.onerror) {
            this.onerror({});
        }
    }

    fire(type, event) {
        for (const listener of this.listeners.get(type) || []) {
            listener(event);
        }
    }
}
