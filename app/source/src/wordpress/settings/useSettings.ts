import { useState, useEffect } from 'react';
import { PixelFlowGeneralOptions, PixelFlowClasses, UserRole } from './settings.types';
import { toast } from 'react-toastify';
import { productClasses } from '@/wordpress/settings/classes.ts';

export interface SaveSettingsOptions {
  generalOptionsOverride?: Partial<PixelFlowGeneralOptions>;
  classOptionsOverride?: Partial<PixelFlowClasses>;
  debugOptionsOverride?: Partial<PixelFlowClasses>;
}

interface UseSettingsReturn {
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
  debug_enabled: 0,
  excluded_user_roles: [],
};

const defaultClassOptions: PixelFlowClasses = {
  woo_class_product_container: 1,
  woo_class_product_name: 1,
  woo_class_product_price: 1,
  woo_class_product_quantity: 1,
  woo_class_product_add_to_cart: 1,
  woo_class_cart_table: 1,
  woo_class_cart_item: 1,
  woo_class_cart_price: 1,
  woo_class_cart_checkout_button: 1,
  woo_class_cart_product_name: 1,
  woo_class_checkout_form: 1,
  woo_class_checkout_item: 1,
  woo_class_checkout_item_name: 1,
  woo_class_checkout_item_price: 1,
  woo_class_checkout_item_quantity: 1,
  woo_class_checkout_total: 1,
  woo_class_checkout_place_order: 1,
};

const defaultDebugOptions: PixelFlowClasses = {
  woo_class_product_container: 0,
  woo_class_product_name: 0,
  woo_class_product_price: 0,
  woo_class_product_quantity: 0,
  woo_class_product_add_to_cart: 0,
  woo_class_cart_table: 0,
  woo_class_cart_item: 0,
  woo_class_cart_price: 0,
  woo_class_cart_checkout_button: 0,
  woo_class_cart_product_name: 0,
  woo_class_checkout_form: 0,
  woo_class_checkout_item: 0,
  woo_class_checkout_item_name: 0,
  woo_class_checkout_item_price: 0,
  woo_class_checkout_item_quantity: 0,
  woo_class_checkout_total: 0,
  woo_class_checkout_place_order: 0,
};

