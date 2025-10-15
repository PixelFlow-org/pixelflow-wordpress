import * as UI from '@pixelflow-org/plugin-ui';
import type { PixelFlowGeneralOptions, UserRole } from './settings.types';

interface GeneralSettingsProps {
  generalOptions: PixelFlowGeneralOptions;
  availableRoles: UserRole[];
  onUpdate: <K extends keyof PixelFlowGeneralOptions>(
    key: K,
    value: PixelFlowGeneralOptions[K]
  ) => void;
  onToggleRole: (roleKey: string) => void;
}

export function GeneralSettings({
  generalOptions,
  availableRoles,
  onUpdate,
  onToggleRole,
}: GeneralSettingsProps) {
  const excludedRoles = generalOptions.excluded_user_roles || [];

  return (
    <div className="space-y-6 pf-layout-main pf-module-home bg-background text-foreground min-h-full !p-[12px]">
      <div>
        <h2 className="text-lg font-semibold mb-4 !text-foreground">Integration Settings</h2>
        <p className="text-sm mb-6 text-foreground">
          Configure your PixelFlow integration settings below.
        </p>
      </div>

      <div className="flex items-center gap-3">
        <UI.Switch.Root
          checked={generalOptions.enabled === 1}
          onCheckedChange={(checked) => onUpdate('enabled', checked ? 1 : 0)}
          id="enablePixelflow"
        ></UI.Switch.Root>
        <UI.TooltipRoot>
          <UI.TooltipTrigger asChild>
            <UI.Label.Root className="cursor-pointer" htmlFor="enablePixelflow">
              Enable PixelFlow Integration
            </UI.Label.Root>
          </UI.TooltipTrigger>
          <UI.TooltipContent>
            Enable or disable the PixelFlow integration on your site
          </UI.TooltipContent>
        </UI.TooltipRoot>
      </div>

      {/* User Role Exclusion */}
      {generalOptions.enabled === 1 && availableRoles.length > 0 && (
        <div className="mt-6 pt-6 border-t">
          <h3 className="text-base font-semibold mb-2 !text-foreground">
            Exclude Script for User Roles
          </h3>
          <p className="text-sm text-foreground mb-4">
            Select user roles that should NOT have the tracking script injected when they visit the
            site. Useful for excluding administrators, editors, or other internal users.
          </p>
          <div className="space-y-2">
            {availableRoles.map((role) => {
              const isExcluded = excludedRoles.includes(role.key);
              const roleId = `exclude-role-${role.key}`;
              return (
                <div key={role.key} className="flex items-center gap-3">
                  <UI.Switch.Root
                    checked={isExcluded}
                    onCheckedChange={() => onToggleRole(role.key)}
                    id={roleId}
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
              injected, even if they are logged in.
            </p>
          )}
        </div>
      )}
    </div>
  );
}
