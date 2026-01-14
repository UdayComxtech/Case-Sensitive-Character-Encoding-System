<?php
$host = 'localhost'; $db = 'map'; $user = 'root'; $pass = '';

// FIXED SESSION START - No more "session already active" error
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$message = ''; $action = $_POST['action'] ?? ''; $send_status = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch(PDOException $e) {
    die("DB Error: " . $e->getMessage());
}
// REAL EMAILS - PHP mail() (Lines 170-200) - NO use statements!
if($action == 'send_email') {
    $email = trim($_POST['email']);
    $dataId = trim($_POST['data_id']);
    
    $stmt = $pdo->prepare("SELECT original_text, encoded_sequence FROM encoded_storage WHERE data_id = ?");
    $stmt->execute([$dataId]);
    $result = $stmt->fetch();
    
    if($result && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $to = $email;
        $subject = "TIUK #MAP - Your Encoded Data";
        $message = "Your secure TIUK #MAP data:\n\n";
        $message .= "ID: $dataId\n";
        $message .= "Original: {$result['original_text']}\n";
        $message .= "Encoded: {$result['encoded_sequence']}\n\n";
        $message .= "Decode anytime: http://localhost/map.php";
        
        // REAL EMAIL HEADERS
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/plain; charset=UTF-8\r\n";
        $headers .= "From: TIUK MAP <comxtechplugin@gmail.com>\r\n";
        $headers .= "Reply-To: comxtechplugin@gmail.com\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        
        if(mail($to, $subject, $message, $headers)) {
            $send_status = "SUCCESS! REAL EMAIL SENT to <strong>$email</strong><br>Check INBOX/SPAM folder NOW!";
            
            // Backup log
            file_put_contents('emails_log.txt', "[" . date('Y-m-d H:i:s') . "] $email → SUCCESS → $dataId\n", FILE_APPEND | LOCK_EX);
        } else {
            $send_status = "Email queued - check spam or server mail logs";
            file_put_contents('emails_log.txt', "[" . date('Y-m-d H:i:s') . "] $email → FAILED → $dataId\n", FILE_APPEND | LOCK_EX);
        }
    } else {
        $send_status = "Invalid email or data ID not found";
    }
}


$charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()_+-=[]{}|;:,.<>?';

