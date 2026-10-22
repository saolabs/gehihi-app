type Data = Record<string, any>;

export const ADMIN_TABS = ['overview', 'providers', 'users', 'limits'] as const;

const LIMIT_GROUPS = [
    { title: 'Mỗi workspace trong ngày', fields: [['daily_runs', 'Số lượt', 'lượt'], ['daily_tokens', 'Token', 'token'], ['concurrency', 'Lượt chạy đồng thời', 'lượt']] },
    { title: 'Ngân sách mỗi lượt', fields: [['chat_tokens', 'Token cho chat', 'token'], ['research_tokens', 'Token cho Research', 'token']] },
    { title: 'Tra cứu web', fields: [['research_quick_queries', 'Truy vấn', 'truy vấn'], ['research_quick_pages', 'Trang đọc', 'trang'], ['research_quick_rounds', 'Vòng', 'vòng'], ['research_quick_seconds', 'Thời gian tối đa', 'giây']] },
    { title: 'Nghiên cứu sâu', fields: [['research_deep_queries', 'Truy vấn', 'truy vấn'], ['research_deep_pages', 'Trang đọc', 'trang'], ['research_deep_rounds', 'Vòng', 'vòng'], ['research_deep_seconds', 'Thời gian tối đa', 'giây']] },
    { title: 'Khách chưa đăng nhập', fields: [['guest_days', 'Thời hạn lưu dữ liệu', 'ngày']] },
];

const MODE_LABEL: Data = { chat: 'Trò chuyện', quick: 'Tra cứu web', deep: 'Nghiên cứu sâu' };

export const num = (n: number) => Number(n || 0).toLocaleString('vi-VN');
const compact = (n: number) => (n >= 1e6 ? (n / 1e6).toFixed(1).replace('.0', '') + 'M' : n >= 1e3 ? (n / 1e3).toFixed(1).replace('.0', '') + 'K' : String(n || 0));
const when = (value?: string) => (value ? new Date(value).toLocaleString('vi-VN', { dateStyle: 'short', timeStyle: 'short' }) : '—');

/** Nạp dữ liệu trang quản trị; mọi chuỗi hiển thị được định dạng sẵn ở đây để template chỉ đọc thuộc tính. */
export class AdminClient {
    state: Data = { tab: 'overview', loading: true, busy: false, error: '', notice: '', me: null, days: 30, overview: null, providers: null, users: [], q: '', limits: [], mobileNav: false };
    private onPop = () => this.go(this.tabFromPath(), false);

    constructor(private emit: (state: Data) => void) {
        addEventListener('popstate', this.onPop);
    }

    destroy() { removeEventListener('popstate', this.onPop); }

    patch(values: Data) { Object.assign(this.state, values); this.emit({ ...this.state }); }

    async api(path: string, method = 'GET', body?: any): Promise<any> {
        const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        if (token) headers['X-CSRF-TOKEN'] = token;
        if (body) headers['Content-Type'] = 'application/json';
        const response = await fetch('/api' + path, { method, credentials: 'same-origin', headers, body: body ? JSON.stringify(body) : undefined });
        const data = await response.json().catch(() => ({ message: 'Phiên làm việc đã thay đổi. Hãy tải lại trang.' }));
        if (!response.ok || data.success === false) throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Không thực hiện được yêu cầu.');
        return data.data;
    }

    async attempt(fn: () => Promise<any>) {
        this.patch({ error: '', busy: true });
        try { return await fn(); } catch (e) { this.patch({ error: e instanceof Error ? e.message : 'Có lỗi xảy ra.' }); return null; } finally { this.patch({ busy: false }); }
    }

    tabFromPath(): string {
        const tab = location.pathname.replace(/^\/admin\/?/, '').split('/')[0];
        return (ADMIN_TABS as readonly string[]).includes(tab) ? tab : 'overview';
    }

    async load() {
        await this.attempt(async () => {
            const boot = await this.api('/bootstrap');
            const theme = boot.workspace?.preferences?.theme || 'system';
            document.documentElement.dataset.theme = theme === 'system' ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : theme;
            this.patch({ me: boot.user ? { ...boot.user, initial: (boot.user.name || '?').charAt(0).toUpperCase() } : null });
            await this.go(this.tabFromPath(), false);
        });
        this.patch({ loading: false });
    }

    async go(tab: string, push = true) {
        if (push) history.pushState({}, '', tab === 'overview' ? '/admin' : '/admin/' + tab);
        this.patch({ tab, mobileNav: false, error: '', notice: '' });
        await this.attempt(async () => {
            if (tab === 'overview') this.patch({ overview: this.shapeOverview(await this.api('/admin/overview?days=' + this.state.days)) });
            if (tab === 'providers') this.patch({ providers: this.shapeProviders(await this.api('/admin/providers')) });
            if (tab === 'users') await this.searchUsers(this.state.q);
            if (tab === 'limits') this.patch({ limits: this.shapeLimits(await this.api('/admin/limits')) });
        });
    }

    async setDays(days: number) { this.patch({ days }); await this.go('overview', false); }

    async searchUsers(q: string) {
        this.patch({ q });
        const data = await this.api('/admin/users?q=' + encodeURIComponent(q));
        this.patch({ users: data.users.map((u: Data) => ({ ...u, initial: (u.name || '?').charAt(0).toUpperCase(), joined: when(u.created_at), runs30: num(u.runs_30d), tokens30: num(u.tokens_30d), isMe: u.id === this.state.me?.id })) });
    }

