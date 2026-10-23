import { expect, it } from 'vitest';
import { renderMarkdown } from '../../resources/saola/web/app/services/Markdown';

const render = (source: string) => {
    const element = document.createElement('div');
    element.innerHTML = renderMarkdown(source);
    return element;
};

it('renders dollar and backslash delimiters without Markdown changing TeX syntax', () => {
    const element = render(String.raw`Nghiệm $x_1 = \frac{-b+\sqrt{b^2-4ac}}{2a}$ và \(E=mc^2\).

$$\int_0^1 x^2\,dx = \frac{1}{3}$$

\[
\begin{pmatrix}1 & 2 \\ 3 & 4\end{pmatrix}
\]`);
    expect(element.querySelectorAll('.katex')).toHaveLength(4);
    expect(element.querySelectorAll('.katex-display')).toHaveLength(2);
    expect(element.querySelectorAll('math')).toHaveLength(4);
    expect(element.querySelector('annotation')?.textContent).toContain('x_1');
    expect(element.querySelector('.katex-error')).toBeNull();
});

it('renders chemistry reactions, ions, isotopes and physical units with mhchem', () => {
    const element = render(String.raw`$\ce{2H2 + O2 -> 2H2O}$, $\ce{SO4^2-}$, $\ce{^{14}C}$, $\pu{9.81 m s-2}$.`);
    expect(element.querySelectorAll('.katex')).toHaveLength(4);
    expect(element.querySelector('.katex-error')).toBeNull();
    expect(element.querySelector('annotation')?.textContent).toBe(String.raw`\ce{2H2 + O2 -> 2H2O}`);
});

it('leaves code, escaped dollars and common currency text untouched', () => {
    const element = render('Giá $5 và $10. \\$20. `\\(x^2\\)`\n\n```latex\n$$x^2$$\n\\ce{H2O}\n```');
    expect(element.querySelector('.katex')).toBeNull();
    expect(element.textContent).toContain('Giá $5 và $10. $20.');
    expect(element.querySelector('code')?.textContent).toBe(String.raw`\(x^2\)`);
    expect(element.querySelector('pre code')?.textContent).toContain('$$x^2$$');
});

it('keeps partial streaming formulas readable and renders them once complete', () => {
    const partial = String.raw`Kết quả: \(\frac{1}{2}`;
    expect(render(partial).querySelector('.katex')).toBeNull();
    expect(render(partial + String.raw`\)`).querySelector('.katex')).not.toBeNull();
    const malformed = render(String.raw`$\frac{1}{$`);
    expect(malformed.querySelector('.katex-error')?.textContent).toBe(String.raw`\frac{1}{`);
});

it('keeps Markdown sanitization and blocks TeX links, images and HTML extensions', () => {
    const element = render(String.raw`<span style="position:fixed" onclick="alert(1)">text</span><img src="https://tracker.invalid/pixel">

$\href{javascript:alert(1)}{click}$
$\includegraphics{https://tracker.invalid/math}$
$\htmlStyle{position:fixed}{x}$
$\text{<img src=x onerror=alert(1)>}$`);
    expect(element.querySelector('img,script,iframe,form,a[href^="javascript:"]')).toBeNull();
    expect(element.querySelector('[onclick],[onerror]')).toBeNull();
    expect(element.querySelector('span[style*="position:fixed"]')).toBeNull();
    expect(element.textContent).toContain('text');
});
