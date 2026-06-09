<?php
/**
 * Paystack webhook endpoint.
 * Register this URL in your Paystack dashboard:
 *   https://yourdomain.com/AkuapemHub/paystack_webhook.php
 *
 * Only handles charge.success — add other events here as needed.
 */

// No session needed for webhook; don't start one
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/paystack.php';

handleWebhook(); // exits with 200/401/400
