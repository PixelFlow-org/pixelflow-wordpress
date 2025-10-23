/**
 * @fileoverview Typed Redux hooks
 * @description Typed versions of React-Redux hooks for WordPress plugin store
 */

/** External libraries */
import { useDispatch, useSelector, useStore } from 'react-redux';

/** Types */
import type { AppDispatch, AppStore, WordPressRootState } from './index';

// Typed hooks for Redux store access
export const useAppDispatch = useDispatch.withTypes<AppDispatch>();
export const useAppSelector = useSelector.withTypes<WordPressRootState>();
export const useAppStore = useStore.withTypes<AppStore>();
