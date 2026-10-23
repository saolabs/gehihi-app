import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { GehihiClient } from '../../resources/saola/web/app/services/GehihiClient';

let client: GehihiClient;
const run = { id: 'run-1', status: 'queued', created_at: '2026-10-23T09:00:00Z' };
beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-23T09:00:59Z'));
    client = new GehihiClient(() => {});
});
afterEach(() => { client.destroy(); vi.useRealTimers(); });

it('restores elapsed time from the server timestamp and advances across polling updates', () => {
    client.patch({ run });
    expect(client.state.runElapsed).toBe('0:59');
    vi.advanceTimersByTime(600);
    client.patch({ run: { ...run, status: 'running' } });
    vi.advanceTimersByTime(400);
    expect(client.state.runElapsed).toBe('1:00');
    expect(vi.getTimerCount()).toBe(1);
    vi.advanceTimersByTime(2000);
    expect(client.state.runElapsed).toBe('1:02');
});

it('stops on completion, clears on navigation, and disposes an active timer', () => {
    client.patch({ run });
    client.patch({ run: { ...run, status: 'completed', finished_at: '2026-10-23T09:00:58Z' } });
    expect(client.state.runActive).toBe(false);
    expect(client.state.runElapsed).toBe('0:58');
    expect(vi.getTimerCount()).toBe(0);
    vi.advanceTimersByTime(5000);
    expect(client.state.runElapsed).toBe('0:58');
    client.patch({ run });
    client.newChat();
    expect(client.state.runActive).toBe(false);
    expect(vi.getTimerCount()).toBe(0);
    client.patch({ run });
    client.destroy();
    expect(vi.getTimerCount()).toBe(0);
});
