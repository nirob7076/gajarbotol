<?php
// ১. টেলিগ্রাম বটের তথ্য
define('BOT_TOKEN', '8984011233:AAE26EifT8xVpwqduXYfQzR6EBUWR5FpXlo'); 
define('ADMIN_ID', '8357251736');
define('BOT_USERNAME', 'easy_to_use5bot'); 
define('MUST_JOIN_CHANNEL', '@Owner_zenitsu'); // 👈 আপনার চ্যানেলের ইউজারনেম দিন (অবশ্যই বটকে চ্যানেলের এডমিন বানাবেন)

// ২. সুপাবেস ডাটাবেইজ
define('DB_HOST', 'aws-1-ap-northeast-1.pooler.supabase.com');
define('DB_PORT', '6543');
define('DB_NAME', 'postgres');
define('DB_USER', 'postgres.eewmopahvxahogfegpcg');
define('DB_PASS', 'gajarbotol.'); // 👈 আপনার পাসওয়ার্ড

function apiRequest($method, $data = []) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function sendMsg($chat_id, $text, $reply_markup = null) {
    $data = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
    apiRequest('sendMessage', $data);
}

function checkJoined($chat_id, $channel) {
    $res = apiRequest('getChatMember', ['chat_id' => $channel, 'user_id' => $chat_id]);
    $status = $res['result']['status'] ?? 'left';
    return in_array($status, ['member', 'administrator', 'creator']);
}

// Database Connection
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

$main_menu = [
    'keyboard' => [
        [['text' => '📢 রেফার'], ['text' => '💸 উত্তোলন']],
        [['text' => '📋 টাস্ক'], ['text' => '👤 ব্যালেন্স']]
    ],
    'resize_keyboard' => true
];
$cancel_menu = ['keyboard' => [[['text' => '❌ ক্যানসেল']]], 'resize_keyboard' => true];

$chat_id = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
$text = $update['message']['text'] ?? null;
$callback_data = $update['callback_query']['data'] ?? null;
$callback_id = $update['callback_query']['id'] ?? null;

if (!$chat_id) exit;

// Get or Create User
$stmt = $pdo->prepare("SELECT * FROM users WHERE chat_id = ?");
$stmt->execute([(string)$chat_id]);
$user = $stmt->fetch();

if (!$user) {
    $unique_code = generateUniqueCode($pdo);
    $ref_by = null;
    if ($text && strpos($text, '/start ') === 0) {
        $ref_by = str_replace('/start ', '', $text);
    }
    $stmt = $pdo->prepare("INSERT INTO users (chat_id, balance, unique_code, referred_by) VALUES (?, 0, ?, ?)");
    $stmt->execute([(string)$chat_id, $unique_code, $ref_by]);
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE chat_id = ?");
    $stmt->execute([(string)$chat_id]);
    $user = $stmt->fetch();
}

$temp_data = json_decode($user['temp_data'], true) ?? [];

// ========== CALLBACK QUERY HANDLING ==========
if ($callback_data) {
    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);

    if ($callback_data == 'check_join') {
        if (checkJoined($chat_id, MUST_JOIN_CHANNEL)) {
            // Process Referral if pending
            if ($user['referred_by']) {
                $stmt = $pdo->prepare("SELECT chat_id FROM users WHERE unique_code = ?");
                $stmt->execute([$user['referred_by']]);
                $referrer = $stmt->fetch();
                if ($referrer && $referrer['chat_id'] != $chat_id) {
                    $pdo->prepare("UPDATE users SET balance = balance + 10 WHERE chat_id = ?")->execute([$referrer['chat_id']]);
                    sendMsg($referrer['chat_id'], "🎉 <b>অভিনন্দন!</b> আপনার রেফারে একজন জয়েন করেছে। আপনি <b>১০৳</b> পেয়েছেন!");
                }
                $pdo->prepare("UPDATE users SET referred_by = NULL WHERE chat_id = ?")->execute([(string)$chat_id]);
            }
            sendMsg($chat_id, "✅ <b>ভেরিফিকেশন সফল!</b> মেনু থেকে অপশন বেছে নিন।", $main_menu);
        } else {
            sendMsg($chat_id, "❌ আপনি এখনো চ্যানেলে জয়েন করেননি। জয়েন করে তারপর Check এ ক্লিক করুন।");
        }
    }
    
    // Withdraw Method Selection
    elseif (strpos($callback_data, 'wd_') === 0) {
        $method = str_replace('wd_', '', $callback_data);
        $temp_data['method'] = ucfirst($method);
        $pdo->prepare("UPDATE users SET state = 'wd_number', temp_data = ? WHERE chat_id = ?")->execute([json_encode($temp_data), (string)$chat_id]);
        sendMsg($chat_id, "👉 আপনার $temp_data[method] নাম্বার দিন:", $cancel_menu);
    }
    
    // Task Verification
    elseif (strpos($callback_data, 'check_task_') === 0) {
        $task_id = str_replace('check_task_', '', $callback_data);
        $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
        $stmt->execute([$task_id]);
        $task = $stmt->fetch();
        
        if ($task) {
            if (checkJoined($chat_id, $task['channel_username'])) {
                // Check if already rewarded
                $check = $pdo->prepare("SELECT * FROM user_tasks WHERE chat_id = ? AND task_id = ?");
                $check->execute([(string)$chat_id, $task_id]);
                if (!$check->fetch()) {
                    $pdo->prepare("INSERT INTO user_tasks (chat_id, task_id) VALUES (?, ?)")->execute([(string)$chat_id, $task_id]);
                    $pdo->prepare("UPDATE users SET balance = balance + ? WHERE chat_id = ?")->execute([$task['reward'], (string)$chat_id]);
                    sendMsg($chat_id, "✅ <b>টাস্ক কমপ্লিট!</b> আপনি {$task['reward']}৳ পেয়েছেন।", $main_menu);
                } else {
                    sendMsg($chat_id, "⚠️ আপনি এই টাস্কটি আগেই কমপ্লিট করেছেন।");
                }
            } else {
                sendMsg($chat_id, "❌ আপনি এখনো ওই চ্যানেলে জয়েন করেননি!");
            }
        }
    }
    exit;
}

