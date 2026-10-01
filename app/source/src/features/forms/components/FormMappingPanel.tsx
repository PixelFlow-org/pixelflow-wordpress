/**
 * @fileoverview Form mapping panel
 * @description Per-identifier field choice and the static value of one form
 */

/** External libraries */
import { useState } from 'react';

/** UI Components */
import * as UI from '@pixelflow-org/plugin-ui';

/** Components */
import { FormSelect } from '@/features/forms/components/FormSelect';

/** Utils */
import {
  IDENTIFIER_LABELS,
  WARNING_ROW_STYLE,
  WARNING_TEXT_STYLE,
  choiceForField,
  choiceIsStale,
  confirmFieldPatch,
  effectiveFieldKey,
  identifierState,
  storedChoice,
  unconfirmedField,
} from '@/features/forms/utils';

/** Types */
import type {
  FormField,
  FormIdentifier,
  FormPatch,
  FormRow,
  IdentifierState,
} from '@/features/forms/types';
import type { FormSelectOption } from '@/features/forms/components/FormSelect';

const NONE = '__none__';

/** How a detected field was arrived at, in words. A chosen field and no field say nothing. */
const STATE_LABELS: Record<IdentifierState, string> = {
  auto: 'Found by field type',
  suggested: 'Found by field name',
  custom: '',
  unconfirmed: 'Needs your choice',
  empty: '',
};

/** A field as the dropdown names it. */
function fieldName(field: FormField): string {
  return field.label || field.key;
}

type FormMappingPanelProps = {
  row: FormRow;
  identifiers: FormIdentifier[];
  disabled: boolean;
  onSave: (patch: FormPatch) => void;
};

/**
 * FormMappingPanel component
 * @param props - Component props
 * @returns Expanded panel of one form row
 */
export function FormMappingPanel(props: FormMappingPanelProps) {
  const { row, identifiers, disabled, onSave } = props;
  const [value, setValue] = useState(
    row.record.value !== undefined ? String(row.record.value) : ''
  );

  const saveValue = () => {
    const trimmed = value.trim();
    const current = row.record.value !== undefined ? String(row.record.value) : '';
    if (trimmed === current) {
      return;
    }
    onSave({ value: trimmed === '' ? null : Number(trimmed) });
  };

  const onFieldChange = (identifier: FormIdentifier, selected: string) => {
    const detected = row.detected[identifier];
    // Nothing to suppress when no field is detected, so the dash stores nothing there and a
    // field added to the form later is still picked up.
    if (selected === NONE && detected) {
      onSave({ fields: { [identifier]: { key: '', type: '', label: '' } } });
      return;
    }
    // Neither does the detected field need a stored choice: picking it returns to detection.
    if (selected === NONE || selected === detected) {
      onSave({ fields: { [identifier]: null } });
      return;
    }
    const field = row.fields.find((f) => f.key === selected);
    if (field) {
      onSave({ fields: { [identifier]: choiceForField(field) } });
    }
  };

  const valueId = `form-value-${row.key}`;
  const nameKey = effectiveFieldKey(row, 'fn');
  const nameIsSplit = nameKey !== null && nameKey === effectiveFieldKey(row, 'ln');

  return (
    <div className="mt-3 ml-12 space-y-4" data-testid={`form-panel-${row.key}`}>
      <table className="text-sm">
        <tbody>
          {identifiers.map((identifier) => {
            const choice = storedChoice(row, identifier);
            const state = identifierState(row, identifier);
            const stale = choiceIsStale(row, identifier);
            const unconfirmed = unconfirmedField(row, identifier);
            const selected = choice
              ? choice.key === ''
                ? NONE
                : choice.key
              : (row.detected[identifier] ?? NONE);
            const selectId = `form-field-${row.key}-${identifier}`;
            const knownField = choice ? row.fields.find((f) => f.key === choice.key) : undefined;

            const options: FormSelectOption[] = [{ value: NONE, label: '—' }];
            if (choice && choice.key !== '' && !knownField) {
              options.push({ value: choice.key, label: `${choice.key} (removed)` });
            }
            row.fields.forEach((field) =>
              options.push({
                value: field.key,
                label: `${fieldName(field)} (${field.key} · ${field.type})`,
                selectedLabel: fieldName(field),
              })
            );

            return (
              <tr
                key={identifier}
                style={stale || unconfirmed ? WARNING_ROW_STYLE : undefined}
                data-testid={`field-row-${identifier}`}
              >
                <td className="pr-3 py-1 whitespace-nowrap">
                  <UI.Label.Root htmlFor={selectId}>{IDENTIFIER_LABELS[identifier]}</UI.Label.Root>
                </td>
                <td className="pr-3 py-1">
                  <FormSelect
                    id={selectId}
                    ariaLabel={IDENTIFIER_LABELS[identifier]}
                    value={selected}
                    options={options}
                    disabled={disabled}
                    className="!w-[260px]"
                    onChange={(next) => onFieldChange(identifier, next)}
                  />
                </td>
                <td className="py-1 pr-2">
                  <span className="text-xs text-gray-600" data-testid={`state-${identifier}`}>
                    {STATE_LABELS[state]}
                  </span>
                  {stale && (
                    <span className="ml-2 text-xs" style={WARNING_TEXT_STYLE} role="status">
                      {knownField
                        ? 'This field has changed since it was chosen.'
                        : 'This field no longer exists on the form.'}
                      {knownField && !disabled && (
                        <button
                          type="button"
                          className="ml-2 underline"
                          onClick={() =>
                            onSave({ fields: { [identifier]: choiceForField(knownField) } })
                          }
                        >
                          Keep this field
                        </button>
                      )}
                    </span>
                  )}
                  {unconfirmed && (
                    <span className="ml-2 text-xs" style={WARNING_TEXT_STYLE} role="status">
                      {`"${fieldName(unconfirmed)}" only contains a word for this, so it is not sent until you choose it.`}
                      {!disabled && (
                        <button
                          type="button"
                          className="ml-2 underline"
                          onClick={() =>
                            onSave({ fields: confirmFieldPatch(row, unconfirmed, identifiers) })
                          }
                        >
                          Use this field
                        </button>
                      )}
                    </span>
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>

      {nameIsSplit && (
        <p className="!m-0 text-xs text-gray-600" data-testid={`form-name-split-${row.key}`}>
          First name and Last name read the same field: its value is split on the first space, the
          first word as the first name and the rest as the last name.
        </p>
      )}

      <div className="flex items-center gap-3 text-sm">
        <UI.Label.Root htmlFor={valueId}>Event value (optional)</UI.Label.Root>
        <div className="w-40">
          <UI.Input.Root>
            <UI.Input.Wrapper>
              <UI.Input.Input
                id={valueId}
                type="number"
                min="0"
                step="any"
                value={value}
                disabled={disabled}
                placeholder="For example 50"
                onChange={(e) => setValue(e.target.value)}
                onBlur={saveValue}
              />
            </UI.Input.Wrapper>
          </UI.Input.Root>
        </div>
        <span className="text-xs text-gray-600">
          A number sent to Meta as what this event is worth to you. No currency is sent with it.
        </span>
      </div>
    </div>
  );
}
