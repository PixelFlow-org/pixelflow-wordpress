/**
 * @fileoverview Main App component for WordPress plugin
 * @description Root application component with all providers
 */

import React from 'react';
import { Provider } from 'react-redux';
import { PersistGate } from 'redux-persist/integration/react';
import { store, persistor } from '@/store';
import { LoadingScreen, ThemeProvider } from '@pixelflow-org/plugin-ui';
import '@pixelflow-org/plugin-ui/dist/styles.css';
import './App.css';

import { PlatformProvider, usePlatform } from './contexts/platform.context';
import Bootstrap from './features/bootstrap/components';
import { ToastContainer } from 'react-toastify';

/**
 * App with platform adapter
 * @description Inner app component that has access to platform adapter
 */
function AppWithPlatform() {
  const { adapter } = usePlatform();

  // Configure window on mount (if platform supports it)
  React.useEffect(() => {
    adapter.configureWindow?.({
      position: 'top right',
      width: 470,
      height: 495,
    });
  }, [adapter]);

  return (
    <Provider store={store}>
      <ThemeProvider
        themeDetector={() => adapter.getTheme()}
        onThemeChange={(callback) => adapter.onThemeChange(callback)}
      >
        <PersistGate loading={<LoadingScreen />} persistor={persistor}>
          <Bootstrap />
          <ToastContainer
            position="bottom-right"
            autoClose={5000}
            hideProgressBar={false}
            closeOnClick={false}
            pauseOnHover={true}
            draggable={true}
            theme={adapter.getTheme()}
          />
        </PersistGate>
      </ThemeProvider>
    </Provider>
  );
}

/**
 * Main App component
 * @description Root component with PlatformProvider
 */
export function App() {
  return (
    <PlatformProvider>
      <AppWithPlatform />
    </PlatformProvider>
  );
}