// ========== TEXT MESSAGE HANDLING ==========
if ($text) {
    // 1. Force Join Check (except if user is cancelling)
    if ($text != '❌ ক্যানসেল' && !checkJoined($chat_id, MUST_JOIN_CHANNEL)) {
        $join_kb = [
            'inline_keyboard' => [
                [['text' => '🔗 চ্যানেল জয়েন করুন', 'url' => 'https://t.me/' . str_replace('@', '', MUST_JOIN_CHANNEL)]],
                [['text' => '✅ চেক করুন', 'callback_data' => 'check_join']]
            ]
        ];
        sendMsg($chat_id, "⚠️ <b>আমাদের বট ব্যবহার করতে হলে নিচের চ্যানেলে জয়েন করতে হবে।</b>\nজয়েন করার পর 'চেক করুন' বাটনে ক্লিক করুন।", $join_kb);
        exit;
    }

    // 2. Cancel Button Processing
    if ($text == '❌ ক্যানসেল') {
        $pdo->prepare("UPDATE users SET state = 'none', temp_data = '{}' WHERE chat_id = ?")->execute([(string)$chat_id]);
        sendMsg($chat_id, "❌ আপনার বর্তমান কাজ ক্যানসেল করা হয়েছে।", $main_menu);
        exit;
    }

    // 3. State Machine (Waiting for input)
    $state = $user['state'];

    if ($state == 'wd_number') {
        $temp_data['phone'] = $text;
        $pdo->prepare("UPDATE users SET state = 'wd_amount', temp_data = ? WHERE chat_id = ?")->execute([json_encode($temp_data), (string)$chat_id]);
        sendMsg($chat_id, "👉 উত্তোলনের পরিমাণ লিখুন (আপনার ব্যালেন্স: {$user['balance']}৳):", $cancel_menu);
        exit;
    }
    
    elseif ($state == 'wd_amount') {
        $amount = (float)$text;
        if ($amount > 0 && $amount <= $user['balance']) {
            $pdo->prepare("UPDATE users SET balance = balance - ?, state = 'none', temp_data = '{}' WHERE chat_id = ?")->execute([$amount, (string)$chat_id]);
            $pdo->prepare("INSERT INTO withdrawals (chat_id, method, phone, amount) VALUES (?, ?, ?, ?)")->execute([(string)$chat_id, $temp_data['method'], $temp_data['phone'], $amount]);
            
            $admin_msg = "🚨 <b>নতুন উত্তোলন রিকোয়েস্ট!</b>\n\n👤 ইউজার: <code>$chat_id</code>\n🏦 মেথড: {$temp_data['method']}\n📱 নাম্বার: <code>{$temp_data['phone']}</code>\n💰 পরিমাণ: <b>$amount ৳</b>";
            sendMsg(ADMIN_ID, $admin_msg);
            
            sendMsg($chat_id, "✅ <b>আপনার উত্তোলনের রিকোয়েস্ট সফলভাবে জমা হয়েছে!</b>\nখুব শীঘ্রই এডমিন আপনার পেমেন্ট করে দেবে।", $main_menu);
        } else {
            sendMsg($chat_id, "⚠️ পরিমাণ ভুল বা ব্যালেন্স কম আছে! আবার সঠিক পরিমাণ লিখুন:", $cancel_menu);
        }
        exit;
    }
    
    // Admin States for Task
    elseif ($state == 'admin_task_channel' && $chat_id == ADMIN_ID) {
        $temp_data['channel'] = $text;
        $pdo->prepare("UPDATE users SET state = 'admin_task_amount', temp_data = ? WHERE chat_id = ?")->execute([json_encode($temp_data), (string)$chat_id]);
        sendMsg($chat_id, "👉 এই টাস্ক কমপ্লিট করলে কত টাকা পাবে তা লিখুন (যেমন: 2.5):", $cancel_menu);
        exit;
    }
    
    elseif ($state == 'admin_task_amount' && $chat_id == ADMIN_ID) {
        $amount = (float)$text;
        $pdo->prepare("INSERT INTO tasks (channel_username, reward) VALUES (?, ?)")->execute([$temp_data['channel'], $amount]);
        $pdo->prepare("UPDATE users SET state = 'none', temp_data = '{}' WHERE chat_id = ?")->execute([(string)$chat_id]);
        sendMsg($chat_id, "✅ <b>সফলভাবে টাস্ক এড করা হয়েছে!</b>", $main_menu);
        exit;
    }

    // 4. Main Menu Commands
    if (strpos($text, '/start') === 0) {
        $msg = "✨ <b>আমাদের বটে আপনাকে স্বাগতম!</b> ✨\n\nআপনি রেফার ও টাস্ক কমপ্লিট করে টাকা আয় করতে পারবেন। নিচে থেকে আপনার পছন্দের অপশন বেছে নিন।";
        sendMsg($chat_id, $msg, $main_menu);
    } 
    
    elseif ($text == '📢 রেফার') {
        $ref_link = "https://t.me/" . BOT_USERNAME . "?start=" . $user['unique_code'];
        $msg = "🔗 <b>আপনার ইনভাইট লিংক:</b>\n<code>$ref_link</code>\n\nএই লিংক দিয়ে কাউকে জয়েন করালে আপনি পাবেন <b>১০৳</b>।\nআপনার বর্তমান ব্যালেন্স: <b>{$user['balance']}৳</b>";
        sendMsg($chat_id, $msg, $main_menu);
    } 
    
    elseif ($text == '👤 ব্যালেন্স') {
        sendMsg($chat_id, "💰 আপনার বর্তমান ব্যালেন্স: <b>{$user['balance']}৳</b>", $main_menu);
    }
    
    elseif ($text == '💸 উত্তোলন') {
        if ($user['balance'] > 0) {
            $methods = [
                'inline_keyboard' => [
                    [['text' => ' বিকাশ', 'callback_data' => 'wd_bkash'], ['text' => ' নগদ', 'callback_data' => 'wd_nagad']]
                ]
            ];
            sendMsg($chat_id, "🏦 <b>পেমেন্ট মেথড সিলেক্ট করুন:</b>", $methods);
        } else {
            sendMsg($chat_id, "⚠️ আপনার ব্যালেন্সে পর্যাপ্ত টাকা নেই!", $main_menu);
        }
    }
    
    elseif ($text == '📋 টাস্ক') {
        // Find 1 task the user hasn't done
        $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id NOT IN (SELECT task_id FROM user_tasks WHERE chat_id = ?) ORDER BY id DESC LIMIT 1");
        $stmt->execute([(string)$chat_id]);
        $task = $stmt->fetch();
        
        if ($task) {
            $task_kb = [
                'inline_keyboard' => [
                    [['text' => '🔗 চ্যানেলে জয়েন করুন', 'url' => 'https://t.me/' . str_replace('@', '', $task['channel_username'])]],
                    [['text' => '✅ চেক করুন', 'callback_data' => 'check_task_' . $task['id']]]
                ]
            ];
            sendMsg($chat_id, "📢 <b>নতুন টাস্ক!</b>\n\nনিচের চ্যানেলে জয়েন করলে পাবেন <b>{$task['reward']}৳</b>। জয়েন করার পর চেক বাটনে ক্লিক করুন।", $task_kb);
        } else {
            sendMsg($chat_id, "✅ বর্তমানে আর কোনো নতুন টাস্ক নেই। পরে আবার চেষ্টা করুন।", $main_menu);
        }
    }
    
    // Admin Panel
    elseif ($text == '/admin' && $chat_id == ADMIN_ID) {
        $users_count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $pending_wd = $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetchColumn();
        
        $msg = "👨‍💻 <b>এডমিন প্যানেল</b>\n\n👥 মোট ইউজার: $users_count\n⏳ পেন্ডিং উত্তোলন: $pending_wd";
        sendMsg($chat_id, $msg);
        
        $pdo->prepare("UPDATE users SET state = 'admin_task_channel' WHERE chat_id = ?")->execute([(string)$chat_id]);
        sendMsg($chat_id, "➕ <b>নতুন টাস্ক এড করতে:</b>\nটাস্ক চ্যানেলের ইউজারনেম দিন (যেমন: @MyChannel) অথবা ক্যান্সেল করতে নিচে ক্লিক করুন।", $cancel_menu);
    }
}
?>
