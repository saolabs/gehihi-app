import { afterEach, expect, it, vi } from 'vitest';
import { GehihiClient } from '../../resources/saola/web/app/services/GehihiClient';

let client: GehihiClient;
afterEach(() => { client?.destroy(); vi.restoreAllMocks(); });

it('retains unsaved stage choices on save failure and sends only explicit fallbacks', async () => {
    client = new GehihiClient(() => {});
    const stages = ['plan', 'assess', 'report'].map(id => ({ id, value: '', fallback: '' }));
    const api = vi.spyOn(client, 'api').mockResolvedValue({ stages, options: [] });
    await client.modelFlows();
    const event = (value: string) => ({ target: { value } }) as unknown as Event;
    client.changeFlow('plan', 'value', event('provider-a|planning-model'));
    client.changeFlow('plan', 'fallback', event('provider-b|backup-model'));
    client.changeFlow('report', 'value', event('provider-c|report-model'));
    api.mockRejectedValueOnce(new Error('Save failed'));
    await client.saveFlows({ preventDefault() {} } as Event);
    expect(client.state.flowStages[0]).toMatchObject({ value: 'provider-a|planning-model', fallback: 'provider-b|backup-model' });
    expect(client.state.error).toBe('Save failed');
    expect(api).toHaveBeenLastCalledWith('/model-flows', 'PUT', { flows: {
        plan: { provider_id: 'provider-a', model_id: 'planning-model', fallbacks: [{ provider_id: 'provider-b', model_id: 'backup-model' }] },
        assess: null,
        report: { provider_id: 'provider-c', model_id: 'report-model', fallbacks: [] },
    } });
    client.changeFlow('plan', 'value', event(''));
    expect(client.state.flowStages[0].fallback).toBe('');
});

it('loads fresh workflow settings on reopen and resets all stages to inheritance', async () => {
    client = new GehihiClient(() => {});
    const api = vi.spyOn(client, 'api').mockResolvedValue({ stages: [{ id: 'plan', value: 'a|model', fallback: 'b|model' }], options: [{ value: 'a|model', label: 'A' }] });
    await client.modelFlows();
    client.resetFlows();
    expect(client.state.flowStages).toEqual([{ id: 'plan', value: '', fallback: '', unavailable: false, fallback_unavailable: false }]);
    client.close();
    await client.modelFlows();
    expect(api).toHaveBeenCalledTimes(2);
    expect(client.state.flowStages[0].value).toBe('a|model');
});
