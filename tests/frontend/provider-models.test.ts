import { afterEach, describe, expect, it, vi } from 'vitest';
import { GehihiClient } from '../../resources/saola/web/app/services/GehihiClient';
import { modelChoices, toggleModel } from '../../resources/saola/_app/ProviderModels';

const clients: GehihiClient[] = [];
afterEach(() => { clients.splice(0).forEach(c => c.destroy()); vi.restoreAllMocks(); });

describe('provider model editor', () => {
    it('omits preset URL overrides and leaves custom URLs empty when switching types', () => {
        const c = new GehihiClient(() => {}); clients.push(c);
        c.patch({ providerTypes: { google: { kind: 'llm' }, openai_compatible: { kind: 'llm', base_url_editable: true } } });
        c.editProvider();
        document.body.innerHTML = '<form><input name="name" value="Mine"><select name="type"><option value="google">Google</option><option value="openai_compatible">Custom</option></select><input name="base_url" value="https://stale.invalid/v1"><input name="models" value="custom-model"></form>';
        const form = document.querySelector('form')!;
        expect(c.providerData(form)).not.toHaveProperty('base_url');
        const select = form.querySelector('select')!;
        select.value = 'openai_compatible';
        c.changeProviderType({ target: select } as any);
        expect(c.state.editProvider).toMatchObject({ type: 'openai_compatible', base_url: '', models: '', name: 'Mine' });
        form.querySelector<HTMLInputElement>('[name="base_url"]')!.value = 'https://custom.example/v1';
        expect(c.providerData(form)).toMatchObject({ base_url: 'https://custom.example/v1', models: 'custom-model' });
    });

    it('retains models missing from the refreshed catalog and filters without losing selection', () => {
        const fields = { models: 'saved\nremote', default_model: 'saved', vision_models: 'saved', reasoning_models: 'remote' };
        expect(modelChoices(['remote', 'new'], fields, 'SAVED')).toEqual([{ id: 'saved', selected: true, stale: true, vision: true, reasoning: false }]);
        const next = toggleModel(fields, 'models', 'saved', false);
        expect(next).toEqual({ models: 'remote', default_model: '', vision_models: '', reasoning_models: 'remote' });
    });

    it('loads catalog choices without enabling every remote model or losing form edits', async () => {
        const c = new GehihiClient(() => {}); clients.push(c);
        c.editProvider({ id: 'p1', name: 'Saved', type: 'google_vertex', models: ['old'], default_model: 'old', capabilities: {}, key_count: 1 });
        document.body.innerHTML = '<form><input name="name" value="Edited"><input name="type" value="google_vertex"><input name="models" value="old"><input name="default_model" value="old"><input name="api_keys" value=""><button type="button">Load</button></form>';
        vi.spyOn(c, 'api').mockResolvedValue(['new']);
        await c.probe({ currentTarget: document.querySelector('button') } as any, 'discover');
        expect(c.state.editProvider).toMatchObject({ name: 'Edited', models: 'old', default_model: 'old', key_count: 1 });
        expect(c.state.providerModelChoices).toMatchObject([{ id: 'old', selected: true, stale: true }, { id: 'new', selected: false }]);
    });
});
