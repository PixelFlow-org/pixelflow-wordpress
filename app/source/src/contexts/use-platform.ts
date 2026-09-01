/**
 * @fileoverview Platform context hook
 * @description Kept out of platform.context.tsx so that module exports only
 * components, which is what Fast Refresh requires.
 */

import { useContext } from 'react';
import { PlatformContext, PlatformContextType } from './platform-context';

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
