<?php
/**
 * uninstall.php as WordPress runs it: included from inside uninstall_plugin(), so the file's
 * top level is function scope and a global it uses must be declared.
 *
 * Run: php tests/test-uninstall.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('WP_UNINSTALL_PLUGIN', 'pixelflow/pixelflow.php');

$GLOBALS['__pf_options'] = [];
$GLOBALS['__pf_deleted'] = [];

function get_option($name, $default = false)
{
    return $GLOBALS['__pf_options'][$name] ?? $default;
}
function delete_option($name)
{
    $GLOBALS['__pf_deleted'][] = $name;

    return true;
}
function is_multisite()
{
    return false;
}

/** The part of $wpdb the uninstall script uses. */
class PF_Test_Uninstall_Wpdb
{
    public string $options = 'wp_options';
    public array $queries  = [];

    public function esc_like($text)
    {
        return addcslashes((string) $text, '_%\\');
    }

    public function prepare($query, ...$args)
    {
        return vsprintf(str_replace('%s', "'%s'", $query), $args);
    }

    public function query($sql)
    {
        $this->queries[] = $sql;

        return 0;
    }
}

$failures = [];
$passes   = 0;

/**
 * Runs uninstall.php the way uninstall_plugin() does, inside a function.
 *
 * @return string|null The error it raised, or null
 */
function pf_run_uninstall(): ?string
{
    $GLOBALS['wpdb']         = new PF_Test_Uninstall_Wpdb();
    $GLOBALS['__pf_deleted'] = [];

    $run = static function (): void {
        include dirname(__DIR__) . '/uninstall.php';
    };
    try {
        $run();
    } catch (\Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    }

    return null;
}

/** @param string $label @param callable $fn @param array $failures @param int $passes */
function pf_uninstall_case(string $label, callable $fn, array &$failures, int &$passes): void
{
    $result = $fn();
    if ($result === true) {
        $passes++;
        echo "PASS  {$label}\n";
    } else {
        $failures[] = $label;
        echo "FAIL  {$label}: {$result}\n";
    }
}

pf_uninstall_case('With removal on, the options and the repeat-window rows are deleted without an error', function () {
    $GLOBALS['__pf_options']['pixelflow_general_options'] = ['remove_on_uninstall' => 1];
    $error = pf_run_uninstall();
    if ($error !== null) {
        return $error;
    }
    if ( ! in_array('pixelflow_form_settings', $GLOBALS['__pf_deleted'], true)) {
        return 'deleted ' . implode(',', $GLOBALS['__pf_deleted']);
    }
    $queries = $GLOBALS['wpdb']->queries;

    return count($queries) === 1 && strpos($queries[0], 'pf\\_form\\_dedupe\\_%') !== false
        ? true
        : 'queries ' . json_encode($queries);
}, $failures, $passes);

pf_uninstall_case('With removal off, nothing is deleted', function () {
    $GLOBALS['__pf_options']['pixelflow_general_options'] = ['remove_on_uninstall' => 0];
    $error = pf_run_uninstall();

    return $error === null && $GLOBALS['__pf_deleted'] === [] && $GLOBALS['wpdb']->queries === []
        ? true
        : ($error ?? 'something was deleted');
}, $failures, $passes);

echo "\n{$passes} passed, " . count($failures) . " failed\n";
exit($failures === [] ? 0 : 1);
