/**
 * @fileoverview WooCommerce Class Section component
 * @description Configurable section for WooCommerce class tracking options
 */

/** External libraries */
import { JSX } from 'react';

/** UI Components */
import {
  Switch,
  TooltipRoot,
  TooltipTrigger,
  Label,
  TooltipContent,
  Dropdown,
  Button,
} from '@pixelflow-org/plugin-ui';

/** Types */
import type { WooClassItem, PixelFlowClasses } from '@/features/settings/types/settings.types.ts';

/** Hooks */
import { useSettings } from '@/features/settings/contexts/SettingsContext.tsx';

/** Constants */
import { debugColors } from '@/features/settings/const/classes.ts';

interface WooClassSectionProps {
  title: JSX.Element;
  comment?: string;
  items: WooClassItem[];
  sectionKey: string;
  isDebugMode?: boolean;
}

/**
 * WooClassSection component
 * @description Renders a configurable section for managing WooCommerce class tracking options
 * with toggle all functionality and per-item controls
 * @param props - Component props
 * @param props.title - Section title element
 * @param props.comment - Optional descriptive comment
 * @param props.items - Array of class items to configure
 * @param props.sectionKey - Unique section identifier
 * @param props.isDebugMode - Whether this section is for debug configuration
 * @returns WooClassSection component
 */
export function WooClassSection({
  title,
  comment,
  items,
  sectionKey,
  isDebugMode = false,
}: WooClassSectionProps) {
  const {
    classOptions,
    debugOptions,
    updateClassOption,
    updateDebugOption,
    saveSettings,
    isSaving,
  } = useSettings();

  // Use appropriate options based on mode
  const values = isDebugMode ? debugOptions : classOptions;
  const updateOption = isDebugMode ? updateDebugOption : updateClassOption;
  const optionsKey = isDebugMode ? 'debugOptions' : 'classOptions';

  const allChecked = items.every((item) => values[item.key] === 1);

  const handleToggleAll = async () => {
    const newValue = allChecked ? 0 : 1;

    // Update all items locally
    items.forEach((item) => updateOption(item.key, newValue));

    // Build override object with all items
    const override: Partial<PixelFlowClasses> = {};
    items.forEach((item) => {
      override[item.key] = newValue;
    });

    // Save immediately with override
    await saveSettings({
      [optionsKey === 'classOptions' ? 'classOptionsOverride' : 'debugOptionsOverride']: override,
    });
  };

  const handleItemToggle = async (key: keyof PixelFlowClasses, checked: boolean) => {
    const newValue = checked ? 1 : 0;
    updateOption(key, newValue);

    // Save immediately with single item override
    await saveSettings({
      [optionsKey === 'classOptions' ? 'classOptionsOverride' : 'debugOptionsOverride']: {
        [key]: newValue,
      },
    });
  };

  return (
    <div className="mb-6 w-full max-w-1/3">
      <div className="flex items-center justify-between mb-3">
        <div className="flex items-center gap-3">
          <Switch.Root
            checked={allChecked}
            onCheckedChange={handleToggleAll}
            id={`toggle-all-${sectionKey}`}
            variant={'green'}
            disabled={isSaving}
          ></Switch.Root>
          <TooltipRoot>
            <TooltipTrigger asChild>
              <Label.Root className="cursor-pointer" htmlFor={`toggle-all-${sectionKey}`}>
                <span>{title}</span>
              </Label.Root>
            </TooltipTrigger>
            <TooltipContent>Enable or disable all tracking</TooltipContent>
          </TooltipRoot>
        </div>
      </div>
      {comment && <p className="text-sm text-foreground ml-12">{comment}</p>}
      <div className="mt-5  ml-11">
        <Dropdown.Root>
          <Dropdown.Trigger asChild>
            <Button.Root size="xsmall">Advanced settings</Button.Root>
          </Dropdown.Trigger>
          <Dropdown.Content>
            <Dropdown.Label>Fine-tune the elements</Dropdown.Label>
            <div className="space-y-3">
              {items.map((item) => {
                const id = '' + sectionKey + item.key + item.className;
                const debugColor = debugColors[item.className];
                return (
                  <div key={item.key} className="space-y-1">
                    <div className="flex items-center gap-2">
                      <Switch.Root
                        checked={values[item.key] === 1}
                        onCheckedChange={(checked) => handleItemToggle(item.key, checked)}
                        id={id}
                        variant={'green'}
                        disabled={isSaving}
                      ></Switch.Root>
                      <Label.Root htmlFor={id} className="flex items-center gap-2">
                        <code className="text-xs bg-gray-100 px-2 py-1 rounded">
                          {item.description}
                        </code>
                        {isDebugMode && debugColor && (
                          <span
                            className="inline-block w-4 h-4 border-2 rounded"
                            style={{ borderColor: debugColor }}
                            title={`Debug border color: ${debugColor}`}
                          ></span>
                        )}
                      </Label.Root>
                    </div>
                    <p className="text-xs text-foreground ml-12">
                      {item.className}
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
          </Dropdown.Content>
        </Dropdown.Root>
      </div>
    </div>
  );
}
