/**
 * @fileoverview Settings context object
 * @description Kept out of SettingsContext.tsx: Fast Refresh requires that a
 * module exporting a component export nothing else, contexts included.
 */

import { createContext } from 'react';

import type { UseSettingsReturn } from '@/features/settings/hooks/useSettings.ts';

export const SettingsContext = createContext<UseSettingsReturn | null>(null);
