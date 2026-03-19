/**
 * @fileoverview Home module for authenticated users
 * @description Main interface module displayed after successful authentication
 */

/** External libraries */
import { useEffect, useState, useMemo, ReactElement } from 'react';

/** Store */
import { getCdnUrl } from '@pixelflow-org/plugin-core';

/** UI Components */
import { Button } from '@pixelflow-org/plugin-ui';

/** Types */
import { PlatformAdapter } from '@pixelflow-org/plugin-core';

/** Hooks */
import { AuthScreen, usePixelsData } from '@pixelflow-org/plugin-features';
import { useUrlTriggersData } from '@pixelflow-org/plugin-features';
import { useEventsData } from '@pixelflow-org/plugin-features';
import { useUsersData } from '@pixelflow-org/plugin-features';

/** Components */
import {
  NavPanel,
  PixelsModule,
  UrlTriggersModule,
  EventsModule,
} from '@pixelflow-org/plugin-features';
import StartSetupModal from './components/start-setup-modal';

/** Constants */
import {
  wordPressNavPanelConfigAuth,
  wordPressNavPanelConfigNotAuth,
} from '@/features/home/constants/index';

/** Utils */
import { useAuth } from '@pixelflow-org/plugin-features';

/** Types */
import { WordPressNavPanelTab } from '@/features/home/types/index';

/* Wordpress settings page */
import {
  ActivatePixelflow,
  useSaveScriptCodeMutation,
  useRemoveScriptCodeMutation,
  AdvancedSettings,
  WooCommerceSettings,
} from '@/features/settings';
import { useSettings } from '@/features/settings/contexts/SettingsContext';
import TopControls from '@/shared/components/TopControls/TopControls.tsx';
import Notification from '@/shared/components/Notification/Notification.tsx';
import Header from '@/shared/components/Header/Header.tsx';

interface HomeProps {
  adapter: PlatformAdapter;
}

/**
 * Main dashboard module that orchestrates pixel tracking management
 * Handles the complete workflow from authentication verification to script injection
 * @param adapter - Platform adapter for platform-specific operations
 * @returns ReactElement - Complete home dashboard interface
 */
