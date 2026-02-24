/**
 * Returns the CSRF token from the meta tag.
 */
export function useCsrf(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
}
