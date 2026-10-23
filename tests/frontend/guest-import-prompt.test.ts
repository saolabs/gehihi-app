import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { GehihiClient } from '../../resources/saola/web/app/services/GehihiClient';

const clients: GehihiClient[] = [];
const bootstrap = { import_available: true, user: { id: 'user-1' }, workspace: { id: 'workspace-1' } };
const client = () => { const c = new GehihiClient(() => {}); clients.push(c); return c; };

beforeEach(() => { vi.useFakeTimers(); sessionStorage.clear(); });
afterEach(() => { clients.splice(0).forEach(c => c.destroy()); vi.useRealTimers(); sessionStorage.clear(); });

it('hides after ten seconds despite repeated bootstrap refreshes, retaining import availability', () => {
    const c = client();
    c.patch(bootstrap);
    expect(c.state.guestImportPrompt).toBe(true);
    vi.advanceTimersByTime(9000);
    c.patch(bootstrap);
    vi.advanceTimersByTime(999);
    expect(c.state.guestImportPrompt).toBe(true);
    vi.advanceTimersByTime(1);
    expect(c.state.guestImportPrompt).toBe(false);
    expect(c.state.import_available).toBe(true);
    c.patch(bootstrap);
    expect(c.state.guestImportPrompt).toBe(false);
    const reloaded = client();
    reloaded.patch(bootstrap);
    expect(reloaded.state.guestImportPrompt).toBe(false);
    expect(reloaded.state.import_available).toBe(true);
});

it('allows manual dismissal without hiding import for a different account and clears timers on teardown', () => {
    const c = client();
    c.patch(bootstrap);
    c.dismissGuestImportPrompt();
    expect(c.state.guestImportPrompt).toBe(false);
    expect(c.state.import_available).toBe(true);
    expect(vi.getTimerCount()).toBe(0);
    c.patch({ ...bootstrap, user: { id: 'user-2' } });
    expect(c.state.guestImportPrompt).toBe(true);
    c.destroy();
    expect(vi.getTimerCount()).toBe(0);
});
