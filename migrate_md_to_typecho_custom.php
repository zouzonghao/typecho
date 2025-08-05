<?php
/**
 * 自定义Markdown博客迁移到Typecho脚本
 * 支持多种front matter格式
 */

class CustomMarkdownToTypechoMigrator
{
    private $dbPath;
    private $pdo;
    private $authorId = 1;
    private $options = [];
    private $stats = [
        'total' => 0,
        'success' => 0,
        'failed' => 0,
        'skipped' => 0
    ];
    
    // Gemini API配置
    private $geminiApiUrl = 'https://dgbpmavfphmr.ap-northeast-1.clawcloudrun.com/v1beta/models/gemini-2.5-flash-lite-preview-06-17:generateContent';
    private $geminiToken = 'sk-jRBLUCqV708TCaXEYG0eXSkfZk6N_XqQWUZatl_XfIzXMB3Z';
    
    public function __construct($dbPath = 'usr/typecho.db', $options = [])
    {
        $this->dbPath = $dbPath;
        $this->options = array_merge([
            'dry_run' => false,
            'verbose' => false,
            'backup_db' => true,
            'skip_existing' => false,
            'default_category' => '默认分类',
            'default_status' => 'publish',
            'use_ai_category' => true
        ], $options);
        
        $this->connectDatabase();
        
        if ($this->options['backup_db'] && !$this->options['dry_run']) {
            $this->backupDatabase();
        }
    }
    
    private function connectDatabase()
    {
        try {
            $this->pdo = new PDO("sqlite:{$this->dbPath}");
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->log("数据库连接成功");
        } catch (PDOException $e) {
            die("数据库连接失败: " . $e->getMessage());
        }
    }
    
    private function backupDatabase()
    {
        $backupPath = $this->dbPath . '.backup.' . date('Y-m-d-H-i-s');
        if (copy($this->dbPath, $backupPath)) {
            $this->log("数据库已备份到: {$backupPath}");
        } else {
            $this->log("警告: 无法备份数据库", 'WARNING');
        }
    }
    
    private function log($message, $level = 'INFO')
    {
        $timestamp = date('Y-m-d H:i:s');
        $levelStr = str_pad($level, 7);
        echo "[{$timestamp}] {$levelStr}: {$message}\n";
    }
    
