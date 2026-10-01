<?php
/**
 * Submissions that must not produce an event: spam and failed submissions, automated traffic,
 * private addresses, excluded roles and repeats — and what each of them reports or logs.
 *
 * Run: php tests/test-form-skips.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-forms.php';

// ---------------------------------------------------------------------
// Contact Form 7 stand-ins, enough to drive the adapter's success hook
// ---------------------------------------------------------------------

class WPCF7_Submission
{
    public static function get_instance()
    {
        return new self();
    }

    public function get_posted_data()
    {
        return ['your-email' => 'cf7@example.test', 'your-message' => 'hello'];
    }
}

class PF_Test_CF7_Tag
{
    public $name;
    public $basetype;
    public $values = [];

    public function __construct(string $name, string $basetype)
    {
        $this->name     = $name;
        $this->basetype = $basetype;
    }

    public function has_option($option)
    {
        return false;
    }
}

class PF_Test_CF7_Form
{
    public function id()
    {
        return 12;
    }

    public function title()
    {
        return 'Contact form 1';
    }

    public function scan_form_tags()
    {
        return [new PF_Test_CF7_Tag('your-email', 'email'), new PF_Test_CF7_Tag('your-message', 'textarea')];
    }
}

$failures = [];
$passes   = 0;

foreach (['spam', 'validation_failed', 'aborted'] as $status) {
    pf_forms_case("CF7 status {$status} sends neither an event nor a beacon", function () use ($status) {
        (new PixelFlow_Form_Adapter_CF7())->on_submit(new PF_Test_CF7_Form(), ['status' => $status]);

        return $GLOBALS['__pf_http'] === [] ? true : 'a request was made';
    }, $failures, $passes);
}

pf_forms_case('CF7 mail_failed still sends', function () {
    (new PixelFlow_Form_Adapter_CF7())->on_submit(new PF_Test_CF7_Form(), ['status' => 'mail_failed']);
    $event = pf_only_event();

    return $event !== null && ($event['customerData']['em'] ?? null) === hash('sha256', 'cf7@example.test')
        ? true
        : 'events ' . count(pf_sent_events());
}, $failures, $passes);

pf_forms_case('A bot user agent reports blocked and sends no event', function () {
    $_SERVER['HTTP_USER_AGENT'] = 'python-requests/2.31';
    $outcome = PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $rows    = pf_blocked_rows();

    return $outcome === 'blocked' && pf_sent_events() === [] && ($rows[0]['reason'] ?? '') === 'bot'
        ? true
        : "outcome {$outcome}, rows " . json_encode($rows);
}, $failures, $passes);

pf_forms_case('A speculative prefetch reports blocked', function () {
    $_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $rows = pf_blocked_rows();

    return ($rows[0]['detail'] ?? '') === 'prefetch_header' && pf_sent_events() === [] ? true : json_encode($rows);
}, $failures, $passes);

pf_forms_case('A private IP sends nothing at all', function () {
    $_SERVER['REMOTE_ADDR'] = '192.168.1.20';
    $_SERVER['HTTP_USER_AGENT'] = 'curl/8.0';
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());

    return $GLOBALS['__pf_http'] === [] ? true : 'a request was made';
}, $failures, $passes);

pf_forms_case('A visitor without any plugin cookie is not treated as automated', function () {
    $_COOKIE = [];
    $outcome = PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());

    return $outcome === 'sent' ? true : "outcome {$outcome}";
}, $failures, $passes);

pf_forms_case('An excluded role sends neither an event nor a blocked report, even while the banner is unanswered', function () {
    $GLOBALS['__pf_options']['pixelflow_general_options']['excluded_user_roles'] = ['administrator'];
    $GLOBALS['__pf_user'] = (object) ['roles' => ['administrator']];
    pf_banner_unanswered();
    $outcome = PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());

    if ($outcome !== 'skipped' || $GLOBALS['__pf_http'] !== []) {
        return "outcome {$outcome}";
    }

    return pf_held_recipes() === [] && ! isset($_COOKIE[PIXELFLOW_HELD_FORM_EVENTS_COOKIE_NAME]) ? true : 'a recipe was stored';
}, $failures, $passes);

pf_forms_case('A role that is not excluded still sends', function () {
    $GLOBALS['__pf_options']['pixelflow_general_options']['excluded_user_roles'] = ['administrator'];
    $GLOBALS['__pf_user'] = (object) ['roles' => ['customer']];

    return PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission()) === 'sent' ? true : 'not sent';
}, $failures, $passes);

pf_forms_case('Two submissions of one form inside two minutes produce one event', function () {
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    PixelFlow_Form_Dispatcher::reset_request_state();
    $second = PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());

    return $second === 'skipped' && count(pf_sent_events()) === 1 ? true : "second {$second}";
}, $failures, $passes);

pf_forms_case('The repeat window reopens after two minutes', function () {
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    PixelFlow_Form_Dispatcher::reset_request_state();
    pf_age_repeat_windows(121);

    return PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission()) === 'sent' ? true : 'second was not sent';
}, $failures, $passes);

pf_forms_case('A request whose claim loses to a concurrent insert sends nothing', function () {
    $GLOBALS['__pf_db_concurrent_insert'] = true;
    $outcome = PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());

    return $outcome === 'skipped' && $GLOBALS['__pf_http'] === [] ? true : "outcome {$outcome}";
}, $failures, $passes);

pf_forms_case('A claim deletes windows that have closed, and only those', function () {
    $GLOBALS['__pf_db_options'] = [
        'pf_form_dedupe_closed' => (string) (time() - 121),
        'pf_form_dedupe_open'   => (string) (time() - 60),
        'pixelflow_other'       => '0',
    ];
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $names = array_keys($GLOBALS['__pf_db_options']);
    sort($names);

    return count($names) === 3 && ! in_array('pf_form_dedupe_closed', $names, true)
        && in_array('pf_form_dedupe_open', $names, true) && in_array('pixelflow_other', $names, true)
        ? true
        : 'rows ' . implode(',', $names);
}, $failures, $passes);

pf_forms_case('Two different forms submitted in a row by one visitor each send', function () {
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission(['form_id' => '8']));

    return count(pf_sent_events()) === 2 ? true : 'events ' . count(pf_sent_events());
}, $failures, $passes);

pf_forms_case('A bot-blocked submission leaves the window open for the next one from the same IP', function () {
    $_COOKIE = [];
    $_SERVER['HTTP_USER_AGENT'] = 'python-requests/2.31';
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    PixelFlow_Form_Dispatcher::reset_request_state();
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0';
    $outcome = PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());

    return $outcome === 'sent' ? true : "visitor outcome {$outcome}";
}, $failures, $passes);

pf_forms_case('A denial beacons denied and stores nothing', function () {
    pf_decide('denied');
    $outcome = PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $rows    = pf_blocked_rows();

    if ($outcome !== 'blocked' || ($rows[0]['reason'] ?? '') !== 'denied') {
        return "outcome {$outcome}, rows " . json_encode($rows);
    }

    return pf_held_recipes() === [] && ! isset($_COOKIE[PIXELFLOW_HELD_FORM_EVENTS_COOKIE_NAME]) ? true : 'a recipe was stored';
}, $failures, $passes);

pf_forms_case('A non-catalogue event name leaves the stored event unchanged', function () {
    $records = pixelflow_apply_form_settings_patch([], ['custom:7' => ['event' => 'Contact']]);
    $records = pixelflow_apply_form_settings_patch($records, ['custom:7' => ['event' => 'completeregistration']]);
    $records = pixelflow_apply_form_settings_patch($records, ['custom:7' => ['event' => 'MyCustomEvent']]);

    return $records['custom:7']['event'] === 'Contact' ? true : 'event became ' . $records['custom:7']['event'];
}, $failures, $passes);

/** Reads the debug log written by this case. */
function pf_debug_log(): string
{
    $path = pixelflow_get_debug_log_path();

    return $path !== '' && file_exists($path) ? (string) file_get_contents($path) : '';
}

