/**
 * @fileoverview Bootstrap component for WordPress plugin
 * @description Orchestrates authentication and renders appropriate feature modules
 */

import { useState, useEffect } from 'react';
import { AuthStatus } from '@pixelflow-org/plugin-core';
import { LoadingScreen } from '@pixelflow-org/plugin-ui';
import { AuthScreen, useAuth, useAuthSelector } from '@pixelflow-org/plugin-features';

import Home from '@/features/home';
import { SettingsProvider } from '@/features/settings';

import { usePlatform } from '@/contexts/platform.context';

/**
 * Bootstrap component
 * @description Main orchestrator for the application flow
 */
const Bootstrap = () => {
  const [isInitializing, setIsInitializing] = useState(true);
  const { adapter } = usePlatform();

  // Auth state from Redux
  const { isAuthenticated } = useAuthSelector();

  // Auth operations (pass adapter for platform-specific operations)
  const { authState, user, checkExistingAuth, handleAuthSuccess, handleLogout } = useAuth({
    adapter,
  });

  // Initialize authentication on mount
  useEffect(() => {
    const initializeAuth = async () => {
      try {
        await checkExistingAuth();
      } catch (error) {
        console.error('Error during auth initialization:', error);
      } finally {
        setIsInitializing(false);
      }
    };

    initializeAuth();
  }, [checkExistingAuth]);

  // Initialize menu when authenticated (if platform supports it)
  useEffect(() => {
    const initializeMenu = async () => {
      if (isAuthenticated && adapter.setMenu) {
        await adapter.setMenu([
          {
            label: 'Log Out',
            onAction: handleLogout,
          },
        ]);
      }
    };

    initializeMenu();
  }, [handleLogout, isAuthenticated, adapter]);

  // Show loading screen while initializing
  if (isInitializing || authState === AuthStatus.LOADING) {
    return <LoadingScreen />;
  }

  // Show auth screen if not authenticated
  if (authState === AuthStatus.UNAUTHENTICATED && !isAuthenticated) {
    return <AuthScreen adapter={adapter} onAuthSuccess={handleAuthSuccess} />;
  }

  // Show home module if authenticated
  if (authState === AuthStatus.AUTHENTICATED && isAuthenticated && user) {
    return (
      <SettingsProvider>
        <Home adapter={adapter} user={user} />
      </SettingsProvider>
    );
  }

  // Fallback loading screen
  return <LoadingScreen />;
};

export default Bootstrap;
