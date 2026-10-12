// A renderer in place of Moodle's templates: every activity row is a list item that holds its uid.
export default {
    renderForPromise: async(template, context) => ({html: `<li data-progress-uid="${context.uid}"></li>`, js: ''}),
    appendNodeContents: (list, html) => list.appended.push(html),
};
