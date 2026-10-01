/**
 * @fileoverview Form events API
 * @description RTK Query endpoints for the forms read and save routes (WordPress admin-ajax)
 */

/** API */
import { authApi } from '@pixelflow-org/plugin-core/dist';

/** Utils */
import { getWordPressAjaxConfig } from '@/features/settings/utils/wordpress-config';

/** Types */
import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';
import type { FormSettingsPatch, FormsListing } from '@/features/forms/types';

const { nonce, ajaxUrl } = getWordPressAjaxConfig();

/**
 * POSTs one admin-ajax action and unwraps WordPress's JSON envelope.
 * @param action - admin-ajax action
 * @param extra - Extra form fields
 * @param failure - Message when the route refuses
 */
async function post<T>(
  action: string,
  extra: Record<string, string>,
  failure: string
): Promise<{ data: T } | { error: FetchBaseQueryError }> {
  try {
    const formData = new FormData();
    formData.append('action', action);
    formData.append('nonce', nonce);
    Object.entries(extra).forEach(([key, value]) => formData.append(key, value));

    const response = await fetch(ajaxUrl, { method: 'POST', body: formData });
    const data = await response.json();

    if (data.success) {
      return { data: data.data as T };
    }
    return {
      error: {
        status: 'CUSTOM_ERROR',
        error: data.data?.message || failure,
      } as FetchBaseQueryError,
    };
  } catch {
    return { error: { status: 'FETCH_ERROR', error: failure } as FetchBaseQueryError };
  }
}

const formsApi = authApi.injectEndpoints({
  endpoints: (builder) => ({
    /**
     * Lists the supported form plugins, the site's forms and each form's computed state.
     */
    getForms: builder.query<FormsListing, void>({
      queryFn: () => post<FormsListing>('pixelflow_get_forms', {}, 'Failed to load forms'),
    }),

    /**
     * Saves what a person changed and returns the fresh listing.
     */
    saveFormSettings: builder.mutation<FormsListing, FormSettingsPatch>({
      queryFn: (patch) =>
        post<FormsListing>(
          'pixelflow_save_form_settings',
          { forms: JSON.stringify(patch) },
          'Failed to save form settings'
        ),
    }),
  }),
  overrideExisting: false,
});

export const { useLazyGetFormsQuery, useSaveFormSettingsMutation } = formsApi;
