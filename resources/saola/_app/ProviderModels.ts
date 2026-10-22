export type ModelFields = { models?: string; default_model?: string; vision_models?: string; reasoning_models?: string };
export type ModelField = 'models' | 'vision_models' | 'reasoning_models';

export function modelIds(value = ''): string[] {
    return [...new Set(value.split(/[\n,]+/).map(id => id.trim()).filter(Boolean))];
}

/** Keep a saved model visible even when it disappears from a refreshed catalog. */
export function modelChoices(catalog: string[] | null, fields: ModelFields, filter = '') {
    const selected = modelIds(fields.models);
    const vision = modelIds(fields.vision_models), reasoning = modelIds(fields.reasoning_models);
    return [...new Set([...selected, ...(catalog || [])])]
        .filter(id => id.toLowerCase().includes(filter.trim().toLowerCase()))
        .map(id => ({ id, selected: selected.includes(id), stale: catalog !== null && !catalog.includes(id), vision: vision.includes(id), reasoning: reasoning.includes(id) }));
}

/** Removing an allowed model also removes its default/capability references. */
export function toggleModel<T extends ModelFields>(fields: T, field: ModelField, id: string, checked: boolean): T {
    const ids = modelIds(fields[field]);
    const next = { ...fields, [field]: (checked ? [...new Set([...ids, id])] : ids.filter(value => value !== id)).join('\n') };
    const allowed = modelIds(next.models);
    next.vision_models = modelIds(next.vision_models).filter(value => allowed.includes(value)).join('\n');
    next.reasoning_models = modelIds(next.reasoning_models).filter(value => allowed.includes(value)).join('\n');
    if (!allowed.includes(next.default_model || '')) next.default_model = '';
    return next;
}
