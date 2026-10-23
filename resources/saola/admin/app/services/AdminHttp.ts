import { App } from '@saolabs/client';

type Method = 'get' | 'post' | 'put' | 'delete';

const options = (timeout: number) => ({
    timeout,
    headers: {
        Accept: 'application/json',
        'X-Sao-Response': 'json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
    },
});

/** Gọi route thao tác của module admin; trả phần `data` của response, lỗi validate gộp thành một câu dễ đọc. */
export async function send<T = any>(method: Method, url: string, body?: Record<string, any>, timeout = 10000): Promise<T> {
    try {
        const response: any = method === 'delete' ? await App.Http.delete(url, options(timeout)) : method === 'get' ? await App.Http.get(url, null, options(timeout)) : await App.Http[method](url, body ?? {}, options(timeout));
        return response.data.data;
    } catch (error: any) {
        const errors = error?.response?.data?.errors;
        const detail = errors && typeof errors === 'object' ? Object.values(errors).flat().join(' ') : '';
        throw new Error(detail || (error instanceof Error ? error.message : 'Không thực hiện được yêu cầu.'));
    }
}

/** Đọc form thành object; checkbox thành boolean (FormData bỏ qua checkbox không chọn). */
export function formValues(form: HTMLFormElement): Record<string, any> {
    const values: Record<string, any> = Object.fromEntries(new FormData(form));
    form.querySelectorAll<HTMLInputElement>('input[type=checkbox][name]').forEach(box => { values[box.name] = box.checked; });
    return values;
}