    async toggleAdmin(user: Data) {
        const grant = !user.is_admin;
        if (!confirm(grant ? `Cấp quyền quản trị cho ${user.email}?` : `Thu quyền quản trị của ${user.email}?`)) return;
        await this.attempt(async () => {
            await this.api('/admin/users/' + user.id, 'PATCH', { is_admin: grant });
            await this.searchUsers(this.state.q);
            this.patch({ notice: grant ? 'Đã cấp quyền quản trị.' : 'Đã thu quyền quản trị.' });
        });
    }

    async saveLimits(event: Event) {
        event.preventDefault();
        const form = new FormData(event.currentTarget as HTMLFormElement);
        const limits = Object.fromEntries([...form.entries()].map(([k, v]) => [k, Number(v)]));
        await this.attempt(async () => {
            this.patch({ limits: this.shapeLimits(await this.api('/admin/limits', 'PUT', { limits })), notice: 'Đã lưu hạn mức. Áp dụng ngay cho lượt chạy mới.' });
        });
    }

    async resetLimits() {
        if (!confirm('Đưa mọi hạn mức về giá trị mặc định?')) return;
        const defaults = Object.fromEntries(this.state.limits.flatMap((g: Data) => g.fields.map((f: Data) => [f.key, f.defaultValue])));
        await this.attempt(async () => {
            this.patch({ limits: this.shapeLimits(await this.api('/admin/limits', 'PUT', { limits: defaults })), notice: 'Đã khôi phục hạn mức mặc định.' });
        });
    }

    private shapeOverview(o: Data) {
        const t = o.totals;
        const max = Math.max(1, ...o.series.map((d: Data) => d.runs));
        const step = o.series.length > 31 ? 14 : o.series.length > 7 ? 5 : 1;
        const maxModel = Math.max(1, ...o.models.map((m: Data) => m.runs));
        const totalModes = Math.max(1, o.modes.reduce((s: number, m: Data) => s + m.runs, 0));
        return {
            stats: [
                { id: 'runs', label: 'Lượt chạy', value: num(t.runs), hint: `${num(t.runs_today)} hôm nay`, icon: '#i-chat' },
                { id: 'tokens', label: 'Token đã dùng', value: compact(t.tokens), hint: `${compact(t.tokens_today)} hôm nay`, icon: '#i-gauge' },
                { id: 'failed', label: 'Tỉ lệ lỗi', value: t.runs ? (t.failed / t.runs * 100).toFixed(1).replace('.0', '') + '%' : '0%', hint: `${num(t.failed)} lượt lỗi`, icon: '#i-alert', warn: t.runs > 0 && t.failed / t.runs > 0.1 },
                { id: 'users', label: 'Người dùng', value: num(t.users), hint: `${num(t.guest_workspaces)} khách đang hoạt động`, icon: '#i-users' },
            ],
            active: t.active,
            conversations: num(t.conversations),
            admins: num(t.admins),
            peak: num(max),
            bars: o.series.map((d: Data, i: number) => {
                const date = new Date(d.date + 'T00:00:00');
                const label = date.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit' });
                return { key: d.date, height: Math.max(d.runs ? 3 : 0, d.runs / max * 100), tick: i % step === 0 || i === o.series.length - 1 ? label : '', tip: `${label}: ${num(d.runs)} lượt · ${num(d.tokens)} token${d.failed ? ` · ${d.failed} lỗi` : ''}` };
            }),
            modes: o.modes.map((m: Data) => ({ ...m, label: MODE_LABEL[m.mode], count: num(m.runs), pct: (m.runs / totalModes * 100).toFixed(0) })),
            models: o.models.map((m: Data, i: number) => ({ ...m, id: 'm' + i, count: num(m.runs), tokensLabel: compact(m.tokens), pct: m.runs / maxModel * 100 })),
            failures: o.failures.map((f: Data) => ({ ...f, meta: [MODE_LABEL[f.mode], f.model, when(f.created_at)].filter(Boolean).join(' · ') })),
        };
    }

    private shapeProviders(p: Data) {
        return {
            personal: p.personal,
            system: p.system.map((x: Data) => {
                const status = !x.enabled ? 'off' : x.key_count && x.cooling_keys >= x.key_count ? 'down' : x.cooling_keys || (x.runs_7d && x.failed_7d / x.runs_7d > 0.2) ? 'warn' : 'ok';
                return { ...x, status, statusLabel: { off: 'Đã tắt', down: 'Mọi key đang tạm khóa', warn: 'Cần chú ý', ok: 'Hoạt động' }[status], kindLabel: x.kind === 'search' ? 'Tìm kiếm' : 'Mô hình AI', runs: num(x.runs_7d), failRate: x.runs_7d ? (x.failed_7d / x.runs_7d * 100).toFixed(0) + '%' : '—', tested: when(x.tested_at), keyLabel: x.cooling_keys ? `${x.key_count} (${x.cooling_keys} tạm khóa)` : String(x.key_count) };
            }),
        };
    }

    private shapeLimits(data: Data) {
        return LIMIT_GROUPS.map((g, i) => ({ id: 'g' + i, title: g.title, fields: g.fields.map(([key, label, unit]) => ({ key, label, unit, value: data.limits[key], defaultValue: data.defaults[key], changed: data.limits[key] !== data.defaults[key], hint: `Mặc định ${num(data.defaults[key])}` })) }));
    }
}
