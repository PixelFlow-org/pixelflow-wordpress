import { DEFAULT_NAV_PANEL_CONFIG, NavPanelConfig } from '@pixelflow-org/plugin-features';
import { SettingsIcon } from '@/shared/icons/settings.icon';
import { StoreIcon } from '@/shared/icons/store.icon';

import { WordPressNavPanelTab } from '@/features/home/types/index';

/** Extended NavPanel configuration for WordPress with custom tabs */
export const wordPressNavPanelConfig: NavPanelConfig<WordPressNavPanelTab> = {
  tabs: [
    {
      id: 'woocommerce',
      label: 'WooCommerce Settings',
      width: '!w-[140px]',
      visible: true,
    },
    ...DEFAULT_NAV_PANEL_CONFIG.tabs.map((tab) => ({
      ...tab,
      id: tab.id as WordPressNavPanelTab,
    })),
    {
      id: 'advanced',
      label: 'Advanced Settings',
      width: '!w-[140px]',
      visible: true,
    },
  ],
  getButton: (activeTab) => {
    switch (activeTab) {
      case 'woocommerce':
        return {
          icon: <StoreIcon />,
          label: 'Configure',
          width: '!w-[90px]',
        };
      case 'advanced':
        return {
          icon: <SettingsIcon />,
          label: 'Edit',
          width: '!w-[70px]',
        };
      // Use default behavior for standard tabs
      case 'pixel':
      case 'url':
      case 'events':
        return DEFAULT_NAV_PANEL_CONFIG.getButton?.(activeTab) || null;
      default:
        return null;
    }
  },
};
