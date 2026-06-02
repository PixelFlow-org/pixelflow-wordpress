/**
 * @fileoverview WooCommerce Settings component
 * @description Configuration interface for WooCommerce event tracking
 */

/** External libraries */
import React from 'react';

/** UI Components */
import * as UI from '@pixelflow-org/plugin-ui';
import { Dropdown } from '@pixelflow-org/plugin-ui';

/** Hooks */
import { useSettings } from '@/features/settings/contexts/SettingsContext.tsx';
import { PixelFlowGeneralOptions } from '@/features/settings';

/**
 * WooCommerceSettings component
 * @description Manages WooCommerce eCommerce event tracking configuration including
 * Add to Cart, Checkout, and Purchase event tracking
 * @returns WooCommerceSettings component
 */
export function WooCommerceSettings() {
  const {
    generalOptions,
    isWooCommerceActive,
    updateGeneralOption,
    saveSettings,
    error,
    isSaving,
  } = useSettings();

  const [skuInput, setSkuInput] = React.useState('');

  const handleSkuKeyDown = async (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key !== 'Enter' && e.key !== ',') return;
    e.preventDefault();
    const sku = skuInput.trim();
    if (!sku) return;
    const current = generalOptions.woo_excluded_skus || [];
    if (current.includes(sku)) {
      setSkuInput('');
      return;
    }
    const updated = [...current, sku];
    setSkuInput('');
    updateGeneralOption('woo_excluded_skus', updated);
    await saveSettings({ generalOptionsOverride: { woo_excluded_skus: updated } });
  };

  const handleRemoveSku = async (sku: string) => {
    const updated = (generalOptions.woo_excluded_skus || []).filter((s) => s !== sku);
    updateGeneralOption('woo_excluded_skus', updated);
    await saveSettings({ generalOptionsOverride: { woo_excluded_skus: updated } });
  };

  if (!isWooCommerceActive) {
    return null;
  }

  const handleToggleOption = async (option: keyof PixelFlowGeneralOptions) => {
    const newValue = generalOptions[option] === 1 ? 0 : 1;
    updateGeneralOption(option, newValue);

    // Save immediately
    await saveSettings({ generalOptionsOverride: { [option]: newValue } });
  };

  // Master event toggles: when disabling, also force the freebies sub-option off
  const masterFreebiesMap: Partial<
    Record<keyof PixelFlowGeneralOptions, keyof PixelFlowGeneralOptions>
  > = {
    woo_disable_add_to_cart: 'woo_disable_add_to_cart_freebies',
    woo_disable_initiate_checkout: 'woo_disable_initiate_checkout_freebies',
    woo_disable_purchase: 'woo_disable_purchase_freebies',
  };

  const handleProductIdFormatChange = async (value: string) => {
    const prev = generalOptions.woo_product_id_format;
    updateGeneralOption('woo_product_id_format', value);
    try {
      await saveSettings({ generalOptionsOverride: { woo_product_id_format: value } });
    } catch {
      updateGeneralOption('woo_product_id_format', prev);
    }
  };

  const handleToggleMasterEvent = async (masterOption: keyof PixelFlowGeneralOptions) => {
    const newValue = generalOptions[masterOption] === 1 ? 0 : 1;
    updateGeneralOption(masterOption, newValue);

    const freebiesKey = masterFreebiesMap[masterOption];
    const cascade = newValue === 1 && freebiesKey;
    if (cascade) {
      updateGeneralOption(freebiesKey!, 1);
    }

    await saveSettings({
      generalOptionsOverride: {
        [masterOption]: newValue,
        ...(cascade ? { [freebiesKey!]: 1 } : {}),
      },
    });
  };

  return (
    <div className="max-w-6xl py-3">
      <div className=" rounded-lg shadow-sm border border-gray-200 p-6">
        {error && (
          <div className="mb-6 p-4 bg-red-50 border border-red-200 rounded-md">
            <p className="text-red-800 text-sm">{error}</p>
          </div>
        )}
        <div className="flex items-center gap-3">
          <UI.Switch.Root
            checked={generalOptions.woo_enabled === 1}
            onCheckedChange={() => handleToggleOption('woo_enabled')}
            id="enableWoo"
            variant={'green'}
            disabled={isSaving}
          ></UI.Switch.Root>
          <UI.TooltipRoot>
            <UI.TooltipTrigger asChild>
              <UI.Label.Root className="cursor-pointer" htmlFor="enableWoo">
                Track WooCommerce eCommerce Events
              </UI.Label.Root>
            </UI.TooltipTrigger>
            <UI.TooltipContent>
              We will automatically track all add to cart, checkout and purchase events along with
              associated metadata where available
            </UI.TooltipContent>
          </UI.TooltipRoot>
        </div>
        <div>
          <p className="text-sm text-foreground ml-11">
            We will automatically track all add to cart, checkout and purchase events along with
            associated metadata where available
          </p>
        </div>
        {generalOptions.woo_enabled === 1 && (
          <div className="space-y-6 mt-6">
            <div className="flex gap-3 [@media(max-width:1100px)]:flex-wrap flex-col">
              <h4 className="font-semibold !mb-0 !text-foreground !text-lg">eCommerce Events</h4>
              <div className="flex items-center gap-3">
                <UI.Switch.Root
                  checked={generalOptions.woo_disable_add_to_cart === 0}
                  onCheckedChange={() => handleToggleMasterEvent('woo_disable_add_to_cart')}
                  id="woo_disable_add_to_cart"
                  variant={'green'}
                  disabled={isSaving}
                ></UI.Switch.Root>
                <UI.TooltipRoot>
                  <UI.TooltipTrigger asChild>
                    <UI.Label.Root className="cursor-pointer" htmlFor="woo_disable_add_to_cart">
                      <span>
                        Enable <b>Add to Cart</b> event
                      </span>
                    </UI.Label.Root>
                  </UI.TooltipTrigger>
                  <UI.TooltipContent>
                    When disabled, Add to Cart events will not be sent for any product.
                  </UI.TooltipContent>
                </UI.TooltipRoot>
              </div>
              <div className="flex items-center gap-3">
                <UI.Switch.Root
                  checked={generalOptions.woo_disable_initiate_checkout === 0}
                  onCheckedChange={() => handleToggleMasterEvent('woo_disable_initiate_checkout')}
                  id="woo_disable_initiate_checkout"
                  variant={'green'}
                  disabled={isSaving}
                ></UI.Switch.Root>
                <UI.TooltipRoot>
                  <UI.TooltipTrigger asChild>
                    <UI.Label.Root
                      className="cursor-pointer"
                      htmlFor="woo_disable_initiate_checkout"
                    >
                      <span>
                        Enable <b>Initiate Checkout</b> event
                      </span>
                    </UI.Label.Root>
                  </UI.TooltipTrigger>
                  <UI.TooltipContent>
                    When disabled, Initiate Checkout events will not be sent regardless of cart
                    contents.
                  </UI.TooltipContent>
                </UI.TooltipRoot>
              </div>
              <div className="flex items-center gap-3">
                <UI.Switch.Root
                  checked={generalOptions.woo_disable_purchase === 0}
                  onCheckedChange={() => handleToggleMasterEvent('woo_disable_purchase')}
                  id="woo_disable_purchase"
                  variant={'green'}
                  disabled={isSaving}
                ></UI.Switch.Root>
                <UI.TooltipRoot>
                  <UI.TooltipTrigger asChild>
                    <UI.Label.Root className="cursor-pointer" htmlFor="woo_disable_purchase">
                      <span>
                        Enable <b>Purchase</b> event
                      </span>
                    </UI.Label.Root>
                  </UI.TooltipTrigger>
                  <UI.TooltipContent>
                    When disabled, Purchase events will not be sent for any order.
                  </UI.TooltipContent>
                </UI.TooltipRoot>
              </div>
            </div>
            <div className="flex gap-3 [@media(max-width:1100px)]:flex-wrap flex-col">
              <h4 className="font-semibold !mb-0 !text-foreground !text-lg">Product ID Format</h4>
              {(() => {
                const formatOptions = [
                  {
                    value: 'product_id',
                    label: 'Product ID',
                    example: '123',
                    description: 'Numeric WooCommerce product ID',
                  },
                  {
                    value: 'prefixed',
                    label: 'Prefixed',
                    example: 'wc_post_id_123',
                    description: 'Prefixed with wc_post_id_',
                  },
                  {
                    value: 'sku',
                    label: 'SKU',
                    example: 'TRDDS64',
                    description: 'Falls back to product ID if SKU is empty',
                  },
                  {
                    value: 'legacy',
                    label: 'Legacy',
                    example: 'product_123',
                    description: 'Original format (pre-2.x)',
                  },
                  {
                    value: 'off',
                    label: 'Off',
                    example: null,
                    description: 'No product ID sent, contents omitted',
                  },
                ] as const;

                const selected = formatOptions.find(
                  (o) => o.value === generalOptions.woo_product_id_format,
                );

                return (
                  <>
                    <Dropdown.Root>
                      <Dropdown.Trigger asChild>
                        <button
                          type="button"
                          disabled={isSaving}
                          className="inline-flex items-center justify-between gap-2 w-56 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm hover:border-gray-400 disabled:opacity-60 disabled:cursor-not-allowed"
                        >
                          <span>
                            {selected ? (
                              <>
                                {selected.label}
                                {selected.example && (
                                  <code className="ml-2 px-1.5 py-0.5 text-xs bg-gray-100 border border-gray-200 rounded font-mono">
                                    {selected.example}
                                  </code>
                                )}
                              </>
                            ) : (
                              'Select format'
                            )}
                          </span>
                          <span className="text-gray-400">▾</span>
                        </button>
                      </Dropdown.Trigger>
                      <Dropdown.Content>
                        {formatOptions.map(({ value, label, example }) => (
                          <Dropdown.Item
                            key={value}
                            onSelect={() => handleProductIdFormatChange(value)}
                          >
                            {label}
                            {example && (
                              <code className="ml-2 px-1.5 py-0.5 text-xs bg-gray-100 border border-gray-200 rounded font-mono">
                                {example}
                              </code>
                            )}
                          </Dropdown.Item>
                        ))}
                      </Dropdown.Content>
                    </Dropdown.Root>
                    {selected && (
                      <p className="text-sm text-gray-500">{selected.description}</p>
                    )}
                  </>
                );
              })()}
            </div>
            <div className="flex gap-3 [@media(max-width:1100px)]:flex-wrap flex-col">
              <h4 className="font-semibold !mb-0 !text-foreground !text-lg">Additional options</h4>
              <div
                className={`flex items-center gap-3${generalOptions.woo_disable_add_to_cart === 1 ? ' opacity-40 pointer-events-none' : ''}`}
              >
                <UI.Switch.Root
                  checked={generalOptions.woo_disable_add_to_cart_freebies === 0}
                  onCheckedChange={() => handleToggleOption('woo_disable_add_to_cart_freebies')}
                  id="woo_disable_add_to_cart_freebies"
                  variant={'green'}
                  disabled={isSaving || generalOptions.woo_disable_add_to_cart === 1}
                ></UI.Switch.Root>
                <UI.TooltipRoot>
                  <UI.TooltipTrigger asChild>
                    <UI.Label.Root
                      className="cursor-pointer"
                      htmlFor="woo_disable_add_to_cart_freebies"
                    >
                      <span>
                        Enable <b>Add to Cart</b> event for <b>free products</b>
                      </span>
                    </UI.Label.Root>
                  </UI.TooltipTrigger>
                  <UI.TooltipContent>
                    When this option is disabled, products with a price of zero will not trigger Add
                    to Cart events.
                  </UI.TooltipContent>
                </UI.TooltipRoot>
              </div>
              <div
                className={`flex items-center gap-3${generalOptions.woo_disable_initiate_checkout === 1 ? ' opacity-40 pointer-events-none' : ''}`}
              >
                <UI.Switch.Root
                  checked={generalOptions.woo_disable_initiate_checkout_freebies === 0}
                  onCheckedChange={() =>
                    handleToggleOption('woo_disable_initiate_checkout_freebies')
                  }
                  id="woo_disable_initiate_checkout_freebies"
                  variant={'green'}
                  disabled={isSaving || generalOptions.woo_disable_initiate_checkout === 1}
                ></UI.Switch.Root>
                <UI.TooltipRoot>
                  <UI.TooltipTrigger asChild>
                    <UI.Label.Root
                      className="cursor-pointer"
                      htmlFor="woo_disable_initiate_checkout_freebies"
                    >
                      <span>
                        Enable <b>Initiate Checkout</b> event for <b>free products</b>
                      </span>
                    </UI.Label.Root>
                  </UI.TooltipTrigger>
                  <UI.TooltipContent>
                    When this option is disabled, if current cart contains only free products, the
                    Initiate Checkout event will not be triggered. It does not interfere the
                    Checkout process itself
                  </UI.TooltipContent>
                </UI.TooltipRoot>
              </div>
              <div
                className={`flex items-center gap-3${generalOptions.woo_disable_purchase === 1 ? ' opacity-40 pointer-events-none' : ''}`}
              >
                <UI.Switch.Root
                  checked={generalOptions.woo_disable_purchase_freebies === 0}
                  onCheckedChange={() => handleToggleOption('woo_disable_purchase_freebies')}
                  id="woo_disable_purchase_freebies"
                  variant={'green'}
                  disabled={isSaving || generalOptions.woo_disable_purchase === 1}
                ></UI.Switch.Root>
                <UI.TooltipRoot>
                  <UI.TooltipTrigger asChild>
                    <UI.Label.Root
                      className="cursor-pointer"
                      htmlFor="woo_disable_purchase_freebies"
                    >
                      <span>
                        Enable <b>Purchase</b> event for <b>free products</b>
                      </span>
                    </UI.Label.Root>
                  </UI.TooltipTrigger>
                  <UI.TooltipContent>
                    When this option is disabled, if current cart contains only free products, the
                    Purchase event will not be triggered. It does not interfere the Purchase process
                    itself
                  </UI.TooltipContent>
                </UI.TooltipRoot>
              </div>
            </div>
            <div className="flex gap-3 [@media(max-width:1100px)]:flex-wrap flex-col">
              <h4 className="font-semibold !mb-0 !text-foreground !text-lg">Excluded SKUs</h4>
              <p className="text-sm text-foreground pt-0">
                Products with these SKUs will not trigger any tracking events (Add to Cart,{' '}
                <UI.TooltipRoot>
                  <UI.TooltipTrigger asChild>
                    <span className="inline-flex items-center gap-0.5 cursor-default">
                      Checkout
                      <span className="inline-flex items-center justify-center w-3.5 h-3.5 rounded-full bg-gray-300 text-gray-600 text-[9px] font-bold leading-none select-none">
                        ?
                      </span>
                    </span>
                  </UI.TooltipTrigger>
                  <UI.TooltipContent>
                    Checkout event is skipped only when every item in the cart has an excluded SKU.
                    If at least one non-excluded product is in the cart, the event fires normally.
                  </UI.TooltipContent>
                </UI.TooltipRoot>
                ,{' '}
                <UI.TooltipRoot>
                  <UI.TooltipTrigger asChild>
                    <span className="inline-flex items-center gap-0.5 cursor-default">
                      Purchase
                      <span className="inline-flex items-center justify-center w-3.5 h-3.5 rounded-full bg-gray-300 text-gray-600 text-[9px] font-bold leading-none select-none">
                        ?
                      </span>
                    </span>
                  </UI.TooltipTrigger>
                  <UI.TooltipContent>
                    Purchase event is skipped only when every item in the order has an excluded SKU.
                    If at least one non-excluded product is in the order, the event fires normally.
                  </UI.TooltipContent>
                </UI.TooltipRoot>
                ). Type a SKU (case sensitive) and press{' '}
                <kbd className="px-1 py-0.5 text-xs border border-gray-300 rounded">Enter</kbd> to
                add.
              </p>
              <div className="w-100">
                <UI.Input.Root>
                  <UI.Input.Wrapper>
                    <UI.Input.Input
                      value={skuInput}
                      name="skuInput"
                      id="skuInput"
                      onChange={(e) => setSkuInput(e.target.value)}
                      onKeyDown={handleSkuKeyDown}
                      placeholder="Type a SKU and press Enter"
                      disabled={isSaving}
                    />
                  </UI.Input.Wrapper>
                </UI.Input.Root>
              </div>
              <div className="flex flex-wrap gap-2 min-h-8">
                {(generalOptions.woo_excluded_skus || []).map((sku) => (
                  <span
                    key={sku}
                    className="inline-flex items-center gap-1 px-2 py-1 text-sm bg-gray-100 border border-gray-300 rounded"
                  >
                    {sku}
                    <button
                      type="button"
                      onClick={() => handleRemoveSku(sku)}
                      disabled={isSaving}
                      className="text-gray-500 hover:text-gray-800 leading-none disabled:opacity-40"
                      aria-label={`Remove SKU ${sku}`}
                    >
                      ×
                    </button>
                  </span>
                ))}
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
