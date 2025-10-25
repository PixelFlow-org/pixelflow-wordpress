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
import {
  PixelFlowGeneralOptions,
  PixelFlowClasses,
  UserRole,
} from '@/features/settings/types/settings.types.ts';

/** Constants */
import { productClasses, cartClasses } from '@/features/settings/const/classes.ts';

export interface SaveSettingsOptions {
  generalOptionsOverride?: Partial<PixelFlowGeneralOptions>;
  classOptionsOverride?: Partial<PixelFlowClasses>;
  debugOptionsOverride?: Partial<PixelFlowClasses>;
}

export interface UseSettingsReturn {
  generalOptions: PixelFlowGeneralOptions;
  classOptions: PixelFlowClasses;
  debugOptions: PixelFlowClasses;
  scriptCode: string;
  availableRoles: UserRole[];
  isWooCommerceActive: boolean;
  isLoading: boolean;
  isSaving: boolean;
  error: string | null;
  updateGeneralOption: <K extends keyof PixelFlowGeneralOptions>(
    key: K,
    value: PixelFlowGeneralOptions[K]
  ) => void;
  updateClassOption: <K extends keyof PixelFlowClasses>(key: K, value: PixelFlowClasses[K]) => void;
  updateDebugOption: <K extends keyof PixelFlowClasses>(key: K, value: PixelFlowClasses[K]) => void;
  toggleExcludedRole: (roleKey: string) => void;
  saveSettings: (options?: SaveSettingsOptions) => Promise<void>;
}

const defaultGeneralOptions: PixelFlowGeneralOptions = {
  enabled: 0,
  woo_enabled: 0,
  woo_purchase_tracking: 1,
  debug_enabled: 0,
  excluded_user_roles: [],
  remove_on_uninstall: 0,
};

// Generate default options from all class definitions
const generateDefaultOptions = (defaultValue: 0 | 1): PixelFlowClasses => {
  const allClasses = [...productClasses, ...cartClasses];
  return allClasses.reduce((acc, classItem) => {
    acc[classItem.key] = defaultValue;
    return acc;
  }, {} as PixelFlowClasses);
};

// All class options enabled by default
const defaultClassOptions: PixelFlowClasses = generateDefaultOptions(1);

// All debug options disabled by default
const defaultDebugOptions: PixelFlowClasses = generateDefaultOptions(0);

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
  const [classOptions, setClassOptions] = useState<PixelFlowClasses>(defaultClassOptions);
  const [debugOptions, setDebugOptions] = useState<PixelFlowClasses>(defaultDebugOptions);
  const [scriptCode, setScriptCode] = useState<string>('');
  const [availableRoles, setAvailableRoles] = useState<UserRole[]>([]);
  const [isWooCommerceActive, setIsWooCommerceActive] = useState(false);
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
      setClassOptions({ ...defaultClassOptions, ...wordpressSettings.class_options });
      setDebugOptions({ ...defaultDebugOptions, ...wordpressSettings.debug_options });
      setScriptCode(wordpressSettings.script_code || '');
      setIsWooCommerceActive(wordpressSettings.is_woocommerce_active);
      setIsLoading(false);
    } else {
      setError('Failed to load settings');
      toast('Failed to load settings', { type: 'error' });
      setIsLoading(false);
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
   * Update a single class option
   * @param key - Class option key to update
   * @param value - New value (0 or 1)
   */
  const updateClassOption = <K extends keyof PixelFlowClasses>(
    key: K,
    value: PixelFlowClasses[K]
  ) => {
    setClassOptions((prev) => ({ ...prev, [key]: value }));
  };

  /**
   * Update a single debug option
   * @param key - Debug option key to update
   * @param value - New value (0 or 1)
   */
  const updateDebugOption = <K extends keyof PixelFlowClasses>(
    key: K,
    value: PixelFlowClasses[K]
  ) => {
    setDebugOptions((prev) => ({ ...prev, [key]: value }));
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
      const finalClassOptions = { ...classOptions, ...options?.classOptionsOverride };
      const finalDebugOptions = { ...debugOptions, ...options?.debugOptionsOverride };

      const result = await saveSettingsMutation({
        generalOptions: finalGeneralOptions,
        classOptions: finalClassOptions,
        debugOptions: finalDebugOptions,
      }).unwrap();

      // Update local state with sanitized values from server
      if (result.general_options) {
        setGeneralOptions({ ...defaultGeneralOptions, ...result.general_options });
      }
      if (result.class_options) {
        setClassOptions({ ...defaultClassOptions, ...result.class_options });
      }
      if (result.debug_options) {
        setDebugOptions({ ...defaultDebugOptions, ...result.debug_options });
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
    classOptions,
    debugOptions,
    scriptCode,
    availableRoles,
    isWooCommerceActive,
    isLoading,
    isSaving,
    error,
    updateGeneralOption,
    updateClassOption,
    updateDebugOption,
    toggleExcludedRole,
    saveSettings,
  };
}
