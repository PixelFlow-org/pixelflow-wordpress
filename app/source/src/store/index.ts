/**
 * @fileoverview Redux store configuration
 * @description Configures Redux store with WordPress-specific reducers
 */

/** Store */
import { createPixelFlowStore, CoreRootState } from '@pixelflow-org/plugin-core';
import woocommerceReducer from '@/features/woocommerce/store/slices';

/** Types */
import { WooCommerceState } from '@/features/woocommerce/types';

// Create store with WordPress-specific reducers
export const { store, persistor } = createPixelFlowStore({
  additionalReducers: {
    woocommerce: woocommerceReducer,
  },
});

// Extended RootState with WordPress-specific slices
export type WordPressRootState = CoreRootState & {
  woocommerce: WooCommerceState;
};

// Re-export dispatch and store types
export type AppDispatch = typeof store.dispatch;
export type AppStore = typeof store;
