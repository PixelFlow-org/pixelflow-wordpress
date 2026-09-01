/**
 * @fileoverview Hook for the shared settings context
 * @description Named useSettingsContext, not useSettings, to keep it distinct
 * from the underlying `features/settings/hooks/useSettings.ts`, which creates a
 * fresh instance of the state. Components want this one.
 */

import { useContext } from 'react';

import { SettingsContext } from '@/features/settings/contexts/settings-context.ts';

import type { UseSettingsReturn } from '@/features/settings/hooks/useSettings.ts';

/**
 * Hook to access settings from context
 * @description Must be used within SettingsProvider. Returns shared settings state
 * to prevent concurrent save operations across multiple components.
 * @returns Settings state and mutation functions
 * @throws Error if used outside of SettingsProvider
 */
export function useSettingsContext(): UseSettingsReturn {
  const context = useContext(SettingsContext);
  if (!context) {
    throw new Error('useSettingsContext must be used within SettingsProvider');
  }
  return context;
}
