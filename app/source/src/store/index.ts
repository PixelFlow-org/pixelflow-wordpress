// NOTE: These imports will work after pixelflow-plugin-core is built and published
// Until then, linter errors are expected (this file won't be used until migration)
import { createPixelFlowStore, CoreRootState } from '@pixelflow-org/plugin-core';
import woocommerceReducer from '@/features/woocommerce/store/slices';
import { WooCommerceState } from '@/features/woocommerce/types';

// Create store with additional reducers
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
