<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>

        </div><!-- end .row -->
    </div>
</div><!-- end #body -->

<footer id="footer" role="contentinfo">
    &copy; <?php echo date('Y'); ?> <a href="<?php $this->options->siteUrl(); ?>"><?php $this->options->title(); ?></a>.
    <?php _e('由 <a href="https://typecho.org">Typecho</a> 强力驱动'); ?>.
</footer><!-- end #footer -->

<!-- [BEGIN] 多关键字搜索JS处理 -->
<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function () {
    const searchForm = document.getElementById('search');
    
    if (searchForm) {
        searchForm.addEventListener('submit', function (e) {
            // 1. 阻止表单的默认提交行为
            e.preventDefault(); 
            
            // 2. 获取输入框的原始值
            const keywordsInput = this.querySelector('input[name="s"]');
            const keywords = keywordsInput.value;
            
            // 3. 如果关键字为空，则不进行任何操作
            if (!keywords || !keywords.trim()) {
                return;
            }
            
            // 4. 获取站点的根URL
            const siteUrl = '<?php $this->options->siteUrl(); ?>';
            
            // 5. 将关键字编码后，构建最终的搜索URL
            // 例如: http://yoursite.com/search/docker%2Cmac
            const searchUrl = siteUrl + 'search/' + encodeURIComponent(keywords);
            
            // 6. 指示浏览器跳转到这个新URL
            window.location.href = searchUrl;
        });
    }
});
</script>
<!-- [END] 多关键字搜索JS处理 -->

<?php $this->footer(); ?>
</body>
</html>
