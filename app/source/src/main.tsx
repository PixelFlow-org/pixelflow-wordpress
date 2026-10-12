/**
 * @fileoverview Entry point for PixelFlow WordPress plugin
 * @description Initializes environment configuration and renders app
 */

/** Core Configuration */
import { setPixelFlowConfig } from '@pixelflow-org/plugin-core';
import { getServiceConfig } from '@/features/settings/utils/wordpress-config';

// Configure core package with the installed build's URLs BEFORE rendering
setPixelFlowConfig(getServiceConfig());

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
