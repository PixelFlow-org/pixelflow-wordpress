import * as UI from '@pixelflow-org/plugin-ui';

interface ActivatePixelflowProps {
  enabled: number;
  onToggle: (enabled: number) => void;
}

export function ActivatePixelflow({ enabled, onToggle }: ActivatePixelflowProps) {
  return (
    <div className="space-y-6 pf-layout-main pf-module-home bg-background text-foreground min-h-full !p-[12px]">
      <div className="flex items-center gap-3">
        <UI.TooltipRoot>
          <UI.TooltipTrigger asChild>
            <UI.Label.Root className="cursor-pointer" htmlFor="enablePixelflow">
              Activate PixelFlow
            </UI.Label.Root>
          </UI.TooltipTrigger>
          <UI.TooltipContent>
            Enable or disable the PixelFlow integration on your site
          </UI.TooltipContent>
        </UI.TooltipRoot>
        <UI.Switch.Root
          checked={enabled === 1}
          onCheckedChange={(checked) => onToggle(checked ? 1 : 0)}
          id="enablePixelflow"
        ></UI.Switch.Root>
      </div>
    </div>
  );
}
