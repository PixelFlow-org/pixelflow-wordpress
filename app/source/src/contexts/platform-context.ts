/**
 * @fileoverview Platform context object
 * @description Kept out of platform.context.tsx: Fast Refresh requires that a
 * module exporting a component export nothing else, contexts included.
 */

import { createContext } from 'react';
import { PlatformAdapter } from '@pixelflow-org/plugin-core';

export interface PlatformContextType {
  adapter: PlatformAdapter;
}

export const PlatformContext = createContext<PlatformContextType | undefined>(undefined);