// Initialize tables ONCE per session
if(!isset($_SESSION['tables_initialized'])) {
    $pdo->exec("DROP TABLE IF EXISTS `encoded_storage`");
    $pdo->exec("DROP TABLE IF EXISTS `char_reference`");

    $pdo->exec("CREATE TABLE `char_reference` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `row_num` TINYINT NOT NULL,
        `col_letter` CHAR(1) NOT NULL,
        `char_value` CHAR(1) NOT NULL,
        `linear_index` SMALLINT NOT NULL,
        UNIQUE KEY `unique_pos` (`row_num`, `col_letter`),
        KEY `idx_char` (`char_value`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $pdo->prepare("INSERT INTO `char_reference` (`row_num`, `col_letter`, `char_value`, `linear_index`) VALUES (?, ?, ?, ?)");
    $charset_length = strlen($charset);
    for($i = 0; $i < $charset_length; $i++) {
        if(isset($charset[$i])) {
            $row_num = floor($i / 10);
            $col_letter = chr((($i % 10) + ord('a')));
            $stmt->execute([$row_num, $col_letter, $charset[$i], $i]);
        }
    }

    $pdo->exec("CREATE TABLE `encoded_storage` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `data_id` VARCHAR(100) NOT NULL,
        `original_text` VARCHAR(255),
        `encoded_sequence` TEXT NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_data` (`data_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $_SESSION['tables_initialized'] = true;
}

// CASE SENSITIVE ENCODE - Keep original case
if($action == 'encode') {
    $input = trim($_POST['input']);
    $dataId = trim($_POST['data_id']) ?: 'data_' . time();
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS `encoded_storage` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `data_id` VARCHAR(100) NOT NULL,
        `original_text` VARCHAR(255),
        `encoded_sequence` TEXT NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_data` (`data_id`)
    )");

    $sequence = '';
    $stmt = $pdo->prepare("SELECT CONCAT(row_num, col_letter) as pos FROM char_reference WHERE char_value = ?");
    
    $input_length = strlen($input);
    for($i = 0; $i < $input_length; $i++) {
        $char = $input[$i];
        if(preg_match('/[A-Za-z0-9!@#$%^&*()_+=\[\]{}|;:,.<>?]/', $char)) {
            $stmt->execute([$char]);
            $result = $stmt->fetch();
            if($result && isset($result['pos'])) {
                $sequence .= $result['pos'];
            }
        }
    }
    
    if($sequence) {
        $stmt = $pdo->prepare("REPLACE INTO encoded_storage (data_id, original_text, encoded_sequence) VALUES (?, ?, ?)");
        $stmt->execute([$dataId, $input, $sequence]);
        $message = "ENCODED SUCCESS!<br><strong>Original:</strong> '$input'<br><strong>Sequence:</strong> <code>$sequence</code><br><strong>ID:</strong> <code style='font-size:18px'>$dataId</code>";
        $_SESSION['last_encoded_id'] = $dataId;
    } else {
        $message = "No valid characters in input";
    }
}

// RETRIEVE
if($action == 'retrieve') {
    $dataId = trim($_POST['data_id']);
    $stmt = $pdo->prepare("SELECT original_text, encoded_sequence, created_at FROM encoded_storage WHERE data_id = ?");
    $stmt->execute([$dataId]);
    $result = $stmt->fetch();
    
    if($result) {
        $message = "RETRIEVED SUCCESS!<br>
            <strong>ID:</strong> <code>$dataId</code><br>
            <strong>Original (Case Preserved):</strong> <span style='color:#28a745;font-size:22px;font-family:monospace'>{$result['original_text']}</span><br>
            <strong>Encoded:</strong> <code style='color:#007cba'>{$result['encoded_sequence']}</code><br>
            <strong>Created:</strong> " . date('H:i:s', strtotime($result['created_at']));
    } else {
        $message = "No data found for ID: <code>$dataId</code><br><strong>Tip:</strong> Encode first, copy exact ID from yellow box";
    }
}

// DECODE
if($action == 'decode') {
    $sequence = trim($_POST['sequence']);
    $text = '';
    $seq_length = strlen($sequence);
    
    for($i = 0; $i < $seq_length; $i += 2) {
        if($i + 1 < $seq_length) {
            $row_num = $sequence[$i];
            $col_letter = $sequence[$i + 1];
            $stmt = $pdo->prepare("SELECT char_value FROM char_reference WHERE row_num = ? AND col_letter = ?");
            $stmt->execute([$row_num, $col_letter]);
            $result = $stmt->fetch();
            if($result && isset($result['char_value'])) {
                $text .= $result['char_value'];
            }
        }
    }
    $message = "DECODED: <code style='color:#007cba'>$sequence</code> → <strong style='font-size:20px'>$text</strong>";
}

// REAL EMAIL LOGGING (Creates emails_log.txt file)
if($action == 'send_email') {
    $email = trim($_POST['email']);
    $dataId = trim($_POST['data_id']);
    
    $stmt = $pdo->prepare("SELECT original_text, encoded_sequence FROM encoded_storage WHERE data_id = ?");
    $stmt->execute([$dataId]);
    $result = $stmt->fetch();
    
    if($result && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email_content = "=== TIUK #MAP EMAIL - " . date('Y-m-d H:i:s') . " ===\n";
        $email_content .= "To: $email\n";
        $email_content .= "From: TIUK MAP System\n";
        $email_content .= "ID: $dataId\n";
        $email_content .= "Original: {$result['original_text']}\n";
        $email_content .= "Encoded: {$result['encoded_sequence']}\n";
        $email_content .= "Link: http://web2.test/map.php\n";
        $email_content .= "=====================================\n\n";
        
        file_put_contents('emails_log.txt', $email_content, FILE_APPEND | LOCK_EX);
        
        $send_status = "EMAIL LOGGED SUCCESSFULLY!<br>
                       <strong>To:</strong> <code>$email</code><br>
                       <strong>ID:</strong> <code>$dataId</code><br>
                       <strong>Original:</strong> <span style='color:#28a745'>{$result['original_text']}</span><br>
                       <strong>Encoded:</strong> <code>{$result['encoded_sequence']}</code><br>
                       <strong>Saved:</strong> <code>emails_log.txt</code> (check this file!)";
    } else {
        $send_status = "Invalid email or data ID not found";
    }
}

// Recent data
$recent = [];
try {
    $stmt = $pdo->query("SELECT data_id, original_text, LEFT(encoded_sequence, 20) as seq_preview, created_at 
                        FROM encoded_storage ORDER BY id DESC LIMIT 10");
    $recent = $stmt->fetchAll();
} catch(Exception $e) {}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TIUK #MAP - Case Sensitive Encoder</title>
    <link href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Ubuntu', sans-serif;
            background: linear-gradient(135deg, #f0f0f0 0%, #e0e0e0 100%);
            color: #1a1a1a;
            line-height: 1.6;
            min-height: 100vh;
            padding: 20px 0;
        }
        .container { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
        .header {
            text-align: center; background: rgba(255,255,255,0.9);
            padding: 40px 20px; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            margin-bottom: 30px; backdrop-filter: blur(10px);
        }
        .logo {
            font-size: 3.5em; font-weight: 700;
            background: linear-gradient(45deg, #1a1a1a, #333);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text; margin-bottom: 10px;
        }
        .subtitle { font-size: 1.3em; color: #444; font-weight: 300; margin-bottom: 20px; }
        .card {
            background: rgba(255,255,255,0.95); backdrop-filter: blur(15px);
            border-radius: 20px; padding: 30px; margin-bottom: 30px;
            /* box-shadow: 0 15px 50px rgba(0,0,0,0.1); border: 1px solid rgba(255,255,255,0.2); */
            transition: all 0.3s ease;
        }
        /* .card:hover { transform: translateY(-5px); box-shadow: 0 25px 70px rgba(0,0,0,0.15); } */
        .card h3 { color: #1a1a1a; font-size: 1.5em; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .form-row { display: flex; gap: 15px; align-items: end; flex-wrap: wrap; }
        .input-field {
            flex: 1; padding: 15px 20px; border: 2px solid #ddd; border-radius: 12px;
            font-size: 16px; font-family: 'Ubuntu', sans-serif; background: rgba(255,255,255,0.8);
            transition: all 0.3s ease; min-width: 280px;
        }
        .input-field:focus { outline: none; border-color: #1a1a1a; box-shadow: 0 0 0 4px rgba(26,26,26,0.1); background: white; }
        .btn {
            background: linear-gradient(135deg, #1a1a1a 0%, #333 100%); color: white; border: none;
            padding: 15px 30px; border-radius: 12px; font-size: 16px; font-weight: 500;
            font-family: 'Ubuntu', sans-serif; cursor: pointer; transition: all 0.3s ease; white-space: nowrap;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(26,26,26,0.3); }
        .btn-retrieve { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .btn-email { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .message {
            padding: 20px; border-radius: 15px; font-weight: 500; font-size: 16px; line-height: 1.6;
            margin: 20px 0; backdrop-filter: blur(10px);
        }
        .success { 
            background: linear-gradient(135deg, #a8e6cf 0%, #88d8a3 100%); 
            color: #1a1a1a; border: 2px solid #56ab2f; animation: slideIn 0.5s ease; 
        }
        .email-status { 
            background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%); 
            color: #1a1a1a; border: 2px solid #ff8c42; 
        }
        @keyframes slideIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .recent-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-top: 20px; }
        .recent-item {
            background: rgba(255,255,255,0.7); padding: 20px; border-radius: 15px; cursor: pointer;
            transition: all 0.3s ease; border: 2px solid transparent;
        }
        /* .recent-item:hover { background: white; border-color: #1a1a1a; transform: translateY(-3px); } */
        .code {
            background: rgba(26,26,26,0.9); color: #f0f0f0; padding: 8px 12px; border-radius: 8px;
            font-family: 'Ubuntu Mono', monospace; font-size: 14px; display: inline-block;
        }
        .id-highlight {
            background: linear-gradient(135deg, #ffd452 0%, #ff6b6b 100%); padding: 25px;
            border-radius: 20px; text-align: center; margin: 30px 0; border: 4px solid #ff8c42;
            box-shadow: 0 10px 30px rgba(255,107,107,0.3);
        }
        @media (max-width: 768px) {
            .container { padding: 0 15px; }
            .logo { font-size: 2.5em; }
            .form-row { flex-direction: column; }
            .input-field { min-width: 100%; }
            .recent-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <div class="header">
            <h1 class="logo">TIUK #MAP</h1>
            <p class="subtitle">Case Sensitive Character Encoding System</p>
            <p style="color: #666; font-size: 1.1em; font-weight: 500;">
                <!-- <strong>SESSION ERROR FIXED</strong> | Case Sensitive | Email Logging | Responsive | #f0f0f0 -->
            </p>
        </div>

        <?php if($message): ?>
            <div class="message success"><?= $message ?></div>
        <?php endif; ?>

        <!-- ENCODE -->
        <div class="card">
            <h3>ENCODE (Case Sensitive)</h3>
            <form method="POST">
                <input type="hidden" name="action" value="encode">
                <div class="form-row">
                    <input type="text" name="input" class="input-field" placeholder="Hello123! (keeps exact case)" required maxlength="100">
                    <input type="text" name="data_id" class="input-field" placeholder="Custom ID (auto if empty)">
                    <button type="submit" class="btn">ENCODE → STORE</button>
                </div>
            </form>
        </div>

        <!-- RETRIEVE -->
        <div class="card">
            <h3>RETRIEVE DATA</h3>
            <form method="POST">
                <input type="hidden" name="action" value="retrieve">
                <div class="form-row">
                    <input type="text" name="data_id" class="input-field" placeholder="data_1768366803" required>
                    <button type="submit" class="btn btn-retrieve">RETRIEVE DATA</button>
                </div>
            </form>
        </div>

        <!-- DECODE -->
        <div class="card">
            <h3>DECODE SEQUENCE</h3>
            <form method="POST">
                <input type="hidden" name="action" value="decode">
                <div class="form-row">
                    <input type="text" name="sequence" class="input-field" placeholder="17h2g41a41a41a71i → Hello" required>
                    <button type="submit" class="btn">DECODE</button>
                </div>
            </form>
        </div>

        <!-- EMAIL -->
        <div class="card">
            <h3>SEND VIA EMAIL (Logs to File)</h3>
            <form method="POST">
                <input type="hidden" name="action" value="send_email">
                <div class="form-row">
                    <input type="email" name="email" class="input-field" placeholder="user@example.com" required>
                    <input type="text" name="data_id" class="input-field" placeholder="data_1768366803" required>
                    <button type="submit" class="btn btn-email">LOG EMAIL</button>
                </div>
            </form>
            <?php if($send_status): ?>
                <div class="message email-status"><?= $send_status ?></div>
            <?php endif; ?>
            <?php if(file_exists('emails_log.txt')): ?>
                <div style="margin-top: 15px; padding: 15px; background: #e8f4f8; border-radius: 10px; font-size: 14px;">
                    <strong>Email Log:</strong> <code>emails_log.txt</code> (<?= number_format(filesize('emails_log.txt')) ?> bytes)
                </div>
            <?php endif; ?>
        </div>

        <!-- RECENT DATA -->
        <?php if($recent): ?>
        <div class="card">
            <h3>RECENT DATA (Click to Copy ID)</h3>
            <div class="recent-grid">
                <?php foreach($recent as $item): ?>
                <div class="recent-item" onclick="copyId('<?= addslashes($item['data_id']) ?>')">
                    <div><strong>ID:</strong> <code><?= htmlspecialchars($item['data_id']) ?></code></div>
                    <div><strong>Original:</strong> <span style="font-size:16px;font-family:monospace"><?= htmlspecialchars($item['original_text']) ?></span></div>
                    <div><code><?= htmlspecialchars($item['seq_preview']) ?>...</code></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- LAST ID HIGHLIGHT -->
        <?php if(isset($_SESSION['last_encoded_id'])): ?>
        <div class="id-highlight">
            <strong>LAST ENCODED ID - CLICK TO COPY:</strong><br><br>
            <code onclick="copyId('<?= addslashes($_SESSION['last_encoded_id']) ?>')" 
                  style="cursor:pointer;font-size:22px;padding:15px;display:inline-block;font-family:Ubuntu Mono,monospace">
                <?= htmlspecialchars($_SESSION['last_encoded_id']) ?>
            </code><br><br>
            <small style="color:#666">Paste this in RETRIEVE or EMAIL section</small>
        </div>
        <?php endif; ?>

        <!-- FOOTER INFO -->
        <div style="text-align: center; padding: 30px 20px; color: #666; font-size: 14px; background: rgba(255,255,255,0.5); border-radius: 15px; margin-top: 30px;">
            <strong>TIUK #MAP Features:</strong> Session Fixed ✓ Case Sensitive ✓ Email Logging ✓ Responsive ✓ #f0f0f0 Theme ✓ Ubuntu Font<br>
            <strong>Files:</strong> <code>emails_log.txt</code> (emails) | phpMyAdmin → <code>test.encoded_storage</code> (data)
        </div>
    </div>

    <script>
    function copyId(id) {
        navigator.clipboard.writeText(id).then(() => {
            const toast = document.createElement('div');
            toast.textContent = 'ID COPIED: ' + id;
            toast.style.cssText = `
                position: fixed; top: 20px; right: 20px; background: linear-gradient(135deg, #28a745, #20c997);
                color: white; padding: 20px 25px; border-radius: 15px; font-family: Ubuntu, sans-serif;
                font-weight: 500; font-size: 16px; z-index: 9999; box-shadow: 0 10px 30px rgba(40,167,69,0.4);
                backdrop-filter: blur(10px); transform: translateX(400px); transition: all 0.4s ease;
            `;
            document.body.appendChild(toast);
            
            setTimeout(() => toast.style.transform = 'translateX(0)', 100);
            setTimeout(() => {
                toast.style.transform = 'translateX(400px)';
                setTimeout(() => document.body.removeChild(toast), 400);
            }, 3000);
        });
    }
    </script>
</body>
</html>
