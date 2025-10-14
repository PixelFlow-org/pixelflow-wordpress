/**
 * @fileoverview Platform Context for PixelFlow WordPress plugin
 * @description Provides platform adapter instance throughout the application
 */

import React, { createContext, useContext, ReactNode, useMemo } from 'react';
import { PlatformAdapter } from '@pixelflow-org/plugin-core';
import { WordpressAdapter } from '../adapters/wordpress-adapter';
import { platformConfig } from '../config/platform.config';

interface PlatformContextType {
  adapter: PlatformAdapter;
}

const PlatformContext = createContext<PlatformContextType | undefined>(undefined);

interface PlatformProviderProps {
  children: ReactNode;
}

/**
 * Platform Provider component
 * @description Creates and provides the WordPress adapter instance
 */
export const PlatformProvider: React.FC<PlatformProviderProps> = ({ children }) => {
  const adapter = useMemo(() => new WordpressAdapter(platformConfig), []);

  return <PlatformContext.Provider value={{ adapter }}>{children}</PlatformContext.Provider>;
};

/**
 * Hook to access platform adapter
 * @description Use this hook to access platform-specific functionality
 * @returns Platform adapter instance
 * @throws Error if used outside PlatformProvider
 */
export const usePlatform = (): PlatformContextType => {
  const context = useContext(PlatformContext);
  if (!context) {
    throw new Error('usePlatform must be used within a PlatformProvider');
  }
  return context;
};
