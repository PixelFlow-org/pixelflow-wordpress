/**
 * @fileoverview Form row
 * @description One form: switch, event, mapped-identifier summary and the expandable panel
 */

/** UI Components */
import * as UI from '@pixelflow-org/plugin-ui';

/** Components */
import { FormMappingPanel } from '@/features/forms/components/FormMappingPanel';
import { Chevron, FormSelect } from '@/features/forms/components/FormSelect';

/** Utils */
import {
  IDENTIFIER_LABELS,
  WARNING_TEXT_STYLE,
  effectiveEnabled,
  effectiveEvent,
  mappedIdentifiers,
  unconfirmedField,
} from '@/features/forms/utils';

/** Types */
import type { FormIdentifier, FormPatch, FormRow } from '@/features/forms/types';

type FormRowItemProps = {
  row: FormRow;
  events: string[];
  identifiers: FormIdentifier[];
  isSaving: boolean;
  open: boolean;
  onToggleOpen: () => void;
  onSave: (patch: FormPatch) => void;
};

/**
 * FormRowItem component
 * @param props - Component props
 * @returns One row of the form list
 */
export function FormRowItem(props: FormRowItemProps) {
  const { row, events, identifiers, isSaving, open, onToggleOpen, onSave } = props;

  const enabled = effectiveEnabled(row);
  const event = effectiveEvent(row);
  const mapped = mappedIdentifiers(row, identifiers);
  const unconfirmed = identifiers.filter(
    (identifier) => unconfirmedField(row, identifier) !== null
  );
  const switchId = `form-switch-${row.key}`;
  const untouchedMedium = row.confidence === 'medium' && row.record.enabled === undefined;

  const eventId = `form-event-${row.key}`;

  return (
    <li className="py-4 border-b border-gray-200" data-testid={`form-row-${row.key}`}>
      <div className="flex items-start gap-3">
        <div className="flex h-5 items-center">
          <UI.Switch.Root
            id={switchId}
            aria-label={`Send events for ${row.title}`}
            checked={enabled && !row.missing}
            disabled={row.missing || isSaving}
            onCheckedChange={(checked: boolean) => onSave({ enabled: checked ? 1 : 0 })}
            variant={'green'}
          />
        </div>
        <div className="flex-1 min-w-0">
          <UI.Label.Root htmlFor={switchId} className="block text-sm leading-5 font-semibold">
            {row.title}
          </UI.Label.Root>
          <p className="!m-0 text-xs text-gray-500 italic">plugin: {row.source_label}</p>
          <p className="!m-0 !mt-1 text-xs text-gray-600" data-testid={`form-summary-${row.key}`}>
            {mapped.length > 0
              ? `Sends: ${mapped.map((id) => IDENTIFIER_LABELS[id]).join(', ')}`
              : 'No identifiers mapped'}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <UI.Label.Root htmlFor={eventId}>Event</UI.Label.Root>
          <FormSelect
            id={eventId}
            ariaLabel={`Event for ${row.title}`}
            value={event}
            options={events.map((name) => ({ value: name, label: name }))}
            disabled={row.missing || isSaving}
            className="!w-[210px]"
            onChange={(name) => onSave({ event: name })}
          />
        </div>
        <UI.Button.Root
          type="button"
          variant="primary"
          mode="ghost"
          size="small"
          aria-expanded={open}
          aria-label={`Configure ${row.title}`}
          onClick={onToggleOpen}
          className="!w-[150px]"
        >
          {open ? 'Hide fields' : 'Configure fields'}
          <Chevron open={open} />
        </UI.Button.Root>
      </div>

      {row.missing && (
        <p className="mt-2 ml-12 text-xs text-red-800" role="status">
          This form no longer exists on the site. Its event and field choices are kept, and apply
          again if the form comes back.
        </p>
      )}
      {!row.missing && unconfirmed.length > 0 && (
        <p
          className="mt-2 ml-12 text-xs"
          style={WARNING_TEXT_STYLE}
          role="status"
          data-testid={`form-unconfirmed-${row.key}`}
        >
          {`Not sent until you choose the field: ${unconfirmed.map((id) => IDENTIFIER_LABELS[id]).join(', ')}. A field's label only contains a word for it.`}
        </p>
      )}
      {!row.missing && untouchedMedium && (
        <p className="mt-2 ml-12 text-xs text-gray-700">
          PixelFlow could not confirm this form collects an email address or phone number, so it is
          off. The suggested event is filled in; turn the form on to start sending.
        </p>
      )}

      {open && (
        <FormMappingPanel
          row={row}
          identifiers={identifiers}
          disabled={row.missing || isSaving}
          onSave={onSave}
        />
      )}
    </li>
  );
}
