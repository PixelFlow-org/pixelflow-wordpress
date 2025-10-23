/**
 * @fileoverview Entry point for PixelFlow WordPress plugin
 * @description Initializes environment configuration and renders app
 */

/** Core Configuration */
import { setPixelFlowConfig } from '@pixelflow-org/plugin-core';

// Configure core package with platform-specific URLs BEFORE rendering
setPixelFlowConfig({
  apiBaseUrl: import.meta.env.VITE_API_BASE_URL || '',
  uiBaseUrl: import.meta.env.VITE_UI_BASE_URL || '',
  cdnUrl: import.meta.env.VITE_CDN_URL || '',
});

/** External libraries */
import React from 'react';
import ReactDOM from 'react-dom/client';

/** Components */
import { App } from './App';

// Mount app to root element
const rootElement = document.getElementById('pixelflowroot');

if (rootElement) {
  ReactDOM.createRoot(rootElement).render(
    <React.StrictMode>
      <App />
    </React.StrictMode>
  );
}
