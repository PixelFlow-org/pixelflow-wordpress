/**
 * @fileoverview Forms settings UI
 * @description The Forms tab renders from the read route and saves only what a person changed.
 * The API hooks and the settings context are mocked; the components and helpers are real.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { FormsSettings } from '@/features/forms/components/FormsSettings';
import type { FormRow, FormsListing } from '@/features/forms/types';

const mockLoadForms = vi.fn();
const mockSaveForms = vi.fn();

vi.mock('@/features/forms/api', () => ({
  useLazyGetFormsQuery: () => [mockLoadForms, {}],
  useSaveFormSettingsMutation: () => [mockSaveForms, {}],
}));

const mockToast = vi.fn();

vi.mock('react-toastify', () => ({
  toast: (...args: unknown[]) => mockToast(...args),
}));

const settings = {
  generalOptions: { enabled: 1, forms_enabled: 1, forms_debug_enabled: 0 },
  updateGeneralOption: vi.fn(),
  saveSettings: vi.fn(),
  isSaving: false,
  wooDebugLogUrl: '',
};

vi.mock('@/features/settings/contexts/useSettingsContext.ts', () => ({
  useSettingsContext: () => settings,
}));

const EVENTS = ['CompleteRegistration', 'Contact', 'Lead', 'Schedule'];
const IDENTIFIERS: FormsListing['identifiers'] = [
  'em',
  'ph',
  'fn',
  'ln',
  'ct',
  'st',
  'zp',
  'country',
];

function row(overrides: Partial<FormRow> = {}): FormRow {
  return {
    key: 'cf7:12',
    source: 'cf7',
    source_label: 'Contact Form 7',
    form_id: '12',
    title: 'Contact us',
    fields: [
      { key: 'your-email', type: 'email', label: 'Email' },
      { key: 'your-phone', type: 'phone', label: 'Phone' },
      { key: 'mobile', type: 'text', label: 'Mobile' },
    ],
    confidence: 'high',
    suggested_event: 'Lead',
    detected: { em: 'your-email', ph: 'your-phone' },
    unconfirmed: {},
    record: {},
    missing: false,
    ...overrides,
  };
}

function listing(forms: FormRow[], plugins?: FormsListing['plugins']): FormsListing {
  return {
    plugins: plugins ?? [
      { id: 'cf7', label: 'Contact Form 7', active: true },
      { id: 'gravity', label: 'Gravity Forms', active: false },
    ],
    events: EVENTS,
    identifiers: IDENTIFIERS,
    forms,
  };
}

function serve(initial: FormsListing, afterSave?: FormsListing) {
  mockLoadForms.mockReturnValue({ unwrap: () => Promise.resolve(initial) });
  mockSaveForms.mockReturnValue({ unwrap: () => Promise.resolve(afterSave ?? initial) });
}

/** Opens one of the page's dropdowns and picks an option from its list. */
async function choose(control: HTMLElement, option: string | RegExp) {
  const user = userEvent.setup();
  await user.click(control);
  await user.click(await screen.findByRole('menuitem', { name: option }));
}

async function renderForms() {
  render(<FormsSettings />);
  await waitFor(() => expect(mockLoadForms).toHaveBeenCalled());
}

