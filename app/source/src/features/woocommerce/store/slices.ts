/**
 * @fileoverview WooCommerce Redux slice
 * @description Redux state management for WooCommerce integration status
 */

/** External libraries */
import { createSlice, PayloadAction } from '@reduxjs/toolkit';

/** Types */
import { WooCommerceState } from '@/features/woocommerce/types';

export const initialState: WooCommerceState = {
  isWooCommerceActive: false,
};

/**
 * WooCommerce slice
 * @description Manages WooCommerce activation state in Redux store.
 * Uses Redux Toolkit's createSlice for automatic action creator generation
 * to reduce boilerplate and ensure type safety.
 */
export const WooCommerceSlice = createSlice({
  name: 'woocommerce',
  initialState,
  reducers: {
    setIsWooCommerceActiveReducer: (state, action: PayloadAction<boolean>) => {
      state.isWooCommerceActive = action.payload;
    },
  },
});

export const { setIsWooCommerceActiveReducer } = WooCommerceSlice.actions;
export default WooCommerceSlice.reducer;
