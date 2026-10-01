/**
 * @fileoverview Form select
 * @description A single-choice dropdown built from the UI kit's button and dropdown menu, as the
 * kit's own currency picker is. The closed control can show a shorter text than the open list.
 */

/** UI Components */
import * as UI from '@pixelflow-org/plugin-ui';

export type FormSelectOption = {
  value: string;
  /** Text in the open list. */
  label: string;
  /** Text on the closed control when this option is selected; the label when absent. */
  selectedLabel?: string;
};

type FormSelectProps = {
  id: string;
  ariaLabel: string;
  value: string;
  options: FormSelectOption[];
  disabled?: boolean;
  className?: string;
  onChange: (value: string) => void;
};

/**
 * Chevron component
 * @param props - Component props
 * @returns Down chevron that turns up when open
 */
export function Chevron({ open = false }: { open?: boolean }) {
  return (
    <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
      <path
        d={open ? 'M2.5 7.5 L6 4 L9.5 7.5' : 'M2.5 4.5 L6 8 L9.5 4.5'}
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

/**
 * FormSelect component
 * @param props - Component props
 * @returns Dropdown showing the selected option
 */
export function FormSelect(props: FormSelectProps) {
  const { id, ariaLabel, value, options, disabled, className, onChange } = props;
  const selected = options.find((option) => option.value === value);

  return (
    <UI.Dropdown.Root>
      <UI.Dropdown.Trigger asChild disabled={disabled}>
        <UI.Button.Root
          id={id}
          type="button"
          variant="neutral"
          mode="stroke"
          size="small"
          aria-label={ariaLabel}
          data-value={value}
          disabled={disabled}
          className={UI.cn('!justify-between !font-normal', className)}
        >
          <span className="truncate text-left">
            {selected ? (selected.selectedLabel ?? selected.label) : value}
          </span>
          <Chevron />
        </UI.Button.Root>
      </UI.Dropdown.Trigger>
      <UI.Dropdown.Content
        align="start"
        className="max-h-[40vh] w-auto min-w-[var(--radix-dropdown-menu-trigger-width)]"
      >
        {options.map((option) => (
          <UI.Dropdown.Item
            key={option.value}
            aria-current={option.value === value}
            className={`cursor-pointer ${option.value === value ? '!bg-muted font-medium' : ''}`}
            onSelect={() => {
              if (option.value !== value) {
                onChange(option.value);
              }
            }}
          >
            {option.label}
          </UI.Dropdown.Item>
        ))}
      </UI.Dropdown.Content>
    </UI.Dropdown.Root>
  );
}
