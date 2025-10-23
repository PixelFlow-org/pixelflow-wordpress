/**
 * @fileoverview WooCommerce state hook
 * @description Custom hook for accessing WooCommerce activation state from Redux store
 */

'use client';

/** External libraries */
import { createSelector } from 'reselect';

/** Store */
import { useAppSelector } from '@/store/hooks';
import { WordPressRootState } from '@/store';

/** Types */
import { WooCommerceState } from '@/features/woocommerce/types';

/**
 * Hook to access WooCommerce state
 * @description Provides memoized selector for WooCommerce activation status
 * @returns Object containing WooCommerce active state
 */
const useWoocommerce = () => {
  const selectWoocommerceReducer = (state: WordPressRootState) => state.woocommerce;
  const isWooCommerceActive = useAppSelector(
    createSelector(
      [selectWoocommerceReducer],
      (woocommerce: WooCommerceState) => woocommerce.isWooCommerceActive
    )
  );

  return {
    isWooCommerceActive,
  };
};

export default useWoocommerce;
