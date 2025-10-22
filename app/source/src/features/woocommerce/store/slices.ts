/** External libraries */
import { createSlice, PayloadAction } from '@reduxjs/toolkit';
import { WooCommerceState } from '../types';

/** Types */

export const initialState: WooCommerceState = {
  isWooCommerceActive: false,
};

/**
 * Pixel setup slice manages pixel setup data state
 * Using Redux Toolkit's createSlice for automatic action creator generation
 * This reduces boilerplate and ensures type safety
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
