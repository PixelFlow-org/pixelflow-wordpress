import pixelFlowApi from '@pixelflow-org/plugin-core/dist/api';
import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';
import type { SettingsResponse, SaveSettingsRequest } from '../types/settings.types';

const wordpressSettingsApi = pixelFlowApi.injectEndpoints({
  endpoints: (builder) => ({
    // Get all settings
    getSettings: builder.query<SettingsResponse, void>({
      queryFn: async () => {
        try {
          const formData = new FormData();
          formData.append('action', 'pixelflow_get_settings');
          formData.append('nonce', window.pixelflowSettings?.nonce || '');

          const response = await fetch(window.pixelflowSettings?.ajax_url || '', {
            method: 'POST',
            body: formData,
          });

          const data = await response.json();

          if (data.success) {
            return { data: data.data as SettingsResponse };
          }
          return {
            error: {
              status: 'CUSTOM_ERROR',
              error: data.data?.message || 'Failed to load settings',
            } as FetchBaseQueryError,
          };
        } catch (error) {
          return {
            error: {
              status: 'FETCH_ERROR',
              error: 'Network error while loading settings',
            } as FetchBaseQueryError,
          };
        }
      },
    }),

    // Save settings
    saveSettings: builder.mutation<SettingsResponse, SaveSettingsRequest>({
      queryFn: async ({ generalOptions, classOptions, debugOptions }) => {
        try {
          const formData = new FormData();
          formData.append('action', 'pixelflow_save_settings');
          formData.append('nonce', window.pixelflowSettings?.nonce || '');

          Object.entries(generalOptions).forEach(([key, value]) => {
            if (key === 'excluded_user_roles' && Array.isArray(value)) {
              // Send array as comma-separated string
              formData.append(`general_options[${key}]`, value.join(','));
            } else {
              formData.append(`general_options[${key}]`, String(value));
            }
          });

          Object.entries(classOptions).forEach(([key, value]) => {
            formData.append(`class_options[${key}]`, String(value));
          });

          Object.entries(debugOptions).forEach(([key, value]) => {
            formData.append(`debug_options[${key}]`, String(value));
          });

          const response = await fetch(window.pixelflowSettings?.ajax_url || '', {
            method: 'POST',
            body: formData,
          });

          const data = await response.json();

          if (data.success) {
            return { data: data.data as SettingsResponse };
          }
          return {
            error: {
              status: 'CUSTOM_ERROR',
              error: data.data?.message || 'Failed to save settings',
            } as FetchBaseQueryError,
          };
        } catch (error) {
          return {
            error: {
              status: 'FETCH_ERROR',
              error: 'Network error while saving settings',
            } as FetchBaseQueryError,
          };
        }
      },
    }),

    // Save script code
    saveScriptCode: builder.mutation<{ script_code: string }, string>({
      queryFn: async (scriptCode) => {
        try {
          const formData = new FormData();
          formData.append('action', 'pixelflow_save_script_code');
          formData.append('nonce', window.pixelflowSettings?.nonce || '');
          formData.append('script_code', scriptCode);

          const response = await fetch(window.pixelflowSettings?.ajax_url || '', {
            method: 'POST',
            body: formData,
          });

          const data = await response.json();

          if (data.success) {
            return { data: data.data as { script_code: string } };
          }
          return {
            error: {
              status: 'CUSTOM_ERROR',
              error: data.data?.message || 'Failed to save script code',
            } as FetchBaseQueryError,
          };
        } catch (error) {
          return {
            error: {
              status: 'FETCH_ERROR',
              error: 'Network error while saving script',
            } as FetchBaseQueryError,
          };
        }
      },
    }),

    // Remove script code
    removeScriptCode: builder.mutation<{ message: string }, void>({
      queryFn: async () => {
        try {
          const formData = new FormData();
          formData.append('action', 'pixelflow_remove_script_code');
          formData.append('nonce', window.pixelflowSettings?.nonce || '');

          const response = await fetch(window.pixelflowSettings?.ajax_url || '', {
            method: 'POST',
            body: formData,
          });

          const data = await response.json();

          if (data.success) {
            return { data: data.data as { message: string } };
          }
          return {
            error: {
              status: 'CUSTOM_ERROR',
              error: data.data?.message || 'Failed to remove script code',
            } as FetchBaseQueryError,
          };
        } catch (error) {
          return {
            error: {
              status: 'FETCH_ERROR',
              error: 'Network error while removing script',
            } as FetchBaseQueryError,
          };
        }
      },
    }),
  }),
  overrideExisting: false,
});

export const {
  useGetSettingsQuery,
  useSaveSettingsMutation,
  useSaveScriptCodeMutation,
  useRemoveScriptCodeMutation,
} = wordpressSettingsApi;

