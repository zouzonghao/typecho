<?php
/**
 * 基于 prismjs 的代码语法高亮插件<br />可显示语言类型、行号，有复制功能<br />(请勿与其它同类插件同时启用，以免互相影响)<br />详细说明：<a target="_blank" href="https://github.com/Copterfly/CodeHighlighter_for_typecho">https://github.com/Copterfly/CodeHighlighter-for-Typecho</a>
 * 
 * @package CodeHighlighter 
 * @author Copterfly
 * @version 1.0.1
 * @link https://www.copterfly.cn
 */
class CodeHighlighter_Plugin implements Typecho_Plugin_Interface {
     /**
     * 激活插件方法,如果激活失败,直接抛出异常
     * 
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate() {
        Typecho_Plugin::factory('Widget_Archive')->header = array(__CLASS__, 'header');
        Typecho_Plugin::factory('Widget_Archive')->footer = array(__CLASS__, 'footer');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * 
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate(){}

    /**
     * 获取插件配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form){
        //设置代码风格样式
        $styles = array_map('basename', glob(dirname(__FILE__) . '/static/styles/*.css'));
        $styles = array_combine($styles, $styles);
        $name = new Typecho_Widget_Helper_Form_Element_Select('code_style', $styles, 'okaikia.css', _t('选择高亮主题风格'));
        $form->addInput($name->addRule('enum', _t('必须选择主题'), $styles));
        $showLineNumber = new Typecho_Widget_Helper_Form_Element_Checkbox('showLineNumber', array('showLineNumber' => _t('显示行号')), array('showLineNumber'), _t('是否在代码左侧显示行号'));
        $form->addInput($showLineNumber);
        
        // 复制功能说明
        $copyInfo = new Typecho_Widget_Helper_Layout('div', array('class' => 'description'));
        $copyInfo->html(_t('复制功能使用Prism.js内置插件，支持现代Clipboard API，无需额外JavaScript文件。'));
        $form->addItem($copyInfo);
    }

    /**
     * 个人用户的配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}

    /**
     * 插件实现方法
     * 
     * @access public
     * @return void
     */
    public static function render() {
        
    }

    /**
     * 检测页面是否包含代码块
     * 
     * @access private
     * @param Widget_Archive $archive
     * @return bool
     */
    private static function hasCodeBlock($archive) {
        // 只在文章页面和独立页面检查
        if (!$archive->is('single')) {
            return false;
        }
        
        // 获取文章内容
        $content = $archive->content;
        
        // 检查是否包含代码块标记
        // 检查 Markdown 代码块: ``` 或 ~~~ 
        if (preg_match('/```[\s\S]*?```/', $content) || preg_match('/~~~[\s\S]*?~~~/', $content)) {
            return true;
        }
        
        // 检查缩进代码块 (4个空格或制表符)
        if (preg_match('/^\s{4,}.*$/m', $content)) {
            return true;
        }
        
        // 检查行内代码: `code`
        if (preg_match('/`[^`]+`/', $content)) {
            return true;
        }
        
        return false;
    }

    /**
     *为header添加css文件
     *@return void
     */
    public static function header($header, $archive) {
        // 检查是否包含代码块
        if (!self::hasCodeBlock($archive)) {
            return;
        }
        
        $style = Helper::options()->plugin('CodeHighlighter')->code_style;
        $cssUrl = Helper::options()->pluginUrl . '/CodeHighlighter/static/styles/' . $style;
        echo '<link rel="stylesheet" type="text/css" href="' . $cssUrl . '" />';
    }

    /**
     *为footer添加js文件
     *@return void
     */
    public static function footer($archive) {
        // 检查是否包含代码块
        if (!self::hasCodeBlock($archive)) {
            return;
        }
        
        $jsUrl = Helper::options()->pluginUrl . '/CodeHighlighter/static/prism.js';
        $showLineNumber = Helper::options()->plugin('CodeHighlighter')->showLineNumber;
        
        if ($showLineNumber) {
            echo <<<HTML
<script type="text/javascript">
	(function(){
		var pres = document.querySelectorAll('pre');
		var lineNumberClassName = 'line-numbers';
		pres.forEach(function (item, index) {
			item.className = item.className == '' ? lineNumberClassName : item.className + ' ' + lineNumberClassName;
		});
	})();
</script>

HTML;
        }
        
        echo <<<HTML
<script type="text/javascript" src="{$jsUrl}"></script>

HTML;
    }
}
