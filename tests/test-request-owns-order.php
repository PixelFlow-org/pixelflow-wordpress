<?php
/**
 * Request ownership: whose consent decision may change an order's state.
 *
 * Any one identity signal is enough, and a request matching none of them leaves
 * the decision persisted with the order untouched.
 *
 * Run: php tests/test-request-owns-order.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

if ( ! defined('PIXELFLOW_PLUGIN_BASENAME')) {
    define('PIXELFLOW_PLUGIN_BASENAME', 'pixelflow/pixelflow.php');
}

require_once dirname(__DIR__) . '/includes/consent.php';

if ( ! function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return (int) ($GLOBALS['__pf_test_current_user_id'] ?? 0);
    }
}

/** Stands in for the WooCommerce session handler the predicate asks for. */
class PF_Test_Session
{
    /** @var array<string, mixed> */
    private $data;

    /** @var string */
    private $customer_id;

    public function __construct(array $data = [], string $customer_id = '')
    {
        $this->data        = $data;
        $this->customer_id = $customer_id;
    }

    public function get($key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function set($key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function get_customer_id(): string
    {
        return $this->customer_id;
    }
}

/** Stands in for WC_Order: the predicate only reads meta and two ids. */
class PF_Test_Order
{
    /** @var array<string, string> */
    private $meta;

    /** @var int */
    private $id;

    /** @var int */
    private $customer_id;

    public function __construct(int $id, array $meta = [], int $customer_id = 0)
    {
        $this->id          = $id;
        $this->meta        = $meta;
        $this->customer_id = $customer_id;
    }

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_customer_id(): int
    {
        return $this->customer_id;
    }

    public function get_meta($key, $single = true)
    {
        return $this->meta[$key] ?? '';
    }
}

/**
 * The predicate reaches for the session through this helper, which normally
 * lives in held-events.php and needs a live WooCommerce.
 *
 * @return object|null
 */
function pixelflow_woo_session(): ?object
{
    return $GLOBALS['__pf_test_session'] ?? null;
}

$failures = [];
$passes   = 0;

/**
 * @param string   $label
 * @param callable $fn
 */
function pf_run_ownership_case(string $label, callable $fn, array &$failures, int &$passes): void
{
    $_COOKIE                              = [];
    $GLOBALS['__pf_test_current_user_id'] = 0;
    $GLOBALS['__pf_test_session']         = null;

    try {
        $result = $fn();
    } catch (\Throwable $e) {
        $result = sprintf('%s: %s', get_class($e), $e->getMessage());
    }

    if ($result === true) {
        $passes++;
        echo "PASS  {$label}\n";
    } else {
        $failures[] = "{$label}: {$result}";
        echo "FAIL  {$label}\n      {$result}\n";
    }
}

pf_run_ownership_case(
    'Visitor identifier alone identifies the buyer',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_uid'] = 'uid-abc';
        $order = new PF_Test_Order(41, ['_pf_cookie__pf_uid' => 'uid-abc']);
        if ( ! pixelflow_request_owns_order($order)) {
            return 'a matching _pf_uid must be sufficient on its own';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_ownership_case(
    'Order predating the stored session id is still recognised',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_uid']           = 'uid-legacy';
        $GLOBALS['__pf_test_session'] = new PF_Test_Session([], 'session-new');
        $order = new PF_Test_Order(42, ['_pf_cookie__pf_uid' => 'uid-legacy']);
        if ( ! pixelflow_request_owns_order($order)) {
            return 'a guest order with no stored session id must still match on _pf_uid';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_ownership_case(
    'Logged-in customer identifies the buyer',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_test_current_user_id'] = 7;
        $order = new PF_Test_Order(43, [], 7);
        if ( ! pixelflow_request_owns_order($order)) {
            return 'a matching customer id must be sufficient';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_ownership_case(
    'Order awaiting payment in this session identifies the buyer',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_test_session'] = new PF_Test_Session(['order_awaiting_payment' => 44], 'session-x');
        $order = new PF_Test_Order(44);
        if ( ! pixelflow_request_owns_order($order)) {
            return 'the order awaiting payment in this session belongs to this request';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_ownership_case(
    'Matching session customer id identifies the buyer',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_test_session'] = new PF_Test_Session([], 'session-x');
        $order = new PF_Test_Order(45, ['_pf_session_customer_id' => 'session-x']);
        if ( ! pixelflow_request_owns_order($order)) {
            return 'a matching session customer id must be sufficient';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_ownership_case(
    'Store staff with their own cookies is not the buyer',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_uid']                   = 'uid-staff';
        $GLOBALS['__pf_test_current_user_id'] = 1;
        $GLOBALS['__pf_test_session']         = new PF_Test_Session([], 'session-staff');
        $order = new PF_Test_Order(46, [
            '_pf_cookie__pf_uid'      => 'uid-buyer',
            '_pf_session_customer_id' => 'session-buyer',
        ], 9);
        if (pixelflow_request_owns_order($order)) {
            return 'no signal matches, so this request must not own the order';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_ownership_case(
    'Stranger opening a shared order-received URL is not the buyer',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_test_session'] = new PF_Test_Session([], 'session-stranger');
        $order = new PF_Test_Order(47, ['_pf_cookie__pf_uid' => 'uid-buyer']);
        if (pixelflow_request_owns_order($order)) {
            return 'a shareable URL must not confer ownership';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_ownership_case(
    'A guest order with no signals at all is not owned',
    /** @return bool|string */
    function () {
        $order = new PF_Test_Order(48);
        if (pixelflow_request_owns_order($order)) {
            return 'an order carrying no identity data cannot be claimed';
        }

        return true;
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
