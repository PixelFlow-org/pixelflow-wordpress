/**
 * @fileoverview Settings context provider
 * @description Provides global settings state to prevent multiple hook instances and race conditions
 */

/** External libraries */
import { ReactNode } from 'react';

/** Context */
import { SettingsContext } from '@/features/settings/contexts/settings-context.ts';

/** Hooks */
import { useSettings as useSettingsInternal } from '@/features/settings/hooks/useSettings.ts';

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
