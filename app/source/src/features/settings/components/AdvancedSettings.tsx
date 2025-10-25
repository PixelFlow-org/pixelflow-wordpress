/**
 * @fileoverview Advanced Settings component
 * @description Configuration for advanced options like user role exclusion
 */

/** UI Components */
import * as UI from '@pixelflow-org/plugin-ui';

/** Hooks */
import { useSettings } from '@/features/settings/contexts/SettingsContext.tsx';

/**
 * AdvancedSettings component
 * @description Provides interface to exclude specific user roles from tracking script injection
 * @returns AdvancedSettings component
 */
export function AdvancedSettings() {
  const { generalOptions, availableRoles, toggleExcludedRole, saveSettings, isSaving, updateGeneralOption } =
    useSettings();
  const excludedRoles = generalOptions.excluded_user_roles || [];

  const handleRoleToggle = async (roleKey: string) => {
    toggleExcludedRole(roleKey);

    // Calculate new excluded roles array
    const current = excludedRoles;
    const updated = current.includes(roleKey)
      ? current.filter((r) => r !== roleKey)
      : [...current, roleKey];

    // Save immediately with override
    await saveSettings({ generalOptionsOverride: { excluded_user_roles: updated } });
  };

  const handleUninstallToggle = async (checked: boolean) => {
    const newValue = checked ? 1 : 0;
    updateGeneralOption('remove_on_uninstall', newValue);

    // Save immediately
    await saveSettings({ generalOptionsOverride: { remove_on_uninstall: newValue } });
  };

  return (
    <div className="max-w-6xl py-3">
      <div className=" rounded-lg shadow-sm border border-gray-200 p-6">
        {/* User Role Exclusion */}
        {availableRoles.length > 0 && (
          <section>
            <h3 className="text-base font-semibold mb-2 !text-foreground">
              Exclude Script for User Roles
            </h3>
            <p className="text-sm text-foreground ml-12">
              Select user roles that should NOT have the tracking script injected when they visit
              the site. Useful for excluding administrators, editors, or other internal users.
            </p>
            <div className="space-y-2">
              {availableRoles.map((role) => {
                const isExcluded = excludedRoles.includes(role.key);
                const roleId = `exclude-role-${role.key}`;
                return (
                  <div key={role.key} className="flex items-center gap-3">
                    <UI.Switch.Root
                      checked={isExcluded}
                      onCheckedChange={() => handleRoleToggle(role.key)}
                      id={roleId}
                      variant={'green'}
                      disabled={isSaving}
                    ></UI.Switch.Root>
                    <UI.Label.Root className="cursor-pointer" htmlFor={roleId}>
                      <span className="text-sm">{role.label}</span>
                      <span className="text-xs text-gray-500 ml-2">({role.key})</span>
                    </UI.Label.Root>
                  </div>
                );
              })}
            </div>
            {excludedRoles.length > 0 && (
              <p className="text-xs text-gray-600 mt-3">
                <strong>Note:</strong> Users with excluded roles will not have tracking script
                injected.
              </p>
            )}
          </section>
        )}

        {/* Data Cleanup Section */}
        <section className={availableRoles.length > 0 ? 'mt-6 pt-6 border-t border-gray-200' : ''}>
          <h3 className="text-base font-semibold mb-2 !text-foreground">
            Plugin Data Management
          </h3>
          <p className="text-sm text-foreground ml-12 mb-4">
            Control what happens to your plugin data when you uninstall PixelFlow.
          </p>
          <div className="flex items-center gap-3">
            <UI.Switch.Root
              checked={generalOptions.remove_on_uninstall === 1}
              onCheckedChange={handleUninstallToggle}
              id="remove-on-uninstall"
              variant={'green'}
              disabled={isSaving}
            ></UI.Switch.Root>
            <UI.Label.Root className="cursor-pointer" htmlFor="remove-on-uninstall">
              <span className="text-sm">Remove plugin data on uninstall</span>
            </UI.Label.Root>
          </div>
          <p className="text-xs text-gray-600 mt-3 ml-11">
            {generalOptions.remove_on_uninstall === 1 ? (
              <>
                <strong>Enabled:</strong> When you delete this plugin, all PixelFlow settings,
                tracking scripts, and configurations will be permanently removed from your database.
              </>
            ) : (
              <>
                <strong>Disabled:</strong> Your PixelFlow settings will be preserved in the
                database even after uninstalling the plugin. This allows you to reinstall later
                without losing your configuration.
              </>
            )}
          </p>
        </section>
      </div>
    </div>
  );
}
