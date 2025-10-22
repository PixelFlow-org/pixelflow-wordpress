'use client';

/** External libraries */
import { createSelector } from 'reselect';

/** Store */
import { useAppSelector } from '@/store/hooks';
import { WordPressRootState } from '@/store';
import { WooCommerceState } from '@/features/woocommerce/types';

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
