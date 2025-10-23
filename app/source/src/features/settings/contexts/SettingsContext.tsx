import { createContext, useContext, ReactNode } from 'react';
import { useSettings as useSettingsInternal } from '@/features/settings/hooks/useSettings.ts';
import type { UseSettingsReturn } from '@/features/settings/hooks/useSettings.ts';

const SettingsContext = createContext<UseSettingsReturn | null>(null);

export function SettingsProvider({ children }: { children: ReactNode }) {
  const settings = useSettingsInternal();

  return <SettingsContext.Provider value={settings}>{children}</SettingsContext.Provider>;
}

export function useSettings(): UseSettingsReturn {
  const context = useContext(SettingsContext);
  if (!context) {
    throw new Error('useSettings must be used within SettingsProvider');
  }
  return context;
}
