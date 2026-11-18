<?php

declare(strict_types=1);

/**
 * Razorpay Checker Configuration
 */

return [
    // Telegram Bot Token (from @BotFather)
    'telegram_bot_token' => '9165080731:AAEcrWDvSRHYNLpv7AfLxaxdzbyWsfHL62o',

    // Telegram Chat ID / Group ID
    'telegram_chat_id'   => '-1004354820990',

    // Send Telegram notification when payment is successfully charged (₹1.00)
    'notify_on_charged'  => true,

    // File saving disabled as per request (Hits are dropped directly to Telegram)
    'save_to_file'       => false,
    'charged_file'       => '',
];
