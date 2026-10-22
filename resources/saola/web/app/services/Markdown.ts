import { marked } from 'marked';
import DOMPurify from 'dompurify';
export function renderMarkdown(text: string): string {
    const html = marked.parse(text || '', { async: false, breaks: true }) as string;
    const safe = DOMPurify.sanitize(html, { FORBID_TAGS: ['img', 'form', 'input', 'style', 'iframe'], FORBID_ATTR: ['style'], ALLOW_DATA_ATTR: false });
    const template = document.createElement('template');
    template.innerHTML = safe;
    template.content.querySelectorAll('a').forEach(a => { a.target = '_blank'; a.rel = 'noopener noreferrer nofollow'; });
    return template.innerHTML;
}
