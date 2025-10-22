import { useDispatch, useSelector, useStore } from 'react-redux';
import type { AppDispatch, AppStore, WordPressRootState } from './index';

export const useAppDispatch = useDispatch.withTypes<AppDispatch>();
export const useAppSelector = useSelector.withTypes<WordPressRootState>();
export const useAppStore = useStore.withTypes<AppStore>();
