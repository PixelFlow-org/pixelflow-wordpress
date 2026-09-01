/**
 * @fileoverview Platform Context for PixelFlow WordPress plugin
 * @description Provides platform adapter instance throughout the application
 */

import React, { ReactNode, useMemo } from 'react';
import { wordpressAdapter } from '@/adapters';
import { PlatformContext } from './platform-context';

interface PlatformProviderProps {
  children: ReactNode;
}

/**
 * Platform Provider component
 * @description Provides the WordPress adapter singleton instance
 */
export const PlatformProvider: React.FC<PlatformProviderProps> = ({ children }) => {
  const adapter = useMemo(() => wordpressAdapter, []);

  return <PlatformContext.Provider value={{ adapter }}>{children}</PlatformContext.Provider>;
};
