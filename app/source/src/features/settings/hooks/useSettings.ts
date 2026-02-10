/**
 * @fileoverview Settings management hook
 * @description Custom hook for managing PixelFlow plugin settings with optimistic updates
 */

/** External libraries */
import { useState, useEffect } from 'react';
import { toast } from 'react-toastify';

/** API */
import { useSaveSettingsMutation } from '@/features/settings/api';

/** Utils */
import { wordpressSettings } from '@/features/settings/utils/wordpress-config';

/** Types */
import { PixelFlowGeneralOptions, UserRole } from '@/features/settings/types/settings.types.ts';

export interface SaveSettingsOptions {
  generalOptionsOverride?: Partial<PixelFlowGeneralOptions>;
}

export interface UseSettingsReturn {
  generalOptions: PixelFlowGeneralOptions;
  scriptCode: string;
  availableRoles: UserRole[];
  isWooCommerceActive: boolean;
  isConfigured: boolean;
  isLoading: boolean;
  isSaving: boolean;
  error: string | null;
  updateGeneralOption: <K extends keyof PixelFlowGeneralOptions>(
    key: K,
    value: PixelFlowGeneralOptions[K]
  ) => void;
  toggleExcludedRole: (roleKey: string) => void;
  saveSettings: (options?: SaveSettingsOptions) => Promise<void>;
}

const defaultGeneralOptions: PixelFlowGeneralOptions = {
  enabled: 0,
  woo_enabled: 0,
  excluded_user_roles: [],
  remove_on_uninstall: 0,
};

/**
 * Custom hook for managing WordPress plugin settings
 * @description Manages PixelFlow settings with optimistic UI updates, state synchronization,
 * and prevents concurrent save operations to avoid race conditions
 * @returns {UseSettingsReturn} Settings state and mutation functions
 */
export function useSettings(): UseSettingsReturn {
  /** API */
  // RTK Mutation hook (queries are skipped, we use wordpressSettings utility for initial data)
  const [saveSettingsMutation] = useSaveSettingsMutation();

  /** Local state */
  // Form state for optimistic updates
  const [generalOptions, setGeneralOptions] =
    useState<PixelFlowGeneralOptions>(defaultGeneralOptions);
  const [scriptCode, setScriptCode] = useState<string>('');
  const [availableRoles, setAvailableRoles] = useState<UserRole[]>([]);
  const [isWooCommerceActive, setIsWooCommerceActive] = useState(false);
  const [isConfigured, setIsConfigured] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  // Prevent concurrent saves to avoid race conditions
  const [isSaving, setIsSaving] = useState(false);

  /** Effects */
  /**
   * Load initial settings from WordPress globals
   * @description Initializes settings state from wordpressSettings utility
   * which extracts data from window.pixelflowSettings once on module load
   */
  useEffect(() => {
    if (wordpressSettings) {
      const generalOpts = { ...defaultGeneralOptions, ...wordpressSettings.general_options };
      // Ensure excluded_user_roles is always an array
      if (!Array.isArray(generalOpts.excluded_user_roles)) {
        generalOpts.excluded_user_roles = [];
      }
      setGeneralOptions(generalOpts);
      setScriptCode(wordpressSettings.script_code || '');
      setIsWooCommerceActive(wordpressSettings.is_woocommerce_active);
      setIsLoading(false);
    } else {
      setError('Failed to load settings');
      toast('Failed to load settings', { type: 'error' });
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    const isConfiguredEl = document.getElementById('pixelflow-configured');
    if (isConfiguredEl) {
      const isConfigured = isConfiguredEl.getAttribute('value');
      setIsConfigured(isConfigured === '1');
    }
  }, []);

  // Parse available user roles from hidden input
  useEffect(() => {
    const rolesInput = document.getElementById('pixelflow-user-roles');
    if (rolesInput) {
      const rolesString = rolesInput.getAttribute('value') || '';
      // Parse: "administrator|Administrator,editor|Editor,..."
      const parsedRoles: UserRole[] = rolesString
        .split(',')
        .filter((role) => role.trim())
        .map((role) => {
          const [key, label] = role.split('|');
          return { key: key.trim(), label: label.trim() };
        });
      setAvailableRoles(parsedRoles);
    }
  }, []);

  /** State update methods */
  /**
   * Update a single general option
   * @param key - Option key to update
   * @param value - New value for the option
   */
  const updateGeneralOption = <K extends keyof PixelFlowGeneralOptions>(
    key: K,
    value: PixelFlowGeneralOptions[K]
  ) => {
    setGeneralOptions((prev) => ({ ...prev, [key]: value }));
  };

  /**
   * Toggle a user role in the excluded roles list
   * @param roleKey - Role key to toggle
   */
  const toggleExcludedRole = (roleKey: string) => {
    setGeneralOptions((prev) => {
      const current = prev.excluded_user_roles || [];
      const updated = current.includes(roleKey)
        ? current.filter((r) => r !== roleKey) // Remove role
        : [...current, roleKey]; // Add role
      return { ...prev, excluded_user_roles: updated };
    });
  };

  /**
   * Save settings to WordPress backend
   * @description Persists settings with race condition prevention and server-side validation.
   * Updates local state with sanitized values from server response.
   * @param options - Optional overrides to merge with current state before saving
   * @returns Promise that resolves when save is complete
   */
  const saveSettings = async (options?: SaveSettingsOptions) => {
    // Prevent concurrent saves
    if (isSaving) {
      console.warn('Save already in progress, skipping...');
      return;
    }

    try {
      setIsSaving(true);

      // Merge current state with overrides
      const finalGeneralOptions = { ...generalOptions, ...options?.generalOptionsOverride };

      const result = await saveSettingsMutation({
        generalOptions: finalGeneralOptions,
      }).unwrap();

      // Update local state with sanitized values from server
      if (result.general_options) {
        setGeneralOptions({ ...defaultGeneralOptions, ...result.general_options });
      }

      toast('Settings saved successfully', { type: 'info' });
    } catch (err) {
      toast('Failed to save settings', { type: 'error' });
      console.error('Save settings error:', err);
    } finally {
      setIsSaving(false);
    }
  };

  return {
    generalOptions,
    scriptCode,
    availableRoles,
    isWooCommerceActive,
    isConfigured,
    isLoading,
    isSaving,
    error,
    updateGeneralOption,
    toggleExcludedRole,
    saveSettings,
  };
}
