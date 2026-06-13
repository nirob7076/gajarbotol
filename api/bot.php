<?php
// ১. টেলিগ্রাম বটের তথ্য
define('BOT_TOKEN', '8984011233:AAE26EifT8xVpwqduXYfQzR6EBUWR5FpXlo'); 
define('ADMIN_ID', '8357251736');
define('BOT_USERNAME', 'easy_to_use5bot'); 

// ২. সুপাবেস ডাটাবেইজের নতুন তথ্য (Pooler Connection)
define('DB_HOST', 'aws-1-ap-northeast-1.pooler.supabase.com');
define('DB_PORT', '6543');
define('DB_NAME', 'postgres');
define('DB_USER', 'postgres.eewmopahvxahogfegpcg');
define('DB_PASS', 'gajarbotol.'); // 👈 এখানে আপনার সুপাবেস পাসওয়ার্ড দিন

function sendTelegramMessage($chat_id, $text, $reply_markup = null) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($reply_markup) {
        $data['reply_markup'] = json_encode($reply_markup);
    }
    file_get_contents($url . '?' . http_build_query($data));
}

// PostgreSQL (Supabase Pooler) ডাটাবেইজ কানেকশন
try {
    $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database Connection Error");
}

function generateUniqueCode($pdo) {
    while(true) {
        $code = substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"), 0, 8);
        $stmt = $pdo->prepare("SELECT chat_id FROM users WHERE unique_code = ?");
        $stmt->execute([$code]);
        if(!$stmt->fetch()) return $code;
    }
}

$update = json_decode(file_get_contents('php://input'), true);

if (isset($update['message'])) {
    $chat_id = $update['message']['chat']['id'];
    $text = $update['message']['text'];

    $keyboard = [
        'keyboard' => [
            [['text' => '📢 রেফার'], ['text' => '💸 উত্তোলন']]
        ],
        'resize_keyboard' => true
    ];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE chat_id = ?");
    $stmt->execute([(string)$chat_id]);
    $user = $stmt->fetch();

    if (!$user) {
        $unique_code = generateUniqueCode($pdo);
        $stmt = $pdo->prepare("INSERT INTO users (chat_id, balance, unique_code) VALUES (?, 0, ?)");
        $stmt->execute([(string)$chat_id, $unique_code]);
        
        if (strpos($text, '/start ') === 0) {
            $ref_code = str_replace('/start ', '', $text);
            $stmt = $pdo->prepare("SELECT chat_id FROM users WHERE unique_code = ?");
            $stmt->execute([$ref_code]);
            $referrer = $stmt->fetch();
            
            if ($referrer && $referrer['chat_id'] != $chat_id) {
                $pdo->prepare("UPDATE users SET balance = balance + 10 WHERE chat_id = ?")->execute([$referrer['chat_id']]);
                sendTelegramMessage($referrer['chat_id'], "🎉 <b>অভিনন্দন!</b> আপনার রেফারে একজন জয়েন করেছে। আপনি <b>১০৳</b> পেয়েছেন!");
            }
        }
        $user = ['chat_id' => $chat_id, 'balance' => 0, 'unique_code' => $unique_code];
    }

    if (strpos($text, '/start') === 0) {
        $msg = "✨ <b>আমাদের বটে আপনাকে স্বাগতম!</b> ✨\n\nআপনি রেফার করে টাকা আয় করতে পারবেন। নিচে থেকে আপনার পছন্দের অপশন বেছে নিন।";
        sendTelegramMessage($chat_id, $msg, $keyboard);
    } 
    elseif ($text == '📢 রেফার') {
        $ref_link = "https://t.me/" . BOT_USERNAME . "?start=" . $user['unique_code'];
        $msg = "🔗 <b>আপনার ইনভাইট লিংক:</b>\n<code>$ref_link</code>\n\nএই লিংক দিয়ে কাউকে জয়েন করালে আপনি পাবেন <b>১০৳</b>।\nআপনার বর্তমান ব্যালেন্স: <b>{$user['balance']}৳</b>";
        sendTelegramMessage($chat_id, $msg, $keyboard);
    } 
    elseif ($text == '💸 উত্তোলন') {
        if ($user['balance'] > 0) {
            $amount = $user['balance'];
            
            $admin_msg = "🚨 <b>নতুন উইথড্র রিকোয়েস্ট!</b>\n\n👤 ইউজার আইডি: <code>$chat_id</code>\n💰 টাকার পরিমাণ: <b>$amount ৳</b>";
            sendTelegramMessage(ADMIN_ID, $admin_msg);
            
            $pdo->prepare("UPDATE users SET balance = 0 WHERE chat_id = ?")->execute([(string)$chat_id]);
            
            sendTelegramMessage($chat_id, "✅ <b>সবকিছু উত্তোলন হয়েছে অটোমেটিক!</b>\nখুব শীঘ্রই এডমিন আপনার পেমেন্ট করে দেবে।", $keyboard);
        } else {
            sendTelegramMessage($chat_id, "⚠️ আপনার ব্যালেন্সে পর্যাপ্ত টাকা নেই! বেশি করে রেফার করুন।", $keyboard);
        }
    }
}
?>