describe('FormsSettings', () => {
  beforeEach(() => {
    mockLoadForms.mockReset();
    mockSaveForms.mockReset();
    mockToast.mockReset();
    settings.generalOptions = { enabled: 1, forms_enabled: 1, forms_debug_enabled: 0 };
  });

  it('shows a loader until the list arrives', async () => {
    let arrive: (value: FormsListing) => void = () => {};
    mockLoadForms.mockReturnValue({
      unwrap: () => new Promise<FormsListing>((resolve) => (arrive = resolve)),
    });
    await renderForms();

    expect(screen.getByTestId('forms-loading')).toHaveTextContent('Loading...');
    expect(screen.queryByTestId('form-row-cf7:12')).toBeNull();

    arrive(listing([row()]));

    expect(await screen.findByTestId('form-row-cf7:12')).toBeInTheDocument();
    expect(screen.queryByTestId('forms-loading')).toBeNull();
  });

  it('states that the forms could not be loaded and keeps the master toggle', async () => {
    mockLoadForms.mockReturnValue({ unwrap: () => Promise.reject(new Error('refused')) });
    await renderForms();

    expect(await screen.findByText('Failed to load forms')).toBeInTheDocument();
    expect(screen.queryByTestId('forms-loading')).toBeNull();
    expect(screen.queryByTestId('form-row-cf7:12')).toBeNull();
    expect(screen.queryByTestId('forms-plugin-status')).toBeNull();
    expect(screen.queryByTestId('forms-no-plugin')).toBeNull();
    expect(screen.getByRole('switch', { name: 'Track form submissions' })).toBeEnabled();
  });

  it('states that a save failed, keeps the previous event and frees the controls', async () => {
    mockLoadForms.mockReturnValue({ unwrap: () => Promise.resolve(listing([row()])) });
    mockSaveForms.mockReturnValue({ unwrap: () => Promise.reject(new Error('refused')) });
    await renderForms();

    const event = await screen.findByRole('button', { name: 'Event for Contact us' });
    await choose(event, 'Contact');

    await waitFor(() =>
      expect(mockToast).toHaveBeenCalledWith('Failed to save form settings', { type: 'error' })
    );
    expect(mockSaveForms).toHaveBeenCalledWith({
      'cf7:12': { title: 'Contact us', event: 'Contact' },
    });
    await waitFor(() => expect(event).toBeEnabled());
    expect(event).toHaveTextContent('Lead');
    expect(screen.getByRole('switch', { name: 'Send events for Contact us' })).toBeEnabled();
  });

  it('names an inactive supported plugin as not active', async () => {
    serve(listing([row()]));
    await renderForms();

    const status = await screen.findByTestId('forms-plugin-status');
    expect(status).toHaveTextContent('Active form plugins: Contact Form 7.');
    expect(status).toHaveTextContent('Supported but not active: Gravity Forms.');
  });

  it('states that no supported plugin was detected and lists no rows', async () => {
    serve(listing([], [{ id: 'cf7', label: 'Contact Form 7', active: false }]));
    await renderForms();

    expect(await screen.findByTestId('forms-no-plugin')).toBeInTheDocument();
    expect(screen.queryByRole('listitem')).not.toBeInTheDocument();
    expect(screen.queryByTestId('forms-double-count-warning')).not.toBeInTheDocument();
  });

  it('renders the double-count warning once, above the list and in no row', async () => {
    serve(listing([row(), row({ key: 'cf7:13', form_id: '13', title: 'Quote' })]));
    await renderForms();

    await screen.findByTestId('form-row-cf7:13');
    expect(screen.getAllByTestId('forms-double-count-warning')).toHaveLength(1);
    expect(within(screen.getByTestId('form-row-cf7:12')).queryByText(/counted twice/)).toBeNull();
  });

  it('hides everything below the master toggle while it is off and brings the choices back', async () => {
    settings.generalOptions = { enabled: 1, forms_enabled: 0, forms_debug_enabled: 0 };
    serve(listing([row({ record: { event: 'Contact', enabled: 1 } })]));
    const { rerender } = render(<FormsSettings />);
    await waitFor(() => expect(mockLoadForms).toHaveBeenCalled());

    expect(screen.getByRole('switch', { name: 'Track form submissions' })).not.toBeChecked();
    expect(screen.queryByTestId('form-row-cf7:12')).toBeNull();
    expect(screen.queryByTestId('forms-plugin-status')).toBeNull();
    expect(screen.queryByTestId('forms-double-count-warning')).toBeNull();
    expect(screen.queryByTestId('forms-loading')).toBeNull();

    settings.generalOptions = { enabled: 1, forms_enabled: 1, forms_debug_enabled: 0 };
    rerender(<FormsSettings />);

    expect(await screen.findByRole('switch', { name: 'Send events for Contact us' })).toBeChecked();
    expect(screen.getByRole('button', { name: 'Event for Contact us' })).toHaveTextContent(
      'Contact'
    );
  });

  it('saves only the changed control of the changed row', async () => {
    serve(listing([row(), row({ key: 'cf7:13', form_id: '13', title: 'Quote' })]));
    await renderForms();

    await choose(await screen.findByRole('button', { name: 'Event for Quote' }), 'Schedule');

    await waitFor(() => expect(mockSaveForms).toHaveBeenCalledTimes(1));
    expect(mockSaveForms).toHaveBeenCalledWith({ 'cf7:13': { title: 'Quote', event: 'Schedule' } });
  });

  it('labels the row and says nothing about an identifier with no field', async () => {
    serve(listing([row()]));
    await renderForms();

    const item = await screen.findByTestId('form-row-cf7:12');
    expect(within(item).getByText('plugin: Contact Form 7')).toBeInTheDocument();
    expect(within(item).getByText('Event')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Configure Contact us' }));
    expect(screen.getByRole('button', { name: 'Configure Contact us' })).toHaveTextContent(
      'Hide fields'
    );
    expect(screen.getByRole('button', { name: 'Email' })).toHaveTextContent(/^Email$/);
    expect(screen.getByRole('button', { name: 'City' })).toHaveTextContent(/^—$/);
    expect(screen.getByTestId('state-ct')).toBeEmptyDOMElement();
    expect(screen.queryByTestId('form-name-split-cf7:12')).toBeNull();
    expect(within(item).queryByText(/sending events for this form/i)).toBeNull();
  });

  it('explains the single name field on request', async () => {
    serve(listing([row()]));
    await renderForms();

    const hint = await screen.findByTestId('forms-name-split-hint');
    expect(hint).toHaveTextContent('Choose it for both First name and Last name');
    expect(screen.queryByTestId('forms-name-split-details')).toBeNull();

    fireEvent.click(within(hint).getByRole('button', { name: 'How it works' }));
    expect(screen.getByTestId('forms-name-split-details')).toHaveTextContent(
      'split on the first space'
    );

    fireEvent.click(within(hint).getByRole('button', { name: 'How it works' }));
    expect(screen.queryByTestId('forms-name-split-details')).toBeNull();
  });

  it('keeps one row open at a time', async () => {
    serve(listing([row(), row({ key: 'cf7:13', form_id: '13', title: 'Quote' })]));
    await renderForms();

    fireEvent.click(await screen.findByRole('button', { name: 'Configure Contact us' }));
    expect(screen.getByTestId('form-panel-cf7:12')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Configure Quote' }));
    expect(screen.getByTestId('form-panel-cf7:13')).toBeInTheDocument();
    expect(screen.queryByTestId('form-panel-cf7:12')).not.toBeInTheDocument();
  });

  it('moves an identifier to Custom and keeps it there', async () => {
    const chosen = row({
      record: {
        title: 'Contact us',
        fields: { ph: { key: 'mobile', type: 'text', label: 'Mobile' } },
      },
    });
    serve(listing([row()]), listing([chosen]));
    await renderForms();

    fireEvent.click(await screen.findByRole('button', { name: 'Configure Contact us' }));
    expect(screen.getByTestId('state-ph')).toHaveTextContent('Found by field type');

    expect(screen.getByRole('button', { name: 'Phone' })).toHaveTextContent('Phone');
    await choose(screen.getByRole('button', { name: 'Phone' }), /^Mobile/);

    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Phone' })).toHaveTextContent('Mobile')
    );
    expect(screen.getByTestId('state-ph')).toBeEmptyDOMElement();
    expect(mockSaveForms).toHaveBeenCalledWith({
      'cf7:12': {
        title: 'Contact us',
        fields: { ph: { key: 'mobile', type: 'text', label: 'Mobile' } },
      },
    });
    expect(screen.getByRole('button', { name: 'Phone' })).toHaveTextContent('Mobile');
  });

  it('lists the same options for every identifier and returns to detection on the detected field', async () => {
    const chosen = row({
      record: { fields: { ph: { key: 'mobile', type: 'text', label: 'Mobile' } } },
    });
    serve(listing([chosen]), listing([row()]));
    await renderForms();

    fireEvent.click(await screen.findByRole('button', { name: 'Configure Contact us' }));
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'City' }));
    const items = (await screen.findAllByRole('menuitem')).map((item) => item.textContent);
    expect(items).toEqual([
      '—',
      'Email (your-email · email)',
      'Phone (your-phone · phone)',
      'Mobile (mobile · text)',
    ]);
    await user.keyboard('{Escape}');

    await choose(screen.getByRole('button', { name: 'Phone' }), /^Phone/);

    expect(mockSaveForms).toHaveBeenCalledWith({
      'cf7:12': { title: 'Contact us', fields: { ph: null } },
    });
    await waitFor(() =>
      expect(screen.getByTestId('state-ph')).toHaveTextContent('Found by field type')
    );
  });

  it('stores the dash as a refusal only where a field is detected', async () => {
    const chosen = row({
      record: { fields: { ct: { key: 'mobile', type: 'text', label: 'Mobile' } } },
    });
    serve(listing([chosen]), listing([row()]));
    await renderForms();
    fireEvent.click(await screen.findByRole('button', { name: 'Configure Contact us' }));

    await choose(screen.getByRole('button', { name: 'City' }), '—');
    expect(mockSaveForms).toHaveBeenLastCalledWith({
      'cf7:12': { title: 'Contact us', fields: { ct: null } },
    });

    await choose(screen.getByRole('button', { name: 'Email' }), '—');
    expect(mockSaveForms).toHaveBeenLastCalledWith({
      'cf7:12': { title: 'Contact us', fields: { em: { key: '', type: '', label: '' } } },
    });
  });

  it('highlights a Custom choice whose field changed until it is saved again', async () => {
    const stale = row({
      fields: [
        { key: 'your-email', type: 'email', label: 'Email' },
        { key: 'mobile', type: 'textarea', label: 'Comment' },
      ],
      record: { fields: { ph: { key: 'mobile', type: 'phone', label: 'Phone' } } },
    });
    const resaved = row({
      fields: stale.fields,
      record: { fields: { ph: { key: 'mobile', type: 'textarea', label: 'Comment' } } },
    });
    serve(listing([stale]), listing([resaved]));
    await renderForms();

    fireEvent.click(await screen.findByRole('button', { name: 'Configure Contact us' }));
    expect(screen.getByText('This field has changed since it was chosen.')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Keep this field' }));
    await waitFor(() =>
      expect(screen.queryByText('This field has changed since it was chosen.')).toBeNull()
    );
  });

  it('highlights a Custom choice whose field was removed', async () => {
    serve(
      listing([row({ record: { fields: { ph: { key: 'gone', type: 'phone', label: 'Phone' } } } })])
    );
    await renderForms();

    fireEvent.click(await screen.findByRole('button', { name: 'Configure Contact us' }));
    expect(screen.getByText('This field no longer exists on the form.')).toBeInTheDocument();
    expect(screen.getByTestId('form-summary-cf7:12')).not.toHaveTextContent('Phone');
  });

  it('flags a field that only contains a name word and sends it once it is chosen', async () => {
    const fields: FormRow['fields'] = [
      { key: 'your-email', type: 'email', label: 'Email' },
      { key: 'company', type: 'text', label: 'Company name' },
    ];
    const company = { key: 'company', type: 'text', label: 'Company name' };
    const offered = row({
      fields,
      detected: { em: 'your-email' },
      unconfirmed: { fn: 'company', ln: 'company' },
    });
    const chosen = row({
      fields,
      detected: { em: 'your-email' },
      unconfirmed: { fn: 'company', ln: 'company' },
      record: { title: 'Contact us', fields: { fn: company, ln: company } },
    });
    serve(listing([offered]), listing([chosen]));
    await renderForms();

    expect(await screen.findByTestId('form-unconfirmed-cf7:12')).toHaveTextContent(
      'Not sent until you choose the field: First name, Last name.'
    );
    expect(screen.getByTestId('form-summary-cf7:12')).toHaveTextContent('Sends: Email');

    fireEvent.click(screen.getByRole('button', { name: 'Configure Contact us' }));
    expect(screen.getByTestId('state-fn')).toHaveTextContent('Needs your choice');
    fireEvent.click(screen.getAllByRole('button', { name: 'Use this field' })[0]);

    expect(mockSaveForms).toHaveBeenCalledWith({
      'cf7:12': { title: 'Contact us', fields: { fn: company, ln: company } },
    });
    await waitFor(() => expect(screen.queryByTestId('form-unconfirmed-cf7:12')).toBeNull());
    expect(screen.getByTestId('state-fn')).toBeEmptyDOMElement();
    expect(screen.getByRole('button', { name: 'First name' })).toHaveTextContent('Company name');
    expect(screen.getByTestId('form-name-split-cf7:12')).toHaveTextContent(
      'split on the first space'
    );
    expect(screen.getByTestId('form-summary-cf7:12')).toHaveTextContent(
      'Sends: Email, First name, Last name'
    );
  });

  it('locks a missing form and states it is missing', async () => {
    serve(listing([row({ missing: true, fields: [], record: { enabled: 1, event: 'Schedule' } })]));
    await renderForms();

    expect(await screen.findByText(/no longer exists on the site/)).toBeInTheDocument();
    expect(screen.getByRole('switch', { name: 'Send events for Contact us' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Event for Contact us' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Event for Contact us' })).toHaveTextContent(
      'Schedule'
    );
  });

  it('shows an untouched medium-confidence form off, with its suggestion filled in', async () => {
    serve(listing([row({ confidence: 'medium', suggested_event: 'CompleteRegistration' })]));
    await renderForms();

    expect(
      await screen.findByRole('switch', { name: 'Send events for Contact us' })
    ).not.toBeChecked();
    expect(screen.getByRole('button', { name: 'Event for Contact us' })).toHaveTextContent(
      'CompleteRegistration'
    );
    expect(screen.getByText(/turn the form on to start sending/)).toBeInTheDocument();
  });
});
