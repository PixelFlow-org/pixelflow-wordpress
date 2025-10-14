import * as UI from '@pixelflow-org/plugin-ui';
import type { WooClassItem, PixelFlowClasses } from './settings.types';

interface WooClassSectionProps {
  title: string;
  items: WooClassItem[];
  values: PixelFlowClasses;
  onUpdate: <K extends keyof PixelFlowClasses>(key: K, value: PixelFlowClasses[K]) => void;
  sectionKey: string;
}

export function WooClassSection({
  title,
  items,
  values,
  onUpdate,
  sectionKey,
}: WooClassSectionProps) {
  const handleToggleAll = () => {
    const allChecked = items.every((item) => values[item.key] === 1);
    const newValue = allChecked ? 0 : 1;
    items.forEach((item) => onUpdate(item.key, newValue));
  };

  const allChecked = items.every((item) => values[item.key] === 1);

  return (
    <div className="mb-6 w-full max-w-sm">
      <div className="flex items-center justify-between mb-3">
        <h4 className="text-sm font-semibold !text-foreground">{title}</h4>
        <button
          type="button"
          onClick={handleToggleAll}
          className="button-primary text-xs text-foreground hover:opacity-80 focus:outline-none cursor-pointer"
        >
          [{allChecked ? 'Uncheck All' : 'Check All'}]
        </button>
      </div>
      <div className="space-y-3">
        {items.map((item) => {
          const id = '' + sectionKey + item.key + item.className;
          return (
            <div key={item.key} className="space-y-1">
              <div className="flex items-center gap-2">
                <UI.Switch.Root
                  checked={values[item.key] === 1}
                  onCheckedChange={(checked) => onUpdate(item.key, checked ? 1 : 0)}
                  id={id}
                ></UI.Switch.Root>
                <UI.Label.Root htmlFor={id}>
                  <code className="text-xs bg-gray-100 px-2 py-1 rounded">{item.className}</code>
                </UI.Label.Root>
              </div>
              <p className="text-xs text-foreground ml-12">{item.description}</p>
            </div>
          );
        })}
      </div>
    </div>
  );
}
