import { useState, useEffect } from 'react';
import { PixelFlowGeneralOptions, PixelFlowClasses } from './settings.types';

interface UseSettingsReturn {
  generalOptions: PixelFlowGeneralOptions;
  classOptions: PixelFlowClasses;
  debugOptions: PixelFlowClasses;
  scriptCode: string;
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
  saveSettings: () => Promise<void>;
}

const defaultGeneralOptions: PixelFlowGeneralOptions = {
  enabled: 0,
  woo_enabled: 0,
  debug_enabled: 0,
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
  const [isWooCommerceActive, setIsWooCommerceActive] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Load settings on mount
  useEffect(() => {
    const settings = window.pixelflowSettings;
    if (settings) {
      setGeneralOptions({ ...defaultGeneralOptions, ...settings.general_options });
      setClassOptions({ ...defaultClassOptions, ...settings.class_options });
      setDebugOptions({ ...defaultDebugOptions, ...settings.debug_options });
      setScriptCode(settings.script_code || '');
      setIsWooCommerceActive(settings.is_woocommerce_active);
      setIsLoading(false);
    } else {
      setError('Failed to load settings');
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

  const saveSettings = async () => {
    const settings = window.pixelflowSettings;
    if (!settings) {
      setError('Settings configuration not available');
      return;
    }

    setIsSaving(true);
    setError(null);

    try {
      const formData = new FormData();
      formData.append('action', 'pixelflow_save_settings');
      formData.append('nonce', settings.nonce);

      // Add general options (script_code is managed separately)
      Object.entries(generalOptions).forEach(([key, value]) => {
        formData.append(`general_options[${key}]`, String(value));
      });

      // Add class options
      Object.entries(classOptions).forEach(([key, value]) => {
        formData.append(`class_options[${key}]`, String(value));
      });

      // Add debug options
      Object.entries(debugOptions).forEach(([key, value]) => {
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
      } else {
        setError(data.data?.message || 'Failed to save settings');
      }
    } catch (err) {
      setError('An error occurred while saving settings');
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
    isWooCommerceActive,
    isLoading,
    isSaving,
    error,
    updateGeneralOption,
    updateClassOption,
    updateDebugOption,
    saveSettings,
  };
}
