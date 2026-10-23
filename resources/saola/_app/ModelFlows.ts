type Stage = { id: string; value: string; fallback: string };

export function flowPayload(stages: Stage[]) {
    const selection = (value: string) => {
        const [provider_id, ...parts] = value.split('|');
        return { provider_id, model_id: parts.join('|') };
    };
    return { flows: Object.fromEntries(stages.map(stage => [stage.id, stage.value ? {
        ...selection(stage.value), fallbacks: stage.fallback && stage.fallback !== stage.value ? [selection(stage.fallback)] : [],
    } : null])) };
}
