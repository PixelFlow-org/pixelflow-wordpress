import * as UI from '@pixelflow-org/plugin-ui';
import type { WooClassItem, PixelFlowClasses } from './settings.types';

interface WooClassSectionProps {
  title: string;
  items: WooClassItem[];
  values: PixelFlowClasses;
  onUpdate: <K extends keyof PixelFlowClasses>(key: K, value: PixelFlowClasses[K]) => void;
  sectionKey: string;
  isDebugMode?: boolean;
}

export function WooClassSection({
  title,
  items,
  values,
  onUpdate,
  sectionKey,
  isDebugMode = false,
}: WooClassSectionProps) {
  const handleToggleAll = () => {
    const allChecked = items.every((item) => values[item.key] === 1);
    const newValue = allChecked ? 0 : 1;
    items.forEach((item) => onUpdate(item.key, newValue));
  };

  const allChecked = items.every((item) => values[item.key] === 1);

  // Map class names to their debug colors
  const debugColors: Record<string, string> = {
    'info-pdct-ctnr-pf': 'green',
    'info-pdct-name-pf': 'red',
    'info-pdct-price-pf': 'blue',
    'info-pdct-qnty-pf': 'orange',
    'action-btn-cart-005-pf': '#fc0390',
    'action-btn-buy-004-pf': '#67a174',
    'info-chk-itm-ctnr-pf': 'green',
    'info-chk-itm-pf': 'rgba(0,0,0,0.1)',
    'info-itm-name-pf': 'orange',
    'info-itm-prc-pf': 'blue',
    'info-itm-qnty-pf': '#03adfc',
    'info-totl-amt-pf': '#b103fc',
    'action-btn-plc-ord-018-pf': '#b01a81',
    'info-pdct-ctnr-list-pf': '#fcdb03',
  };

  return (
    <div className="mb-6 w-full max-w-1/3">
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
          const debugColor = debugColors[item.className];
          return (
            <div key={item.key} className="space-y-1">
              <div className="flex items-center gap-2">
                <UI.Switch.Root
                  checked={values[item.key] === 1}
                  onCheckedChange={(checked) => onUpdate(item.key, checked ? 1 : 0)}
                  id={id}
                ></UI.Switch.Root>
                <UI.Label.Root htmlFor={id} className="flex items-center gap-2">
                  <code className="text-xs bg-gray-100 px-2 py-1 rounded">{item.className}</code>
                  {isDebugMode && debugColor && (
                    <span
                      className="inline-block w-4 h-4 border-2 rounded"
                      style={{ borderColor: debugColor }}
                      title={`Debug border color: ${debugColor}`}
                    ></span>
                  )}
                </UI.Label.Root>
              </div>
              <p className="text-xs text-foreground ml-12">
                {item.description}
                {isDebugMode && debugColor && (
                  <span className="text-gray-500">
                    {' '}
                    → Will show{' '}
                    <span style={{ color: debugColor }} className="font-semibold">
                      {debugColor}
                    </span>{' '}
                    border
                  </span>
                )}
              </p>
            </div>
          );
        })}
      </div>
    </div>
  );
}