pf_forms_case('The log masks the client IP and holds no raw value', function () {
    $GLOBALS['__pf_options']['pixelflow_debug_log_key']                          = 'formslog' . getmypid();
    $GLOBALS['__pf_options']['pixelflow_general_options']['forms_debug_enabled'] = 1;
    @unlink(pixelflow_get_debug_log_path());
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $log = pf_debug_log();

    if ($log === '') {
        return 'nothing was logged';
    }
    if (strpos($log, PF_TEST_IP) !== false || strpos($log, '93.184.21****') === false) {
        return 'the client IP is not masked';
    }
    if (stripos($log, 'ada@example.test') !== false || strpos($log, 'SECRET-MESSAGE') !== false || strpos($log, '5678') !== false) {
        return 'a raw value reached the log';
    }

    return strpos($log, '"identifiers"') !== false && strpos($log, '"em"') !== false ? true : 'identifiers not named';
}, $failures, $passes);

pf_forms_case('A blocked beacon is logged as its own entry with the IP masked', function () {
    $GLOBALS['__pf_options']['pixelflow_debug_log_key']                          = 'formslog' . getmypid();
    $GLOBALS['__pf_options']['pixelflow_general_options']['forms_debug_enabled'] = 1;
    @unlink(pixelflow_get_debug_log_path());
    pf_decide('denied');
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $log = pf_debug_log();

    if (strpos($log, '"hook": "FORM BLOCKED_EVENTS Lead"') === false) {
        return 'no beacon entry in the log';
    }
    if (strpos($log, '"reason": "denied"') === false) {
        return 'the beacon entry does not carry the denied row';
    }

    return strpos($log, PF_TEST_IP) === false ? true : 'the client IP is not masked in the beacon entry';
}, $failures, $passes);

pf_forms_case('Nothing is logged for a form while the form switch is off, even with the Woo switch on', function () {
    $GLOBALS['__pf_options']['pixelflow_debug_log_key']                        = 'formslog' . getmypid();
    $GLOBALS['__pf_options']['pixelflow_general_options']['woo_debug_enabled'] = 1;
    @unlink(pixelflow_get_debug_log_path());
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());

    return pf_debug_log() === '' ? true : 'a form entry was logged';
}, $failures, $passes);

@unlink(WP_CONTENT_DIR . '/pixelflow_debug_formslog' . getmypid() . '.log');
@rmdir(WP_CONTENT_DIR);

pf_forms_finish($failures, $passes);
