/** PixelFlow Plugin Packages */
import { useTheme } from '@pixelflow-org/plugin-ui';
import { NarrowButton } from '@pixelflow-org/plugin-ui';

/** Local Components */
import SunIcon from '@/shared/icons/sun.icon';
import MoonIcon from '@/shared/icons/moon.icon';
import LogoutIcon from '@/shared/icons/logout.icon';

/**
 * TopControls component
 * @description Theme switcher and logout button positioned at top right
 */
const TopControls = ({ handleLogout }: { handleLogout: () => Promise<void> }) => {
  const { resolvedTheme, setTheme } = useTheme();

  const toggleTheme = () => {
    const newTheme = resolvedTheme === 'dark' ? 'light' : 'dark';
    setTheme?.(newTheme);

    // Update the DOM attributes for immediate visual feedback
    document.documentElement.setAttribute('data-theme', newTheme);
    localStorage.setItem('pixelflow_theme', newTheme);
    const root = document.getElementById('root');
    if (root) {
      root.setAttribute('data-theme', newTheme);
    }
    document.body.setAttribute('data-theme', newTheme);
  };

  return (
    <div className="flex justify-end items-center gap-2 mb-[20px]">
      <NarrowButton
        onClick={toggleTheme}
        width="!w-[110px]"
        className="justify-center"
        title={`Switch to ${resolvedTheme === 'dark' ? 'light' : 'dark'} mode`}
      >
        {resolvedTheme === 'dark' ? <SunIcon /> : <MoonIcon />}
        <span className="text-xs">{resolvedTheme === 'dark' ? 'Light Mode' : 'Dark Mode'}</span>
      </NarrowButton>
      <NarrowButton onClick={handleLogout} width="!w-[85px]" className="justify-center">
        <LogoutIcon />
        <span className="text-xs">Log Out</span>
      </NarrowButton>
    </div>
  );
};

export default TopControls;