export function useSettings(): UseSettingsReturn {
  const [generalOptions, setGeneralOptions] =
    useState<PixelFlowGeneralOptions>(defaultGeneralOptions);
  const [classOptions, setClassOptions] = useState<PixelFlowClasses>(defaultClassOptions);
  const [debugOptions, setDebugOptions] = useState<PixelFlowClasses>(defaultDebugOptions);
  const [scriptCode, setScriptCode] = useState<string>('');
  const [availableRoles, setAvailableRoles] = useState<UserRole[]>([]);
  const [isWooCommerceActive, setIsWooCommerceActive] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

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

  // Load settings on mount
  useEffect(() => {
    const settings = window.pixelflowSettings;
    if (settings) {
      const generalOpts = { ...defaultGeneralOptions, ...settings.general_options };
      // Ensure excluded_user_roles is always an array
      if (!Array.isArray(generalOpts.excluded_user_roles)) {
        generalOpts.excluded_user_roles = [];
      }
      setGeneralOptions(generalOpts);
      setClassOptions({ ...defaultClassOptions, ...settings.class_options });
      setDebugOptions({ ...defaultDebugOptions, ...settings.debug_options });
      setScriptCode(settings.script_code || '');
      setIsWooCommerceActive(settings.is_woocommerce_active);
      setIsLoading(false);
    } else {
      setError('Failed to load settings');
      toast('Failed to load settings', {
        type: 'error',
      });
      setIsLoading(false);
    }
  }, []);

  // Listen for script code updates (when generated/regenerated)
  useEffect(() => {
    const handleScriptUpdate = () => {
      const settings = window.pixelflowSettings;
      if (settings) {
        setScriptCode(settings.script_code || '');
      }
    };

    // Listen for custom event dispatched when script is saved
    window.addEventListener('pixelflow:script-updated', handleScriptUpdate);

    return () => {
      window.removeEventListener('pixelflow:script-updated', handleScriptUpdate);
    };
  }, []);

  const updateGeneralOption = <K extends keyof PixelFlowGeneralOptions>(
    key: K,
    value: PixelFlowGeneralOptions[K]
  ) => {
    setGeneralOptions((prev) => ({ ...prev, [key]: value }));
  };

  const updateClassOption = <K extends keyof PixelFlowClasses>(
    key: K,
    value: PixelFlowClasses[K]
  ) => {
    setClassOptions((prev) => ({ ...prev, [key]: value }));
  };

  const updateDebugOption = <K extends keyof PixelFlowClasses>(
    key: K,
    value: PixelFlowClasses[K]
  ) => {
    setDebugOptions((prev) => ({ ...prev, [key]: value }));
  };

  const toggleExcludedRole = (roleKey: string) => {
    setGeneralOptions((prev) => {
      const current = prev.excluded_user_roles || [];
      const updated = current.includes(roleKey)
        ? current.filter((r) => r !== roleKey) // Remove role
        : [...current, roleKey]; // Add role
      return { ...prev, excluded_user_roles: updated };
    });
  };

  const saveSettings = async (options?: SaveSettingsOptions) => {
    const settings = window.pixelflowSettings;
    if (!settings) {
      setError('Settings configuration not available');
      toast('Settings configuration not available', {
        type: 'error',
      });

      return;
    }

    setIsSaving(true);
    setError(null);

    try {
      const formData = new FormData();
      formData.append('action', 'pixelflow_save_settings');
      formData.append('nonce', settings.nonce);

      // Merge current state with overrides
      const finalGeneralOptions = { ...generalOptions, ...options?.generalOptionsOverride };
      const finalClassOptions = { ...classOptions, ...options?.classOptionsOverride };
      const finalDebugOptions = { ...debugOptions, ...options?.debugOptionsOverride };

      // Add general options (script_code is managed separately)
      Object.entries(finalGeneralOptions).forEach(([key, value]) => {
        formData.append(`general_options[${key}]`, String(value));
      });

      // Add class options
      Object.entries(finalClassOptions).forEach(([key, value]) => {
        formData.append(`class_options[${key}]`, String(value));
      });

      // Add debug options
      Object.entries(finalDebugOptions).forEach(([key, value]) => {
        formData.append(`debug_options[${key}]`, String(value));
      });

      const response = await fetch(settings.ajax_url, {
        method: 'POST',
        body: formData,
      });

      const data = await response.json();

      if (data.success) {
        // Update local state with sanitized values
        if (data.data.general_options) {
          setGeneralOptions({ ...defaultGeneralOptions, ...data.data.general_options });
        }
        if (data.data.class_options) {
          setClassOptions({ ...defaultClassOptions, ...data.data.class_options });
        }
        if (data.data.debug_options) {
          setDebugOptions({ ...defaultDebugOptions, ...data.data.debug_options });
        }

        // Also apply overrides to local state immediately if they were used
        if (options?.generalOptionsOverride) {
          setGeneralOptions((prev) => ({ ...prev, ...options.generalOptionsOverride }));
        }
        if (options?.classOptionsOverride) {
          setClassOptions((prev) => ({ ...prev, ...options.classOptionsOverride }));
        }
        if (options?.debugOptionsOverride) {
          setDebugOptions((prev) => ({ ...prev, ...options.debugOptionsOverride }));
        }

        toast('Settings saved successfully', {
          type: 'info',
        });
      } else {
        setError(data.data?.message || 'Failed to save settings');
        toast('Failed to save settings', {
          type: 'error',
        });
      }
    } catch (err) {
      setError('An error occurred while saving settings');
      console.error('Save settings error:', err);
      toast('Save settings error, see browser console for details', {
        type: 'error',
      });
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
