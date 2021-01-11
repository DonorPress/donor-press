<?php
//https://developer.paypal.com/docs/checkout/reference/server-integration/setup-sdk/#set-up-the-environment
namespace Sample;

use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\SandboxEnvironment;

ini_set('error_reporting', E_ALL); // or error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

class PayPalClient
{
    /**
     * Returns PayPal HTTP client instance with environment that has access
     * credentials context. Use this instance to invoke PayPal APIs, provided the
     * credentials have access.
     */
    public static function client()
    {
        return new PayPalHttpClient(self::environment());
    }

    /**
     * Set up and return PayPal PHP SDK environment with PayPal access credentials.
     * This sample uses SandboxEnvironment. In production, use LiveEnvironment.
     */
    public static function environment()
    {
        $clientId = getenv("CLIENT_ID") ?: "AbsrQyC_K6PMfwH6-x1PlzTW-h1FPtBtZkpqV-nNaXZGPEcQNc1bRGP_DI3foFJwj14SxP7YQzneJA7n";
        $clientSecret = getenv("CLIENT_SECRET") ?: "EP4aa2Kn6pqxVc4sP5Hi3duYewR1_udsWJFqMOsTpJSYLmMAYjJ4SmZCS0Kwl9-_1DOw4wow-_CkYFVA";
        return new SandboxEnvironment($clientId, $clientSecret);
    }
}