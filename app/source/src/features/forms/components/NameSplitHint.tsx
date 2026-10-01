/**
 * @fileoverview Name split hint
 * @description Notice that one name field can supply both the first and the last name, with
 * the rule spelled out on request
 */

/** External libraries */
import { useState } from 'react';

/** UI Components */
import * as UI from '@pixelflow-org/plugin-ui';

/** Components */
import { Chevron } from '@/features/forms/components/FormSelect';
import Notification from '@/shared/components/Notification/Notification.tsx';

/**
 * NameSplitHint component
 * @returns Notice above the form list
 */
export function NameSplitHint() {
  const [open, setOpen] = useState(false);

  return (
    <Notification
      type="success"
      icon={
        <span className="flex shrink-0 self-start mt-1">
          <UI.FaqIcon />
        </span>
      }
      message={
        <>
          One name field on the form? Choose it for both First name and Last name, and it is split
          automatically.{' '}
          <button
            type="button"
            aria-expanded={open}
            onClick={() => setOpen(!open)}
            className="inline-flex items-center gap-1 underline cursor-pointer"
          >
            How it works
            <Chevron open={open} />
          </button>
          {open && (
            <span className="block mt-2 font-normal" data-testid="forms-name-split-details">
              When First name and Last name read the same field, its value is split on the first
              space: the first word is sent as the first name and the rest as the last name.
              &quot;Ada King Lovelace&quot; becomes first name &quot;Ada&quot; and last name
              &quot;King Lovelace&quot;. A value with no space is sent as the first name only.
            </span>
          )}
        </>
      }
    />
  );
}
