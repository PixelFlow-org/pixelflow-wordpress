/**
 * @fileoverview Settings module exports
 * @description Central export point for all settings-related components, hooks, and types
 */

// Components
export { SettingsPage } from '@/features/settings/SettingsPage.tsx';
export { ActivatePixelflow } from '@/features/settings/components/ActivatePixelflow.tsx';
export { AdvancedSettings } from '@/features/settings/components/AdvancedSettings.tsx';
export { WooCommerceSettings } from '@/features/settings/components/WooCommerceSettings.tsx';
export { DebugSettings } from '@/features/settings/components/DebugSettings.tsx';
export { WooClassSection } from '@/features/settings/components/WooClassSection.tsx';

// Context & Hooks
export { useSettings, SettingsProvider } from '@/features/settings/contexts/SettingsContext.tsx';

// Types & API
export * from '@/features/settings/types/settings.types.ts';
export * from '@/features/settings/api';
