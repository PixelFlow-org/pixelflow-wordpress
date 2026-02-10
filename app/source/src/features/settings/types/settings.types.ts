// General plugin options (enabled/disabled toggles)
export interface PixelFlowGeneralOptions {
  enabled: number;
  woo_enabled: number;
  excluded_user_roles: string[]; // Array of role keys to exclude from script injection
  remove_on_uninstall: number; // Remove all plugin settings when plugin is uninstalled
}

// User role structure parsed from WordPress
export interface UserRole {
  key: string; // 'administrator', 'editor', etc.
  label: string; // 'Administrator', 'Editor', etc.
}

export interface PixelFlowSettings {
  general_options: PixelFlowGeneralOptions;
  script_code: string; // Managed separately via ajax_save_script_code
  nonce: string;
  ajax_url: string;
  is_woocommerce_active: boolean;
}

// RTK Query types
export interface SettingsResponse {
  general_options: PixelFlowGeneralOptions;
  script_code: string;
  is_woocommerce_active: boolean;
}

export interface SaveSettingsRequest {
  generalOptions: PixelFlowGeneralOptions;
}

declare global {
  interface Window {
    pixelflowSettings?: PixelFlowSettings;
  }
}
