import * as UI from '@pixelflow-org/plugin-ui';
import { useSettings } from '@/features/settings/contexts/SettingsContext.tsx';

export function AdvancedSettings() {
  const { generalOptions, availableRoles, toggleExcludedRole, saveSettings, isSaving } =
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
      </div>
    </div>
  );
}
