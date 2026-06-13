<?php
// বটের টোকেন এবং এডমিন আইডি সেট করুন
define('BOT_TOKEN', '8984011233:AAE26EifT8xVpwqduXYfQzR6EBUWR5FpXlo'); 
define('ADMIN_ID', '8357251736');
define('BOT_USERNAME', 'easy_to_use5bot'); // বটের ইউজারনেম দিন (যেমন: @my_bot এর জায়গায় শুধু my_bot)

// Telegram API Function
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

// SQLite Database কানেকশন
try {
    $pdo = new PDO('sqlite:/tmp/database.sqlite'); // Vercel এর /tmp ফোল্ডারে রিড-রাইট করা যায়
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // টেবিল তৈরি করা
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        chat_id TEXT PRIMARY KEY,
        balance INTEGER DEFAULT 0,
        unique_code TEXT UNIQUE
    )");
} catch (PDOException $e) {
    die("Database Error");
}

// ইউনিক কোড জেনারেট করার ফাংশন (ইউজার আইডি থাকবে না)
function generateUniqueCode($pdo) {
    while(true) {
        $code = substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"), 0, 8);
        $stmt = $pdo->prepare("SELECT chat_id FROM users WHERE unique_code = ?");
        $stmt->execute([$code]);
        if(!$stmt->fetch()) return $code;
    }
}

// Telegram থেকে আসা ডাটা রিসিভ করা
$update = json_decode(file_get_contents('php://input'), true);

if (isset($update['message'])) {
    $chat_id = $update['message']['chat']['id'];
    $text = $update['message']['text'];

    // ইউজারের জন্য সুন্দর বাটন
    $keyboard = [
        'keyboard' => [
            [['text' => '📢 রেফার'], ['text' => '💸 উত্তোলন']]
        ],
        'resize_keyboard' => true
    ];

    // ইউজার ডাটাবেইজে আছে কিনা চেক করা
    $stmt = $pdo->prepare("SELECT * FROM users WHERE chat_id = ?");
    $stmt->execute([$chat_id]);
    $user = $stmt->fetch();

    if (!$user) {
        // নতুন ইউজার হলে ইউনিক কোড তৈরি করে ডাটাবেইজে সেভ করা
        $unique_code = generateUniqueCode($pdo);
        $stmt = $pdo->prepare("INSERT INTO users (chat_id, balance, unique_code) VALUES (?, 0, ?)");
        $stmt->execute([$chat_id, $unique_code]);
        
        // কেউ রেফার করলে তার ব্যালেন্সে ১০ টাকা অ্যাড করা
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

    // /start কমান্ড দিলে মেসেজ
    if (strpos($text, '/start') === 0) {
        $msg = "✨ <b>আমাদের বটে আপনাকে স্বাগতম!</b> ✨\n\nআপনি রেফার করে টাকা আয় করতে পারবেন। নিচে থেকে আপনার পছন্দের অপশন বেছে নিন।";
        sendTelegramMessage($chat_id, $msg, $keyboard);
    } 
    
    // রেফার বাটনে ক্লিক করলে
    elseif ($text == '📢 রেফার') {
        $ref_link = "https://t.me/" . BOT_USERNAME . "?start=" . $user['unique_code'];
        $msg = "🔗 <b>আপনার ইনভাইট লিংক:</b>\n<code>$ref_link</code>\n\nএই লিংক দিয়ে কাউকে জয়েন করালে আপনি পাবেন <b>১০৳</b>।\nআপনার বর্তমান ব্যালেন্স: <b>{$user['balance']}৳</b>";
        sendTelegramMessage($chat_id, $msg, $keyboard);
    } 
    
    // উত্তোলন বাটনে ক্লিক করলে
    elseif ($text == '💸 উত্তোলন') {
        if ($user['balance'] > 0) {
            $amount = $user['balance'];
            
            // এডমিনকে রিকোয়েস্ট পাঠানো
            $admin_msg = "🚨 <b>নতুন উইথড্র রিকোয়েস্ট!</b>\n\n👤 ইউজার আইডি: <code>$chat_id</code>\n💰 টাকার পরিমাণ: <b>$amount ৳</b>";
            sendTelegramMessage(ADMIN_ID, $admin_msg);
            
            // ইউজারের ব্যালেন্স জিরো করে দেওয়া
            $pdo->prepare("UPDATE users SET balance = 0 WHERE chat_id = ?")->execute([$chat_id]);
            
            // ইউজারকে মেসেজ দেওয়া
            sendTelegramMessage($chat_id, "✅ <b>সবকিছু উত্তোলন হয়েছে অটোমেটিক!</b>\nখুব শীঘ্রই এডমিন আপনার পেমেন্ট করে দেবে।", $keyboard);
        } else {
            sendTelegramMessage($chat_id, "⚠️ আপনার ব্যালেন্সে পর্যাপ্ত টাকা নেই! বেশি করে রেফার করুন।", $keyboard);
        }
    }
}
?>
