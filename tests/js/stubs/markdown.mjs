// The Markdown renderer needs a browser; under node the text comes back unchanged.
export const renderMarkdown = (text) => String(text);
