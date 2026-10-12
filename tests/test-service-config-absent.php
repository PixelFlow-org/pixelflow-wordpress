<?php
/**
 * A plugin with no service configuration, such as a clone that was never built: forms send
 * no event and no blocked report, and say why in the debug log. Runs with no WC().
 *
 * PIXELFLOW_CONFIG_FILE names a file that does not exist, so the case does not depend on
 * whether a build has written includes/config.php into the working tree.
 *
 * Run: php tests/test-service-config-absent.php
 */

declare(strict_types=1);

define('PIXELFLOW_CONFIG_FILE', __DIR__ . '/fixtures/no-such-config.php');

require_once __DIR__ . '/bootstrap-forms.php';

$failures = [];
$passes   = 0;

pf_forms_case('pixelflow_config() is empty', function () {
    return pixelflow_config() === [] ? true : json_encode(pixelflow_config());
}, $failures, $passes);

pf_forms_case('A form submission sends nothing and the log says the API address is missing', function () {
    $GLOBALS['__pf_options']['pixelflow_debug_log_key']                          = 'formsnoconfig' . getmypid();
    $GLOBALS['__pf_options']['pixelflow_general_options']['forms_debug_enabled'] = 1;
    $path = pixelflow_get_debug_log_path();
    @unlink($path);

    $outcome = PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());
    $log     = file_exists($path) ? (string) file_get_contents($path) : '';
    @unlink($path);

    if ($GLOBALS['__pf_http'] !== []) {
        return 'a request was made: ' . json_encode(array_column($GLOBALS['__pf_http'], 'url'));
    }
    if (strpos($log, 'THE PLUGIN HAS NO API ADDRESS CONFIGURED') === false) {
        return "outcome {$outcome}, log: {$log}";
    }

    return true;
}, $failures, $passes);

pf_forms_case('A bot submission sends no blocked report', function () {
    $_SERVER['HTTP_USER_AGENT'] = 'python-requests/2.31';
    PixelFlow_Form_Dispatcher::dispatch(pf_contact_submission());

    return $GLOBALS['__pf_http'] === [] ? true : 'a request was made';
}, $failures, $passes);

pf_forms_finish($failures, $passes);
