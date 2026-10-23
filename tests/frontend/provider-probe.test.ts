import { expect, it, vi } from 'vitest';
import { checkProviderKeys } from '../../resources/saola/_app/ProviderProbe';

it('checks entered keys in separate requests, deduplicates and publishes each result', async () => {
    const request = vi.fn(async payload => ({ results: [{ key: payload.key_index, ok: payload.key_index === 2 }] }));
    const progress = vi.fn();
    const results = await checkProviderKeys({ api_keys: 'first\n second\nfirst\n' }, 10, request, progress);
    expect(request.mock.calls.map(([payload]) => payload.key_index)).toEqual([1, 2]);
    expect(progress.mock.calls.map(([results]) => results.length)).toEqual([0, 1, 1, 2]);
    expect(results).toEqual([{ key: 1, ok: false }, { key: 2, ok: true }]);
});

it('checks stored keys by index without reading or returning their secrets', async () => {
    const request = vi.fn(async payload => ({ results: [{ key: payload.key_index, ok: true }] }));
    await checkProviderKeys({ id: 'saved-provider', api_keys: '' }, 3, request, () => {});
    expect(request.mock.calls.map(([payload]) => payload.key_index)).toEqual([1, 2, 3]);
    expect(request.mock.calls.every(([payload]) => payload.api_keys === '' && payload.id === 'saved-provider')).toBe(true);
});

it('retains completed results on request failure and stops when the form is closed', async () => {
    const progress = vi.fn();
    const request = vi.fn().mockResolvedValueOnce({ results: [{ key: 1, ok: true }] }).mockRejectedValueOnce(new Error('network'));
    await expect(checkProviderKeys({ api_keys: 'first\nsecond\nthird' }, 0, request, progress)).rejects.toThrow('network');
    expect(progress.mock.calls.at(-1)?.[0]).toEqual([{ key: 1, ok: true }]);
    let active = true;
    const closed = vi.fn(async () => { active = false; return { results: [{ key: 1, ok: true }] }; });
    await checkProviderKeys({ api_keys: 'first\nsecond' }, 0, closed, () => {}, () => active);
    expect(closed).toHaveBeenCalledTimes(1);
});
