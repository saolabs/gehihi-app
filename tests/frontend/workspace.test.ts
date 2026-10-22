import { afterEach, describe, expect, it, vi } from 'vitest';
import { GehihiClient } from '../../resources/saola/web/app/services/GehihiClient';
import { renderMarkdown } from '../../resources/saola/web/app/services/Markdown';

const clients: GehihiClient[] = [];
function client() { const c = new GehihiClient(() => {}); clients.push(c); return c; }
afterEach(() => { clients.splice(0).forEach(c => c.destroy()); vi.unstubAllGlobals(); });

describe('workspace context', () => {
    it('renders only the selected branch and limits selectable sources to the current project', () => {
        const c = client();
        c.patch({ project: { id: 'p1' }, files: [{ id: 'a' }, { id: 'b', project_id: 'p1' }, { id: 'c', project_id: 'p2' }], conversation: { selected_leaf_id: 'new', messages: [
            { id: 'u', parent_id: null, role: 'user', content: 'Question' },
            { id: 'old', parent_id: 'u', role: 'assistant', content: 'Old answer' },
            { id: 'new', parent_id: 'u', role: 'assistant', content: 'Regenerated answer' },
        ] } });
        expect(c.state.visibleMessages.map(m => m.id)).toEqual(['u', 'new']);
        expect(c.state.availableFiles.map(f => f.id)).toEqual(['a', 'b']);
    });

    it('does not restore an old conversation when its network response arrives after navigation', async () => {
        const c = client(); let respond!: (value: any) => void;
        vi.spyOn(c, 'api').mockImplementation(() => new Promise(resolve => { respond = resolve; }));
        const request = c.open('old');
        c.newChat({ id: 'new-project' });
        respond({ id: 'old', messages: [] });
        await request;
        expect(c.state.conversation).toBeNull();
        expect(c.state.project.id).toBe('new-project');
    });

    it('sends the session CSRF token with same-origin mutations', async () => {
        document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
        const fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { id: 'p' } }) });
        vi.stubGlobal('fetch', fetch);
        await client().api('/projects', 'POST', { name: 'Example' });
        expect(fetch).toHaveBeenCalledWith('/api/projects', expect.objectContaining({ credentials: 'same-origin', headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'test-token' }) }));
    });
});

describe('untrusted model output', () => {
    it('removes executable markup, forms and tracking images while retaining readable code and source links', () => {
        const html = renderMarkdown('<script>alert(1)</script><img src="https://tracker.invalid/pixel"><form><input></form><a href="javascript:alert(2)">bad</a>\n\n```js\nconst value = 1;\n```\n\n[Source](https://example.com/source)');
        const element = document.createElement('div'); element.innerHTML = html;
        expect(element.querySelector('script,img,form,input')).toBeNull();
        expect(element.querySelector('a')?.hasAttribute('href')).toBe(false);
        expect(element.querySelector('code')?.textContent).toContain('const value = 1;');
        const source = element.querySelector('a[href="https://example.com/source"]');
        expect(source?.getAttribute('rel')).toContain('noopener');
    });
});