const Home = ({ adapter }: HomeProps): ReactElement => {
  /** Local state */
  // Track which settings panel is currently visible to users
  const [activeTab, setActiveTab] = useState<WordPressNavPanelTab>('woocommerce');
  // Store site ID to associate tracking data with specific sites
  const [siteExternalId, setSiteExternalId] = useState<string | null>(null);
  // Store site ID to associate tracking data with specific sites
  const [siteId, setSiteId] = useState<number | null>(null);
  // Control pixel configuration modal visibility to manage user interactions
  const [openAddPixelModal, setOpenAddPixelModal] = useState<boolean>(false);
  // Control URL tracking modal visibility to manage user interactions
  const [openAddUrlModal, setOpenAddUrlModal] = useState<boolean>(false);
  // Store selected currency to associate tracking data with specific currencies
  const [selectedCurrency, setSelectedCurrency] = useState<string>('USD');
  // Track if script generation is in progress to prevent duplicate calls
  const [isGeneratingScript, setIsGeneratingScript] = useState<boolean>(false);
  // Track error state and type for notifications
  const [error, setError] = useState('');
  // Track error type for notifications
  const [errorType, setErrorType] = useState<'warning' | 'error'>('error');
  // Control start setup modal visibility to manage user interactions
  const [openStartSetupModal, setOpenStartSetupModal] = useState<boolean>(false);

  /**
   * Auth things
   */
  const { user, handleAuthSuccess, handleLogout, checkExistingAuth } = useAuth({
    adapter,
  });

  /** Pixels */
  // Manage pixel tracking configurations tied to the current site
  const { pixels, addPixel, updatePixel, deletePixel, arePixelsLoading } = usePixelsData({
    siteExternalId: siteExternalId ?? '',
    adapter,
  });

  /** URL triggers */
  const { urlTriggers, eventTypes, addUrlTrigger, deleteUrlTrigger, updateCurrency } =
    useUrlTriggersData({
      siteExternalId: siteExternalId ?? '',
      siteId,
      adapter,
    });

  /** Users */
  // Access secure API key retrieval for authentication with tracking services
  const { getHashedApiKey } = useUsersData({ adapter });

  /** Events */
  // Manage event tracking configurations for conversion tracking
  const { events, areEventsLoading, refreshEvents } = useEventsData({
    siteId,
    adapter,
  });

  // Get settings save function to disable integration on logout
  const { saveSettings, isWooCommerceActive } = useSettings();

  useEffect(() => {
    if (user) {
      if (!isWooCommerceActive) {
        setActiveTab('pixel');
      } else {
        setActiveTab('woocommerce');
      }
    } else {
      setActiveTab('account');
    }
  }, [isWooCommerceActive, user]);

  // Create dynamic nav config based on WooCommerce availability
  const navConfig = useMemo(
    () => ({
      ...(user ? wordPressNavPanelConfigAuth : wordPressNavPanelConfigNotAuth),
      tabs: (user ? wordPressNavPanelConfigAuth : wordPressNavPanelConfigNotAuth).tabs.map((tab) =>
        tab.id === 'woocommerce' ? { ...tab, visible: isWooCommerceActive } : tab
      ),
    }),
    [isWooCommerceActive, user]
  );

  // RTK mutations for script management
  const [saveScriptCode] = useSaveScriptCodeMutation();
  const [removeScriptCode] = useRemoveScriptCodeMutation();

  const logoutHandler = async () => {
    try {
      // Disable PixelFlow integration before logout
      // Use override to ensure the value is set immediately without waiting for state update
      await saveSettings();

      console.log('[PixelFlow] Integration disabled on logout');
    } catch (error) {
      console.error('[PixelFlow] Failed to disable integration:', error);
    } finally {
      // Proceed with logout regardless of settings update result
      handleLogout().then(() => {
        checkExistingAuth().then(() => {
          console.log('user logged out', user);
        });
      });
    }
  };

  /** Effects */
  /**
   * Auto-generate and save tracking script when all dependencies are ready
   * @description Automatically generates the tracking script once authentication and all
   * required data (pixels, site info, API key) are available. The script is saved to WordPress
   * and can be enabled/disabled via the settings toggle.
   */
  useEffect(() => {
    if (!user) {
      return;
    }
    const generateAndSaveScript = async () => {
      // Skip if already generating or if any required dependency is missing
      if (isGeneratingScript || !siteExternalId || !pixels || pixels.length === 0 || !siteId) {
        return;
      }

      setIsGeneratingScript(true);

      try {
        // Get API key for script generation
        const apiKey = await getHashedApiKey();
        if (!apiKey) {
          console.log('[PixelFlow] Waiting for API key...');
          setIsGeneratingScript(false);
          return;
        }

        // Save script parameters to WordPress database using RTK mutation
        await saveScriptCode({
          siteExternalId,
          apiKey,
          apiEndpoint: `${import.meta.env.VITE_API_BASE_URL || ''}/event`,
          cdnUrl: getCdnUrl(),
        }).unwrap();

        console.log('[PixelFlow] Tracking script saved successfully. Use settings to enable.');
      } catch (error) {
        console.error('[PixelFlow] Failed to generate/save tracking script:', error);
      } finally {
        setIsGeneratingScript(false);
      }
    };

    generateAndSaveScript();
    // Note: getHashedApiKey and getSite are intentionally NOT in dependencies
    // to avoid infinite loops - they're stable functions from hooks
  }, [siteExternalId, pixels, siteId, selectedCurrency, urlTriggers, user]);

  // Automatically associate tracking data with the current site on component mount
  useEffect(() => {
    if (!user) {
      return;
    }
    /**
     * Retrieves site identifier to ensure tracking data isolation between sites
     * Prevents configuration leakage between different user sites
     */
    const fetchSiteExternalId = async (): Promise<void> => {
      try {
        const siteId = await adapter.getSiteId();
        setSiteExternalId(siteId);
      } catch (error) {
        console.error('Error fetching site ID:', error);
      }
    };
    fetchSiteExternalId();
  }, [adapter, user]);

  useEffect(() => {
    if (!user) return;
    if (user.sites.length > 0 && siteExternalId) {
      const site = user.sites.find((site) => site.external_id === siteExternalId);
      if (site) {
        setSiteId(site.id);
        if (site.currency) {
          setSelectedCurrency(site.currency);
        }
      }
    }
  }, [user, siteExternalId]);

  // Open start setup modal if no pixels are added
  useEffect(() => {
    if (pixels) {
      setOpenStartSetupModal(typeof pixels !== 'undefined' && pixels.length === 0);
    }
  }, [pixels]);

  /**
   * Unified modal trigger that opens appropriate configuration interfaces
   * Reduces code duplication and provides consistent user experience across tabs
   * @returns void
   */
  const onAddEntityClick = (): void => {
    // Route to appropriate modal based on current context to maintain user workflow continuity
    switch (activeTab) {
      case 'woocommerce':
        break;
      case 'pixel':
        setOpenAddPixelModal(true);
        break;
      case 'url':
        setOpenAddUrlModal(true);
        break;
      case 'events':
        refreshEvents();
        break;
      case 'advanced':
        break;
    }
  };

  /**
   * Manually regenerate the tracking script
   * @description Forces regeneration of the tracking script with current configuration.
   * Note: Script auto-regenerates when dependencies change, but this allows manual trigger.
   * @returns Promise<boolean> - Success status
   */
  const onRegenerateScript = async (): Promise<boolean> => {
    try {
      // Clear existing script first
      setError('');
      await removeScriptCode().unwrap();

      // Validate API key availability
      const apiKey = await getHashedApiKey();
      if (!apiKey) {
        const errorMsg = 'API key is missing. Please contact support.';
        adapter.showNotification(errorMsg, 'error');
        setError(errorMsg);
        setErrorType('error');
        return false;
      }

      // Verify site context is available
      if (!siteExternalId) {
        const errorMsg = 'Failed to regenerate script. Please try again later.';
        adapter.showNotification(errorMsg, 'error');
        setError(errorMsg);
        setErrorType('error');
        return false;
      }

      // Ensure tracking configuration exists
      if (!pixels || pixels.length === 0) {
        const errorMsg = 'Add at least one pixel before generating script';
        adapter.showNotification(errorMsg, 'warning');
        setError(errorMsg);
        setErrorType('warning');
        // Switch to the Pixel tab
        setActiveTab('pixel');

        return false;
      }

      // Save script parameters to WordPress database using RTK mutation
      await saveScriptCode({
        siteExternalId,
        apiKey,
        apiEndpoint: `${import.meta.env.VITE_API_BASE_URL || ''}/event`,
        cdnUrl: getCdnUrl(),
      }).unwrap();

      adapter.showNotification('Tracking script regenerated successfully', 'success');
      return true;
    } catch (error) {
      const errorMsg = 'Failed to regenerate script';
      adapter.showNotification(errorMsg, 'error');
      setError(errorMsg);
      setErrorType('error');
      console.error('[PixelFlow] Script regeneration failed:', error);
      return false;
    }
  };

  /**
   * Updates the currency for a given site
   * @param currency - The currency to update the site to
   * @returns A promise that resolves when the site is updated
   */
  const onCurrencyChange = async (currency: string): Promise<void> => {
    try {
      if (!siteId) {
        adapter.showNotification(
          'Sorry, we can not recognize your site. Please try again later.',
          'error'
        );
        return;
      }
      await updateCurrency(siteId, currency);
      setSelectedCurrency(currency);
    } catch (error) {
      console.log('ERROR', error);
    }
  };

  /**
   * Handles the open change event for the start setup modal
   * @param open - The new open state of the modal
   * @returns void
   */
  const onStartSetupOpenChange = (open: boolean): void => {
    if (!open && pixels && pixels.length > 0) {
      setOpenStartSetupModal(false);
    }
  };

  return (
    <div className="bg-background text-foreground min-h-full !p-[12px]">
      <nav className="flex justify-between items-center mb-6 gap-60">
        <Header selectedCurrency={selectedCurrency} updateCurrency={onCurrencyChange} user={user} />
        <TopControls handleLogout={logoutHandler} user={user} />
      </nav>
      {error && <Notification message={error} type={errorType} />}
      <ActivatePixelflow onRegenerateScript={onRegenerateScript} user={user} />
      <NavPanel
        activeTab={activeTab}
        setActiveTab={setActiveTab}
        onButtonClick={onAddEntityClick}
        config={navConfig}
      />
      {activeTab === 'woocommerce' && <WooCommerceSettings />}
      {activeTab === 'account' && (
        <AuthScreen adapter={adapter} onAuthSuccess={handleAuthSuccess} />
      )}
      {activeTab === 'pixel' &&
        (user ? (
          <>
            <PixelsModule
              pixels={pixels ?? []}
              siteExternalId={siteExternalId}
              addPixel={addPixel}
              updatePixel={updatePixel}
              deletePixel={deletePixel}
              open={openAddPixelModal}
              setOpen={setOpenAddPixelModal}
              adapter={adapter}
            />
            <Button.Root
              size="xsmall"
              className={`${pixels?.length ? '' : 'pf-add-pixel-button'} my-4 mx-auto`}
              onClick={() => setOpenAddPixelModal(true)}
            >
              Add pixel
            </Button.Root>
          </>
        ) : (
          <AuthScreen adapter={adapter} onAuthSuccess={handleAuthSuccess} />
        ))}
      {activeTab === 'url' &&
        (user ? (
          <>
            <UrlTriggersModule
              urlTriggers={urlTriggers ?? []}
              eventTypes={eventTypes ?? []}
              addUrlTrigger={addUrlTrigger}
              deleteUrlTrigger={deleteUrlTrigger}
              open={openAddUrlModal}
              setOpen={setOpenAddUrlModal}
              adapter={adapter}
            />
            <Button.Root
              size="xsmall"
              className="my-4 mx-auto"
              onClick={() => setOpenAddUrlModal(true)}
            >
              Add Url trigger
            </Button.Root>
          </>
        ) : (
          <AuthScreen adapter={adapter} onAuthSuccess={handleAuthSuccess} />
        ))}
      {activeTab === 'events' &&
        (user ? (
          <EventsModule events={events} areEventsLoading={areEventsLoading} adapter={adapter} />
        ) : (
          <AuthScreen adapter={adapter} onAuthSuccess={handleAuthSuccess} />
        ))}
      {activeTab === 'advanced' && <AdvancedSettings />}
      {siteId && user && !arePixelsLoading && (
        <StartSetupModal open={openStartSetupModal} onOpenChange={onStartSetupOpenChange} />
      )}
    </div>
  );
};

export default Home;
