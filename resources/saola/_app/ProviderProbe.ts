type Data = Record<string, any>;

/** Separate requests keep a multi-key check within each PHP/request timeout. */
export async function checkProviderKeys(data: Data, savedKeyCount: number, request: (payload: Data) => Promise<Data>, progress: (results: Data[], index: number, count: number) => void, active = () => true): Promise<Data[]> {
    const enteredKeys = new Set(String(data.api_keys || '').split(/[\r\n]+/).map(key => key.trim()).filter(Boolean));
    const count = Math.max(1, enteredKeys.size || (data.clear_keys ? 0 : savedKeyCount));
    const results: Data[] = [];
    for (let index = 1; index <= count && active(); index++) {
        progress([...results], index, count);
        const response = await request({ ...data, key_index: index });
        if (!active()) break;
        results.push(...response.results);
        progress([...results], index, count);
    }
    return results;
}
