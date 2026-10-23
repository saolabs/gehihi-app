import { modelIds, modelChoices, toggleModel, type ModelField } from '@app/ProviderModels';

type Data = Record<string, any>;
export class GehihiClient {
    state: Data = { loading: true, busy: false, error: '', notice: '', locale: 'vi', theme: 'system', workspace: {}, user: null, projects: [], conversations: [], files: [], catalog: [], models: [], providers: [], providerTypes: {}, providerModelCatalog: null, providerModelFilter: '', providerModelChoices: [], providerSelectedModels: [], conversation: null, project: null, messages: [], visibleMessages: [], run: null, mode: 'chat', thinking: 'auto', selectedModel: '', searchProvider: '', selectedFiles: [], modal: '', editProvider: {}, editProject: {}, systemProviders: false, probe: null, search: '', archived: false, mobileNav: false, usage: {}, sourcePanel: false, advanced: false, fallbacks: [], includeDomains: '', excludeDomains: '', language: 'vi', country: 'vn', import_available: false, guestImportPrompt: false, resetToken: '', resetEmail: '', researchNote: '', trashGroups: [] };
    private polling?: ReturnType<typeof setTimeout>;
    private fileWatch?: ReturnType<typeof setTimeout>;
    private guestImportTimer?: ReturnType<typeof setTimeout>;
    private guestImportKey = '';
    private runTimer?: ReturnType<typeof setInterval>;
    private timedRun = '';
    private runStarted = 0;
    private pendingFiles = new Set<string>();
    private disposed = false;
    private requestId?: string;
    private navigation = 0;
    private pop = () => this.route();
    constructor(private emit: (state: Data) => void) { window.addEventListener('popstate', this.pop); }
    patch(data: Data) {
        this.state = { ...this.state, ...data };
        if ('import_available' in data) this.syncGuestImportPrompt();
        if ('run' in data) this.syncRunTimer();
        const all = this.state.conversation?.messages || [];
        const map = new Map<string, Data>(all.map((m: Data) => [m.id, m]));
        const chain: Data[] = [];
        let cursor = this.state.conversation?.selected_leaf_id;
        for (let i = 0; cursor && i < 200; i++) { const m = map.get(cursor); if (!m) break; chain.unshift(m); cursor = m.parent_id; }
        this.state.visibleMessages = chain;
        this.state.models = this.state.catalog.filter((p: Data) => p.kind === 'llm').flatMap((p: Data) => p.models.map((id: string) => ({ value: p.id + '|' + id, label: (p.scope === 'system' ? 'Hệ thống' : 'Của bạn') + ' · ' + p.name + ' · ' + id })));
        this.state.history = this.historyItems(); this.state.availableFiles = this.availableFiles(); this.state.typeOptions = Object.entries(this.state.providerTypes).map(([value, meta]: [string, any]) => ({ value, label: meta.label }));
        this.state.providerSelectedModels = modelIds(this.state.editProvider.models);
        this.state.providerModelChoices = modelChoices(this.state.providerModelCatalog, this.state.editProvider, this.state.providerModelFilter);
        this.emit({ ...this.state });
    }
    private syncRunTimer() {
        const run = this.state.run;
        this.state.runActive = !!run && ['queued', 'running'].includes(run.status);
        if (run?.id !== this.timedRun) {
            this.timedRun = run?.id || '';
            this.runStarted = Date.now();
        }
        const started = Date.parse(run?.created_at || run?.started_at || '');
        if (Number.isFinite(started)) this.runStarted = started;
        const end = Date.parse(run?.finished_at || '');
        const seconds = Math.max(0, Math.floor(((Number.isFinite(end) ? end : Date.now()) - this.runStarted) / 1000));
        this.state.runElapsed = Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
        if (!this.state.runActive || this.disposed) {
            clearInterval(this.runTimer);
            this.runTimer = undefined;
        } else if (!this.runTimer) {
            this.runTimer = setInterval(() => this.patch({ run: this.state.run }), 1000);
        }
    }
    private syncGuestImportPrompt() {
        if (!this.state.import_available || !this.state.user) {
            clearTimeout(this.guestImportTimer);
            this.state.guestImportPrompt = false;
            return;
        }
        const key = 'gehihi:guest-import-dismissed:' + this.state.user.id + ':' + this.state.workspace.id;
        if (this.guestImportKey === key) return;
        clearTimeout(this.guestImportTimer);
        this.state.guestImportPrompt = false;
        this.guestImportKey = key;
        try { if (sessionStorage.getItem(key)) return; } catch { /* Storage can be unavailable in private browsers. */ }
        this.state.guestImportPrompt = true;
        this.guestImportTimer = setTimeout(() => this.dismissGuestImportPrompt(), 10000);
    }
    dismissGuestImportPrompt() {
        clearTimeout(this.guestImportTimer);
        try { if (this.guestImportKey) sessionStorage.setItem(this.guestImportKey, '1'); } catch { /* Keep dismissal for this page in memory. */ }
        this.patch({ guestImportPrompt: false });
    }
    async api(path: string, method = 'GET', body?: any): Promise<any> {
        const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        if (token) headers['X-CSRF-TOKEN'] = token;
        if (body && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
        const response = await fetch('/api' + path, { method, credentials: 'same-origin', headers, body: body instanceof FormData ? body : body ? JSON.stringify(body) : undefined });
        const data = await response.json().catch(() => ({ message: 'Phiên làm việc đã thay đổi. Hãy tải lại trang.' }));
        if (!response.ok || data.success === false) throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Không thực hiện được yêu cầu.');
        return data.data;
    }
    async attempt(fn: () => Promise<any>) {
        this.patch({ error: '', busy: true });
        try { return await fn(); } catch (e) { this.patch({ error: e instanceof Error ? e.message : 'Có lỗi xảy ra.' }); return null; } finally { this.patch({ busy: false }); }
    }
    async load() {
        await this.attempt(async () => {
            const data = await this.api('/bootstrap');
            const pref = data.workspace.preferences || {};
            this.patch({ ...data, locale: pref.locale || 'vi', theme: pref.theme || 'system', loading: false });
            this.applyTheme(pref.theme || 'system');
            await this.route();
            const params = new URLSearchParams(location.search);
            if (params.has('login') && !this.state.user) this.patch({ modal: 'login' });
            if (params.has('reset_token')) this.patch({ modal: 'reset', resetToken: params.get('reset_token'), resetEmail: params.get('email') || '' });
        });
        this.patch({ loading: false });
    }
    async refresh() { const data = await this.api('/bootstrap?q=' + encodeURIComponent(this.state.search)); this.patch(data); }
    async route() {
        clearTimeout(this.polling);
        const match = location.pathname.match(/^\/c\/([^/]+)/);
        const project = location.pathname.match(/^\/p\/([^/]+)/);
        if (match) await this.open(match[1], false);
        else { this.patch({ conversation: null, run: null, project: project ? this.state.projects.find((p: Data) => p.id === project[1]) || null : null }); }
    }
    async open(id: string, navigate = true) {
        const navigation = ++this.navigation;
        await this.attempt(async () => {
            clearTimeout(this.polling);
            const conversation = await this.api('/conversations/' + id);
            if (navigation !== this.navigation || this.disposed) return;
            this.patch({ conversation, project: conversation.project, selectedModel: this.selectionValue(conversation.selection || conversation.project?.selection), mobileNav: false, run: null, selectedFiles: [] });
            this.scrollToLatest(true);
            if (navigate) history.pushState({}, '', '/c/' + id);
            const run = await this.api('/conversations/' + id + '/run');
            if (navigation !== this.navigation || this.disposed) return;
            if (run) { this.updateRun(run); if (['queued', 'running'].includes(run.status)) this.poll(run.id); }
        });
    }
    newChat(project?: Data, temporary = false) {
        ++this.navigation;
        clearTimeout(this.polling); this.requestId = undefined;
        this.patch({ conversation: null, project: project || null, run: null, selectedFiles: [], selectedModel: this.selectionValue(project?.selection), mobileNav: false, temporary });
        history.pushState({}, '', project ? '/p/' + project.id : '/');
    }
    async createConversation() {
        if (this.state.conversation) return this.state.conversation;
        const c = await this.api('/conversations', 'POST', { project_id: this.state.project?.id || null, temporary: !!this.state.temporary });
        const conversation = { ...c, messages: [], project: this.state.project };
        this.patch({ conversation }); history.replaceState({}, '', '/c/' + c.id); return conversation;
    }
    selectionValue(s?: Data) { return s?.provider_id ? s.provider_id + '|' + s.model_id : ''; }
    selection() {
        if (!this.state.selectedModel) return null;
        const pair = (value: string) => { const [provider_id, ...model] = value.split('|'); return { provider_id, model_id: model.join('|') }; };
        return { ...pair(this.state.selectedModel), fallbacks: this.state.fallbacks.filter(Boolean).slice(0, 3).map(pair) };
    }
    async send(text: string, options: Data = {}) {
        if (this.state.busy || ['queued', 'running'].includes(this.state.run?.status) || (!text.trim() && !options.regenerate_id)) return false;
        let accepted = false;
        await this.attempt(async () => {
            const c = await this.createConversation();
            this.requestId ||= crypto.randomUUID();
            const run = await this.api('/conversations/' + c.id + '/runs', 'POST', { request_id: this.requestId, message: text, parent_id: c.selected_leaf_id, file_ids: this.state.selectedFiles, mode: this.state.mode, thinking: this.state.thinking, selection: this.selection(), search_provider_id: this.state.searchProvider || null, language: this.state.language, country: this.state.country, include_domains: this.domains(this.state.includeDomains), exclude_domains: this.domains(this.state.excludeDomains), ...options });
            accepted = true; this.requestId = undefined;
            const conversation = await this.api('/conversations/' + c.id);
            this.patch({ conversation, selectedFiles: [], run }); this.scrollToLatest(true); await this.refresh(); this.poll(run.id);
        });
        return accepted;
    }
    private domains(text: string) { return text.split(/[\s,]+/).map(v => v.replace(/^https?:\/\//, '').replace(/\/$/, '').toLowerCase()).filter(Boolean); }
    updateRun(run: Data) {
        if (run.conversation_id !== this.state.conversation?.id) return;
        this.scrollToLatest();
        const c = this.state.conversation;
        const messages = c.messages.map((m: Data) => m.id === run.message_id ? { ...m, content: run.content, status: run.status, metadata: { ...m.metadata, model: run.actual_model, sources: run.sources, error: run.error } } : m);
        this.patch({ run, conversation: { ...c, messages } });
    }
    private scrollToLatest(force = false) {
        const area = document.querySelector<HTMLElement>('.conversation-scroll');
        if (area && (force || area.scrollHeight - area.scrollTop - area.clientHeight < 140)) {
            requestAnimationFrame(() => { if (area.isConnected) area.scrollTop = area.scrollHeight; });
        }
    }
    poll(id: string) {
        clearTimeout(this.polling);
        const tick = async () => {
            if (this.disposed || this.state.run?.id !== id) return;
            try { const run = await this.api('/runs/' + id); if (this.disposed || this.state.run?.id !== id) return; this.updateRun(run); if (['queued', 'running'].includes(run.status)) this.polling = setTimeout(tick, 650); else { await this.refresh(); this.patch({ usage: await this.api('/usage') }); } }
            catch (e) { this.patch({ notice: 'Mất kết nối cập nhật. Đang thử nối lại…' }); this.polling = setTimeout(tick, 2500); }
        };
        this.polling = setTimeout(tick, 150);
    }
    async cancel() { await this.attempt(async () => { const run = await this.api('/runs/' + this.state.run.id + '/cancel', 'POST'); this.updateRun(run); }); }
    async resume() { await this.attempt(async () => { const run = await this.api('/runs/' + this.state.run.id + '/resume', 'POST'); this.updateRun(run); this.poll(run.id); }); }
    async chooseBranch(id: string) { await this.api('/conversations/' + this.state.conversation.id, 'PATCH', { selected_leaf_id: id }); await this.open(this.state.conversation.id, false); }
    siblings(message: Data): Data[] { return (this.state.conversation?.messages || []).filter((m: Data) => m.parent_id === message.parent_id && m.role === message.role); }
    async copy(text: string) { try { await navigator.clipboard.writeText(text); this.patch({ notice: 'Đã sao chép.' }); } catch { this.patch({ error: 'Trình duyệt chưa cho phép sao chép.' }); } }
    async search(value: string) { this.patch({ search: value }); await this.attempt(() => this.refresh()); }
    historyItems() { return this.state.conversations.filter((c: Data) => !!c.archived === !!this.state.archived && (!this.state.project || c.project_id === this.state.project.id)); }
    showProject(project: Data) { this.newChat(project); }
    async projectForm(event: Event) {
        event.preventDefault(); const data = Object.fromEntries(new FormData(event.currentTarget as HTMLFormElement));
        this.patch({ editProject: { ...this.state.editProject, ...data } });
        await this.attempt(async () => { const id = this.state.editProject.id; const p = await this.api('/projects' + (id ? '/' + id : ''), id ? 'PATCH' : 'POST', data); this.close(); await this.refresh(); if (this.state.conversation?.project_id === p.id) await this.open(this.state.conversation.id, false); else this.newChat(p); });
    }
    async removeProject(project: Data) { if (!confirm('Xóa dự án này? Hội thoại được giữ ở ngoài dự án; nguồn dự án được ẩn.')) return; await this.attempt(async () => { await this.api('/projects/' + project.id, 'DELETE'); this.newChat(); await this.refresh(); }); }
    async conversationAction(action: string) {
        const c = this.state.conversation; if (!c) return;
        if (action === 'rename') { this.patch({ modal: 'rename' }); return; }
        if (action === 'delete' && !confirm('Xóa cuộc trò chuyện này khỏi workspace?')) return;
        await this.attempt(async () => { if (action === 'delete') await this.api('/conversations/' + c.id, 'DELETE'); else await this.api('/conversations/' + c.id, 'PATCH', action === 'pin' ? { pinned: !c.pinned } : { archived: !c.archived }); this.newChat(); await this.refresh(); });
    }
    async rename(event: Event) { event.preventDefault(); const title = new FormData(event.currentTarget as HTMLFormElement).get('title'); await this.attempt(async () => { await this.api('/conversations/' + this.state.conversation.id, 'PATCH', { title }); this.close(); await this.open(this.state.conversation.id, false); await this.refresh(); }); }
    async moveConversation(projectId: string) {
        if (!confirm('Các lượt mới sẽ dùng hướng dẫn của dự án đích. Nguồn thuộc dự án cũ sẽ không được nạp lại; nội dung cũ vẫn còn trong lịch sử. Có thể tạo chat mới nếu cần ngữ cảnh sạch. Tiếp tục?')) return;
        await this.attempt(async () => { const id = this.state.conversation.id; await this.api('/conversations/' + id, 'PATCH', { project_id: projectId || null }); await this.open(id, false); await this.refresh(); });
    }
    toggleFile(id: string) { this.patch({ selectedFiles: this.state.selectedFiles.includes(id) ? this.state.selectedFiles.filter((f: string) => f !== id) : [...this.state.selectedFiles, id].slice(0, 5) }); }
    async upload(event: Event) {
        const input = event.target as HTMLInputElement; const files = Array.from(input.files || []);
        await this.attempt(async () => { for (const file of files.slice(0, 5)) { const data = new FormData(); data.append('file', file); if (this.state.project?.id) data.append('project_id', this.state.project.id); const source = await this.api('/files', 'POST', data); this.pendingFiles.add(source.id); } await this.refresh(); }); input.value = '';
        this.watchFiles();
    }
    /** Tệp được đọc ở worker: hỏi lại tới khi xong, tệp đọc được thì tự chọn cho lượt kế tiếp. */
    private watchFiles(round = 0) {
        clearTimeout(this.fileWatch);
        if (this.disposed || !this.pendingFiles.size) return;
        for (const file of this.state.files) {
            if (!this.pendingFiles.has(file.id) || file.status === 'processing') continue;
            this.pendingFiles.delete(file.id);
            if (['ready', 'partial'].includes(file.status)) this.patch({ selectedFiles: [...new Set([...this.state.selectedFiles, file.id])].slice(0, 5) });
            else this.patch({ error: file.name + ': ' + (file.error || 'Chưa đọc được tệp.') });
        }
        if (!this.pendingFiles.size) return;
        if (round >= 80) { this.pendingFiles.clear(); this.patch({ error: 'Tệp vẫn đang được đọc. Kiểm tra worker hàng đợi rồi mở lại danh sách nguồn.' }); return; }
        this.fileWatch = setTimeout(async () => { try { await this.refresh(); } catch { /* lỗi mạng tạm thời: thử lại vòng sau */ } this.watchFiles(round + 1); }, 1500);
    }
    async removeFile(id: string) { if (!confirm('Xóa tệp khỏi nguồn có thể dùng cho các lượt mới?')) return; await this.attempt(async () => { await this.api('/files/' + id, 'DELETE'); this.patch({ selectedFiles: this.state.selectedFiles.filter((f: string) => f !== id) }); await this.refresh(); }); }
    async openTrash() { this.patch({ modal: 'trash', trashGroups: [] }); await this.attempt(async () => this.showTrash(await this.api('/trash'))); }
    private showTrash(trash: Data) {
        const en = this.state.locale === 'en';
        const when = (at: string) => new Date(at).toLocaleString(en ? 'en-GB' : 'vi-VN', { dateStyle: 'short', timeStyle: 'short' });
        const group = (type: string, vi: string, enLabel: string, title: (x: Data) => string, extra: (x: Data) => string = () => '') => ({ type, label: en ? enLabel : vi, items: (trash[type] || []).map((x: Data) => ({ id: x.id, title: title(x), meta: [(en ? 'Deleted ' : 'Xóa lúc ') + when(x.deleted_at), extra(x)].filter(Boolean).join(' · ') })) });
        this.patch({ trashGroups: [
            group('conversations', 'Hội thoại', 'Conversations', x => x.title),
            group('projects', 'Dự án', 'Projects', x => x.name),
            group('files', 'Tệp', 'Files', x => x.name, x => Math.ceil(x.size / 1024) + ' KB'),
        ] });
    }
    async restoreTrash(type: string, id: string) { await this.attempt(async () => { this.showTrash(await this.api('/trash/' + type + '/' + id + '/restore', 'POST')); await this.refresh(); this.patch({ notice: this.state.locale === 'en' ? 'Restored.' : 'Đã khôi phục.' }); }); }
    async forgetTrash(type: string, id: string) { if (!confirm(this.state.locale === 'en' ? 'Delete forever? This cannot be undone.' : 'Xóa vĩnh viễn? Không thể hoàn tác.')) return; await this.attempt(async () => this.showTrash(await this.api('/trash/' + type + '/' + id, 'DELETE'))); }
    availableFiles() { return this.state.files.filter((f: Data) => !f.project_id || f.project_id === this.state.project?.id); }
    async settings(system = false) {
        this.patch({ modal: 'settings', systemProviders: system, probe: null, editProvider: {}, providers: [] });
        if (this.state.user) await this.attempt(async () => { const data = await this.api('/providers' + (system ? '?system=1' : '')); this.patch({ providers: data.providers, providerTypes: data.types, usage: await this.api('/usage') }); });
    }
    editProvider(provider?: Data) { this.patch({ modal: 'provider', providerModelCatalog: null, providerModelFilter: '', editProvider: provider ? { ...provider, models: provider.models.join('\n'), api_keys: '', vision_models: provider.models.filter((id: string) => provider.capabilities?.models?.[id]?.vision ?? provider.capabilities?.vision).join('\n'), reasoning_models: provider.models.filter((id: string) => provider.capabilities?.models?.[id]?.reasoning ?? provider.capabilities?.reasoning).join('\n') } : { name: '', type: 'google', base_url: '', models: '', api_keys: '', enabled: true, is_default: false }, probe: null }); }
    changeProviderType(event: Event) {
        const form = (event.target as HTMLElement).closest('form')!;
        const data = this.providerData(form), type = (event.target as HTMLSelectElement).value;
        this.patch({ editProvider: { ...this.state.editProvider, ...data, type, base_url: '', api_keys: '', clear_keys: false, models: '', default_model: '', vision_models: '', reasoning_models: '' }, providerModelCatalog: null, providerModelFilter: '', probe: null });
    }
    filterProviderModels(event: Event) {
        const form = (event.target as HTMLElement).closest('form')!;
        this.patch({ editProvider: { ...this.state.editProvider, ...this.providerData(form) }, providerModelFilter: (event.target as HTMLInputElement).value });
    }
    selectProviderModel(event: Event, field: ModelField, id: string) {
        const form = (event.target as HTMLElement).closest('form')!;
        this.patch({ editProvider: toggleModel({ ...this.state.editProvider, ...this.providerData(form) }, field, id, (event.target as HTMLInputElement).checked) });
    }
    addProviderModel(event: Event) {
        const form = (event.target as HTMLElement).closest('form')!;
        const input = form.querySelector<HTMLInputElement>('[data-model-id]')!;
        let next = { ...this.state.editProvider, ...this.providerData(form) };
        for (const id of modelIds(input.value)) next = toggleModel(next, 'models', id, true);
        input.value = '';
        this.patch({ editProvider: next });
    }
    providerData(form: HTMLFormElement): Data {
        const data: Data = Object.fromEntries(new FormData(form));
        for (const name of ['enabled', 'is_default', 'vision', 'reasoning', 'clear_keys']) data[name] = data[name] === 'on';
        if (!this.state.providerTypes[data.type]?.base_url_editable) delete data.base_url;
        return { ...data, system: this.state.systemProviders, id: this.state.editProvider.id || null };
    }
    async saveProvider(event: Event) { event.preventDefault(); const data = this.providerData(event.currentTarget as HTMLFormElement); this.patch({ editProvider: { ...this.state.editProvider, ...data } }); await this.attempt(async () => { await this.api('/providers' + (data.id ? '/' + data.id : ''), data.id ? 'PATCH' : 'POST', data); await this.refresh(); await this.settings(this.state.systemProviders); this.patch({ notice: 'Đã lưu provider. Key được mã hóa phía máy chủ.' }); }); }
    async probe(event: Event, kind: string) {
        const form = (event.currentTarget as HTMLElement).closest('form')!; const data = this.providerData(form); this.patch({ editProvider: { ...this.state.editProvider, ...data } });
        await this.attempt(async () => { const result = await this.api('/providers/' + kind, 'POST', data); if (kind === 'discover') this.patch({ providerModelCatalog: result, probe: { message: data.type === 'google_vertex' ? 'Đã nạp ' + result.length + ' model từ danh mục Vertex AI. Chọn model rồi bấm Kiểm tra key để xác nhận quyền sử dụng.' : 'Đã tải ' + result.length + ' model. Tick các model muốn sử dụng rồi lưu provider.' } }); else this.patch({ probe: result }); });
    }
    async removeProvider(id: string) { if (!confirm('Xóa provider này? Các lựa chọn đang trỏ đến nó sẽ không chạy được.')) return; await this.attempt(async () => { await this.api('/providers/' + id + (this.state.systemProviders ? '?system=1' : ''), 'DELETE'); await this.refresh(); await this.settings(this.state.systemProviders); }); }
    async auth(event: Event, action: string) {
        event.preventDefault(); const data = Object.fromEntries(new FormData(event.currentTarget as HTMLFormElement));
        await this.attempt(async () => { await this.api('/auth/' + action, 'POST', data); if (['forgot', 'reset'].includes(action)) { this.patch({ notice: action === 'forgot' ? 'Nếu email đã đăng ký, hướng dẫn sẽ được gửi.' : 'Đã đổi mật khẩu. Bạn có thể đăng nhập.', modal: 'login' }); return; } const next = new URLSearchParams(location.search).get('next') || ''; location.assign(next.startsWith('/admin') ? next : '/'); });
    }
    async logout() { await this.attempt(async () => { this.close(); await this.api('/auth/logout', 'POST'); location.assign('/'); }); }
    async importGuest() { await this.attempt(async () => { await this.api('/auth/import', 'POST'); this.dismissGuestImportPrompt(); await this.refresh(); this.patch({ notice: 'Đã nhập workspace khách vào tài khoản.' }); }); }
    applyTheme(theme: string) { document.documentElement.dataset.theme = theme === 'system' ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : theme; }
    async preference(key: string, value: string) { this.patch({ [key]: value }); if (key === 'theme') this.applyTheme(value); await this.attempt(() => this.api('/preferences', 'PUT', { ...this.state.workspace.preferences, theme: this.state.theme, locale: this.state.locale })); }
    close() { this.patch({ modal: '', editProvider: {}, probe: null }); }
    destroy() { this.disposed = true; ++this.navigation; clearTimeout(this.polling); clearTimeout(this.fileWatch); clearTimeout(this.guestImportTimer); clearInterval(this.runTimer); window.removeEventListener('popstate', this.pop); }
}
