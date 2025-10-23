/**
 * @fileoverview Settings context provider
 * @description Provides global settings state to prevent multiple hook instances and race conditions
 */

/** External libraries */
import { createContext, useContext, ReactNode } from 'react';

/** Hooks */
import { useSettings as useSettingsInternal } from '@/features/settings/hooks/useSettings.ts';

/** Types */
import type { UseSettingsReturn } from '@/features/settings/hooks/useSettings.ts';

const SettingsContext = createContext<UseSettingsReturn | null>(null);

/**
 * Settings provider component
 * @description Wraps the application to provide shared settings state across all components
 * @param children - Child components that need access to settings
 * @returns Provider component
 */
export function SettingsProvider({ children }: { children: ReactNode }) {
  const settings = useSettingsInternal();

  return <SettingsContext.Provider value={settings}>{children}</SettingsContext.Provider>;
}

/**
 * Hook to access settings from context
 * @description Must be used within SettingsProvider. Returns shared settings state
 * to prevent concurrent save operations across multiple components.
 * @returns Settings state and mutation functions
 * @throws Error if used outside of SettingsProvider
 */
export function useSettings(): UseSettingsReturn {
  const context = useContext(SettingsContext);
  if (!context) {
    throw new Error('useSettings must be used within SettingsProvider');
  }
  return context;
}
