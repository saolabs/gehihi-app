import { Marked, type Tokens } from 'marked';
import DOMPurify from 'dompurify';
import katex from 'katex';
import 'katex/contrib/mhchem';

// Tokenize before Markdown consumes TeX backslashes, underscores and line breaks.
// Fenced code and inline code remain handled by Marked's own code tokenizers.
const blockMath = /^ {0,3}(?:\$\$([\s\S]+?)\$\$|\\\[([\s\S]+?)\\\])(?:[ \t]*\n)?/;
const inlineMath = /^(?:\$\$([\s\S]+?)\$\$|\\\[([\s\S]+?)\\\]|\\\(([\s\S]+?)\\\)|\$(?!\s)([^\n]*?[^\s\\])\$(?!\d))/;

export function renderMarkdown(text: string): string {
    const formulas: { id: string; tex: string; display: boolean }[] = [];
    const prefix = 'gehihi-math-' + crypto.randomUUID();
    const renderer = (token: Tokens.Generic) => {
        const id = prefix + '-' + formulas.length;
        formulas.push({ id, tex: token.tex, display: token.display });
        return token.type === 'mathBlock' ? `<div id="${id}" class="math-block"></div>\n` : `<span id="${id}"></span>`;
    };
    const markdown = new Marked({ extensions: [
        {
            name: 'mathBlock', level: 'block',
            start: source => source.search(/(?:^|\n) {0,3}(?:\$\$|\\\[)/),
            tokenizer(source) {
                const match = blockMath.exec(source);
                if (match) return { type: 'mathBlock', raw: match[0], tex: (match[1] ?? match[2]).trim(), display: true };
            }, renderer,
        },
        {
            name: 'mathInline', level: 'inline',
            start: source => source.search(/\$|\\[([]/),
            tokenizer(source) {
                const match = inlineMath.exec(source);
                if (match) return { type: 'mathInline', raw: match[0], tex: (match[1] ?? match[2] ?? match[3] ?? match[4]).trim(), display: match[1] !== undefined || match[2] !== undefined };
            }, renderer,
        },
    ] });
    const html = markdown.parse(text || '', { async: false, breaks: true }) as string;
    const safe = DOMPurify.sanitize(html, { FORBID_TAGS: ['img', 'form', 'input', 'style', 'iframe'], FORBID_ATTR: ['style'], ALLOW_DATA_ATTR: false });
    const template = document.createElement('template');
    template.innerHTML = safe;
    // Only KaTeX creates styled markup, after untrusted Markdown is sanitized.
    // Keep trust disabled: TeX cannot inject HTML, links or external images.
    for (const formula of formulas) {
        const slot = template.content.getElementById(formula.id);
        if (!slot) continue;
        katex.render(formula.tex, slot, { displayMode: formula.display, output: 'htmlAndMathml', throwOnError: false, trust: false, strict: 'ignore', maxSize: 20, maxExpand: 1000, macros: {} });
        slot.removeAttribute('id');
    }
    template.content.querySelectorAll('a').forEach(a => { a.target = '_blank'; a.rel = 'noopener noreferrer nofollow'; });
    return template.innerHTML;
}