    /**
     * 解析Markdown文件的front matter
     * 支持多种格式：YAML、JSON、TOML等
     */
    private function parseFrontMatter($content)
    {
        $frontMatter = [];
        
        // 检查是否有YAML front matter
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $content, $matches)) {
            $yaml = $matches[1];
            $lines = explode("\n", $yaml);
            
            $currentKey = '';
            $inArray = false;
            $arrayItems = [];
            
            foreach ($lines as $line) {
                $line = rtrim($line); // 保留前导空格
                if (empty($line) || strpos($line, '#') === 0) continue;
                
                // 检查是否是新的键值对
                if (preg_match('/^([^:]+):\s*(.+)$/', $line, $parts)) {
                    // 保存之前的数组
                    if ($inArray && !empty($arrayItems)) {
                        $frontMatter[$currentKey] = implode(',', $arrayItems);
                    }
                    
                    $currentKey = trim($parts[1]);
                    $value = trim($parts[2]);
                    $inArray = false;
                    $arrayItems = [];
                    
                    // 检查是否是数组开始
                    if ($currentKey === 'tags' && empty($value)) {
                        $inArray = true;
                    } elseif ($currentKey === 'tags' && strpos($value, '[') === 0 && strpos($value, ']') !== false) {
                        // 解析单行数组格式的标签
                        $frontMatter[$currentKey] = $this->parseArrayTags($value);
                    } else {
                        $frontMatter[$currentKey] = trim($value, '"\'');
                    }
                } elseif (preg_match('/^([^:]+):\s*$/', $line, $parts)) {
                    // 处理没有值的键（如 tags:）
                    $currentKey = trim($parts[1]);
                    if ($currentKey === 'tags') {
                        $inArray = true;
                        $arrayItems = [];
                    }
                } elseif ($inArray && preg_match('/^\s*-\s*(.+)$/', $line, $parts)) {
                    // 解析数组项
                    $item = trim($parts[1], '"\' ');
                    if (!empty($item)) {
                        $arrayItems[] = $item;
                    }
                } elseif ($inArray && empty(trim($line))) {
                    // 空行，继续数组
                    continue;
                } elseif ($inArray && !preg_match('/^\s*-\s/', $line) && !empty(trim($line))) {
                    // 数组结束
                    $inArray = false;
                    if (!empty($arrayItems)) {
                        $frontMatter[$currentKey] = implode(',', $arrayItems);
                    }
                    $currentKey = '';
                    $arrayItems = [];
                }
            }
            
            // 保存最后一个数组
            if ($inArray && !empty($arrayItems)) {
                $frontMatter[$currentKey] = implode(',', $arrayItems);
            }
        }
        
        return $frontMatter;
    }
    
    /**
     * 解析数组格式的标签
     */
    private function parseArrayTags($tagString)
    {
        // 移除方括号
        $tagString = trim($tagString, '[]');
        
        // 分割标签
        $tags = [];
        if (!empty($tagString)) {
            // 支持多种分隔符：逗号、空格等
            $tagArray = preg_split('/[,\s]+/', $tagString);
            foreach ($tagArray as $tag) {
                $tag = trim($tag, '"\' ');
                if (!empty($tag)) {
                    $tags[] = $tag;
                }
            }
        }
        
        return implode(',', $tags);
    }
    
    /**
     * 使用AI分析文章分类
     */
    private function analyzeCategoryWithAI($title, $description, $content)
    {
        try {
            // 构建分类分析提示词
            $prompt = "请仔细分析以下文章内容，将其分类到以下分类之一：\n";
            $prompt .= "1. 开发 - 编程代码实现、算法设计、系统架构、技术开发新东西的相关内容\n";
            $prompt .= "2. 软件 - 软件使用教程、软件评测、软件配置、软件应用相关内容\n";
            $prompt .= "3. 硬件 - 硬件设备评测、硬件技术、硬件配置、硬件相关相关内容\n";
            $prompt .= "4. 工具与脚本 - 工具使用指南、脚本编写、效率工具、自动化相关内容\n";
            $prompt .= "5. 学习 - 学习笔记、知识分享、教程、经验总结相关内容\n";
            $prompt .= "6. 生活随笔 - 个人生活、感悟、随笔、日常记录相关内容\n";
            $prompt .= "7. 技术杂谈 - 技术讨论、技术观点、技术趋势、技术分析相关内容\n\n";
            $prompt .= "分类原则：\n";
            $prompt .= "- 如果是具体的编程实现了新东西、代码开发，选择'开发'\n";
            $prompt .= "- 如果是软件使用教程、配置指南，选择'软件'\n";
            $prompt .= "- 如果是工具使用、脚本编写，选择'工具与脚本'\n";
            $prompt .= "- 如果是学习笔记、知识分享，选择'学习'\n";
            $prompt .= "- 如果是个人生活记录，选择'生活随笔'\n";
            $prompt .= "- 如果是技术讨论、分析，选择'技术杂谈'\n\n";
            $prompt .= "请只返回分类名称，不要其他内容。如果无法明确分类，请返回'技术杂谈'作为兜底分类。\n\n";
            $prompt .= "文章标题：{$title}\n";
            if (!empty($description)) {
                $prompt .= "文章描述：{$description}\n";
            }
            $prompt .= "文章内容：\n" . substr($content, 0, 3000) . "...";
            
            $data = [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => $prompt
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'maxOutputTokens' => 50,
                    'temperature' => 0.3
                ]
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->geminiApiUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->geminiToken
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($httpCode === 200 && !$error) {
                $result = json_decode($response, true);
                if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                    $category = trim($result['candidates'][0]['content']['parts'][0]['text']);
                    
                    // 验证分类是否在允许的范围内
                    $allowedCategories = ['开发', '软件', '硬件', '工具与脚本', '学习', '生活随笔', '技术杂谈'];
                    if (!in_array($category, $allowedCategories)) {
                        $category = '技术杂谈'; // 兜底分类
                    }
                    
                    $this->log("AI分类分析成功: {$category}");
                    return $category;
                }
            }
            
            $this->log("AI分类分析失败，使用兜底分类", 'WARNING');
            return '技术杂谈';
            
        } catch (Exception $e) {
            $this->log("AI分类分析异常: " . $e->getMessage(), 'ERROR');
            return '技术杂谈';
        }
    }
    
    /**
     * 使用AI生成文章摘要
     */
    private function generateAISummary($title, $description, $content)
    {
        try {
            // 构建提示词
            $prompt = "请为以下文章生成一个35字以内的摘要，从文章的功能、适用场景、解决了什么问题等方面来写：\n\n";
            $prompt .= "标题：{$title}\n";
            if (!empty($description)) {
                $prompt .= "描述：{$description}\n";
            }
            $prompt .= "内容：\n" . substr($content, 0, 5000) . "...";
            
            $data = [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => $prompt
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'maxOutputTokens' => 100,
                    'temperature' => 0.7
                ]
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->geminiApiUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->geminiToken
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($httpCode === 200 && !$error) {
                $result = json_decode($response, true);
                if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                    $summary = trim($result['candidates'][0]['content']['parts'][0]['text']);
                    $this->log("AI摘要生成成功: {$summary}");
                    return $summary;
                }
            }
            
            $this->log("AI摘要生成失败，使用默认摘要", 'WARNING');
            return "文章摘要";
            
        } catch (Exception $e) {
            $this->log("AI摘要生成异常: " . $e->getMessage(), 'ERROR');
            return "文章摘要";
        }
    }
    
    /**
     * 从Markdown内容中提取纯文本内容
     */
    private function extractMarkdownContent($content, $title = '', $description = '')
    {
        // 移除front matter
        $content = preg_replace('/^---\s*\n.*?\n---\s*\n/s', '', $content);
        
        // 检查是否已有 <!-- more --> 标记
        $hasMoreTag = preg_match('/<!--\s*more\s*-->/i', $content);
        
        // 移除现有的 <!-- more --> 标记（如果存在）
        $content = preg_replace('/<!--\s*more\s*-->/i', '', $content);
        
        // 生成AI摘要
        $aiSummary = $this->generateAISummary($title, $description, $content);
        
        // 添加Typecho的Markdown标识
        $content = '<!--markdown-->' . $aiSummary . "\n\n<!--more-->\n\n" . trim($content);
        
        return $content;
    }
    
    /**
     * 生成slug
     */
    private function generateSlug($title)
    {
        $slug = strtolower($title);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        $slug = trim($slug, '-');
        
        return $slug ?: 'post-' . time();
    }
    
    /**
     * 检查slug是否已存在
     */
    private function isSlugExists($slug)
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM typecho_contents WHERE slug = ?");
        $stmt->execute([$slug]);
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * 生成唯一slug
     */
    private function generateUniqueSlug($title)
    {
        $slug = $this->generateSlug($title);
        $originalSlug = $slug;
        $counter = 1;
        
        while ($this->isSlugExists($slug)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }
        
        return $slug;
    }
    
    /**
     * 创建或获取分类
     */
    private function createOrGetCategory($categoryName)
    {
        if (empty($categoryName)) {
            return 1; // 返回默认分类ID
        }
        
        // 检查分类是否已存在
        $stmt = $this->pdo->prepare("SELECT mid FROM typecho_metas WHERE name = ? AND type = 'category'");
        $stmt->execute([$categoryName]);
        $mid = $stmt->fetchColumn();
        
        if ($mid) {
            return $mid;
        }
        
        // 创建新分类
        $slug = $this->generateSlug($categoryName);
        $stmt = $this->pdo->prepare("
            INSERT INTO typecho_metas (name, slug, type, description, count, \"order\", parent) 
            VALUES (?, ?, 'category', ?, 0, 0, 0)
        ");
        $stmt->execute([$categoryName, $slug, $categoryName]);
        
        $newMid = $this->pdo->lastInsertId();
        $this->log("创建新分类: {$categoryName} (ID: {$newMid})");
        
        return $newMid;
    }
    
    /**
     * 创建或获取标签
     */
    private function createOrGetTags($tags)
    {
        if (empty($tags)) {
            return [];
        }
        
        $tagIds = [];
        $tagArray = is_array($tags) ? $tags : explode(',', $tags);
        
        foreach ($tagArray as $tagName) {
            $tagName = trim($tagName);
            if (empty($tagName)) continue;
            
            // 检查标签是否已存在
            $stmt = $this->pdo->prepare("SELECT mid FROM typecho_metas WHERE name = ? AND type = 'tag'");
            $stmt->execute([$tagName]);
            $mid = $stmt->fetchColumn();
            
            if (!$mid) {
                // 创建新标签
                $slug = $this->generateSlug($tagName);
                $stmt = $this->pdo->prepare("
                    INSERT INTO typecho_metas (name, slug, type, description, count, \"order\", parent) 
                    VALUES (?, ?, 'tag', ?, 0, 0, 0)
                ");
                $stmt->execute([$tagName, $slug, $tagName]);
                $mid = $this->pdo->lastInsertId();
                $this->log("创建新标签: {$tagName} (ID: {$mid})");
            }
            
            $tagIds[] = $mid;
        }
        
        return $tagIds;
    }
    
    /**
     * 创建文章关系（分类和标签）
     */
    private function createRelationships($cid, $categoryId, $tagIds)
    {
        // 添加分类关系
        if ($categoryId && $categoryId != 1) {
            $stmt = $this->pdo->prepare("INSERT INTO typecho_relationships (cid, mid) VALUES (?, ?)");
            $stmt->execute([$cid, $categoryId]);
        }
        
        // 添加标签关系
        foreach ($tagIds as $tagId) {
            $stmt = $this->pdo->prepare("INSERT INTO typecho_relationships (cid, mid) VALUES (?, ?)");
            $stmt->execute([$cid, $tagId]);
        }
    }
    
    /**
     * 更新分类和标签的计数
     */
    private function updateMetaCounts($categoryId, $tagIds)
    {
        // 更新分类计数
        if ($categoryId && $categoryId != 1) {
            $stmt = $this->pdo->prepare("UPDATE typecho_metas SET count = count + 1 WHERE mid = ?");
            $stmt->execute([$categoryId]);
        }
        
        // 更新标签计数
        foreach ($tagIds as $tagId) {
            $stmt = $this->pdo->prepare("UPDATE typecho_metas SET count = count + 1 WHERE mid = ?");
            $stmt->execute([$tagId]);
        }
    }
    
    /**
     * 迁移单个Markdown文件
     */
    public function migrateMarkdownFile($filePath)
    {
        $this->stats['total']++;
        
        if (!file_exists($filePath)) {
            $this->log("文件不存在: {$filePath}", 'ERROR');
            $this->stats['failed']++;
            return false;
        }
        
        try {
            $content = file_get_contents($filePath);
            if ($content === false) {
                throw new Exception("无法读取文件");
            }
            
            $frontMatter = $this->parseFrontMatter($content);
            
            // 获取文章信息，支持多种字段名
            $title = $frontMatter['title'] ?? basename($filePath, '.md');
            $description = $frontMatter['description'] ?? '';
            
            $markdownContent = $this->extractMarkdownContent($content, $title, $description);
            
            // 获取其他文章信息
            $slug = $frontMatter['slug'] ?? $this->generateUniqueSlug($title);
            
            // 支持多种日期字段
            $date = $frontMatter['date'] ?? $frontMatter['publishDate'] ?? $frontMatter['created'] ?? date('Y-m-d H:i:s');
            
            // 支持多种分类字段，如果没有指定分类则使用AI分析
            $category = $frontMatter['category'] ?? $frontMatter['categories'] ?? '';
            
            // 如果没有在front matter中指定分类，则使用AI分析
            if (empty($category) && $this->options['use_ai_category']) {
                $category = $this->analyzeCategoryWithAI($title, $description, $content);
            } elseif (empty($category)) {
                $category = $this->options['default_category'];
            }
            
            // 支持多种标签字段
            $tags = $frontMatter['tags'] ?? '';
            
            // 支持多种状态字段
            $status = $frontMatter['status'] ?? $frontMatter['published'] ?? $this->options['default_status'];
            
            // 检查是否跳过已存在的文章
            if ($this->options['skip_existing'] && $this->isSlugExists($slug)) {
                $this->log("跳过已存在的文章: {$title} (slug: {$slug})", 'WARNING');
                $this->stats['skipped']++;
                return false;
            }
            
            // 转换日期格式
            $created = strtotime($date);
            if ($created === false) {
                $created = time();
                $this->log("警告: 无法解析日期 '{$date}'，使用当前时间", 'WARNING');
            }
            
            if ($this->options['dry_run']) {
                            $this->log("模拟迁移: {$title} -> {$slug}");
            if ($this->options['verbose']) {
                $this->log("  - 日期: {$date}");
                $this->log("  - 分类: {$category}");
                $this->log("  - 标签: {$tags}");
                $this->log("  - 状态: {$status}");
                $this->log("  - Front Matter: " . json_encode($frontMatter, JSON_UNESCAPED_UNICODE));
            }
                $this->stats['success']++;
                return true;
            }
            
            // 创建或获取分类
            $categoryId = $this->createOrGetCategory($category);
            
            // 创建或获取标签
            $tagIds = $this->createOrGetTags($tags);
            
            // 插入文章
            $stmt = $this->pdo->prepare("
                INSERT INTO typecho_contents (
                    title, slug, created, modified, text, \"order\", authorId, 
                    template, type, status, password, commentsNum, 
                    allowComment, allowPing, allowFeed, parent, views
                ) VALUES (
                    ?, ?, ?, ?, ?, 0, ?, NULL, 'post', ?, NULL, 0, '1', '1', '1', 0, 0
                )
            ");
            
            $stmt->execute([
                $title,
                $slug,
                $created,
                $created,
                $markdownContent,
                $this->authorId,
                $status
            ]);
            
            $cid = $this->pdo->lastInsertId();
            
            // 创建关系
            $this->createRelationships($cid, $categoryId, $tagIds);
            
            // 更新计数
            $this->updateMetaCounts($categoryId, $tagIds);
            
            $this->log("成功迁移文章: {$title} (ID: {$cid}, slug: {$slug})");
            if ($this->options['verbose']) {
                $this->log("  - 日期: {$date}");
                $this->log("  - 分类: {$category}");
                $this->log("  - 标签: {$tags}");
                $this->log("  - 状态: {$status}");
            }
            $this->stats['success']++;
            return $cid;
            
        } catch (Exception $e) {
            $this->log("迁移文件失败 {$filePath}: " . $e->getMessage(), 'ERROR');
            $this->stats['failed']++;
            return false;
        }
    }
    
    /**
     * 递归获取所有Markdown文件
     */
    private function getMarkdownFiles($directory)
    {
        $files = [];
        
        if (!is_dir($directory)) {
            return $files;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'md') {
                $files[] = $file->getPathname();
            }
        }
        
        return $files;
    }
    
    /**
     * 批量迁移Markdown文件
     */
    public function migrateMarkdownDirectory($directory)
    {
        if (!is_dir($directory)) {
            $this->log("目录不存在: {$directory}", 'ERROR');
            return false;
        }
        
        $files = $this->getMarkdownFiles($directory);
        $totalCount = count($files);
        
        $this->log("找到 {$totalCount} 个Markdown文件（包含子目录）");
        
        if ($this->options['dry_run']) {
            $this->log("运行在模拟模式下，不会实际修改数据库");
        }
        
        foreach ($files as $file) {
            $this->migrateMarkdownFile($file);
        }
        
        $this->printStats();
        return $this->stats['success'];
    }
    
    /**
     * 打印统计信息
     */
    private function printStats()
    {
        $this->log("=== 迁移统计 ===");
        $this->log("总计: {$this->stats['total']}");
        $this->log("成功: {$this->stats['success']}");
        $this->log("失败: {$this->stats['failed']}");
        $this->log("跳过: {$this->stats['skipped']}");
        
        if ($this->stats['failed'] > 0) {
            $this->log("有 {$this->stats['failed']} 个文件迁移失败，请检查错误日志", 'WARNING');
        }
    }
    
    /**
     * 设置作者ID
     */
    public function setAuthorId($authorId)
    {
        $this->authorId = $authorId;
    }
    
    /**
     * 获取统计信息
     */
    public function getStats()
    {
        return $this->stats;
    }
}

// 命令行使用
if (php_sapi_name() === 'cli') {
    $options = [
        'dry_run' => false,
        'verbose' => false,
        'backup_db' => true,
        'skip_existing' => false,
        'use_ai_category' => true
    ];
    
    // 解析命令行参数
    $args = $argv;
    array_shift($args); // 移除脚本名称
    
    foreach ($args as $arg) {
        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
        } elseif ($arg === '--verbose') {
            $options['verbose'] = true;
        } elseif ($arg === '--no-backup') {
            $options['backup_db'] = false;
        } elseif ($arg === '--skip-existing') {
            $options['skip_existing'] = true;
        } elseif ($arg === '--no-ai-category') {
            $options['use_ai_category'] = false;
        }
    }
    
    if (empty($args) || in_array($args[0], ['--help', '-h'])) {
        echo "自定义Markdown到Typecho迁移工具\n";
        echo "支持多种front matter格式\n";
        echo "使用方法: php migrate_md_to_typecho_custom.php <markdown目录路径> [选项]\n";
        echo "\n选项:\n";
        echo "  --dry-run        模拟运行，不实际修改数据库\n";
        echo "  --verbose        详细输出\n";
        echo "  --no-backup      不备份数据库\n";
        echo "  --skip-existing  跳过已存在的文章\n";
        echo "  --no-ai-category 禁用AI自动分类功能\n";
        echo "  --help, -h       显示帮助信息\n";
        echo "\n支持的front matter字段:\n";
        echo "  - title: 文章标题\n";
        echo "  - description: 文章描述\n";
        echo "  - date/publishDate: 发布日期\n";
        echo "  - category/categories: 分类（如未指定，将使用AI自动分类）\n";
        echo "  - tags: 标签（支持数组格式）\n";
        echo "  - slug: 文章别名\n";
        echo "  - status: 发布状态\n";
        echo "\nAI自动分类支持以下分类：\n";
        echo "  - 开发：编程、代码、软件开发、技术实现相关内容\n";
        echo "  - 软件：软件使用、软件评测、软件教程相关内容\n";
        echo "  - 硬件：硬件设备、硬件评测、硬件技术相关内容\n";
        echo "  - 工具与脚本：工具使用、脚本编写、效率工具相关内容\n";
        echo "  - 学习：学习笔记、教程、知识分享相关内容\n";
        echo "  - 生活随笔：个人生活、感悟、随笔相关内容\n";
        echo "  - 技术杂谈：技术讨论、技术观点、技术趋势相关内容（兜底分类）\n";
        echo "\n示例:\n";
        echo "  php migrate_md_to_typecho_custom.php /path/to/markdown/files\n";
        echo "  php migrate_md_to_typecho_custom.php /path/to/markdown/files --dry-run --verbose\n";
        echo "  php migrate_md_to_typecho_custom.php /path/to/markdown/files --no-ai-category\n";
        exit(1);
    }
    
    $markdownDir = $args[0];
    $migrator = new CustomMarkdownToTypechoMigrator('usr/typecho.db', $options);
    $migrator->migrateMarkdownDirectory($markdownDir);
}
?> 