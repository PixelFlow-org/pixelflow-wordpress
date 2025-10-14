/**
 * @fileoverview Entry point for PixelFlow WordPress plugin
 * @description Initializes environment and renders app
 */

// Configure core package with platform-specific URLs BEFORE rendering
import { setPixelFlowConfig } from '@pixelflow-org/plugin-core';

setPixelFlowConfig({
  apiBaseUrl: import.meta.env.VITE_API_BASE_URL || '',
  uiBaseUrl: import.meta.env.VITE_UI_BASE_URL || '',
  cdnUrl: import.meta.env.VITE_CDN_URL || '',
});

import React from 'react';
import ReactDOM from 'react-dom/client';
import { App } from './App';

// Check if we're on the WordPress settings page
const rootElement = document.getElementById('pixelflowroot');

if (rootElement) {
  ReactDOM.createRoot(rootElement).render(
    <React.StrictMode>
      <App />
    </React.StrictMode>
  );
}
