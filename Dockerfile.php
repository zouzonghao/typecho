FROM php:8.1-fpm

# 安装系统依赖
# (这一部分保持不变)
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    libcurl4-openssl-dev \
    libonig-dev \
    libxml2-dev \
    libssl-dev \
    libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/*

# -------------------- 修改开始 --------------------

# 配置并安装PHP扩展
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        pdo_sqlite \
        mbstring \
        curl \
        zip \
        xml \
        opcache

# -------------------- 修改结束 --------------------

# 设置工作目录
WORKDIR /var/www/html

# 设置文件权限
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# 创建PHP-FPM配置目录
RUN mkdir -p /usr/local/etc/php-fpm.d

# 设置时区
RUN echo "date.timezone = Asia/Shanghai" > /usr/local/etc/php/conf.d/timezone.ini

# 优化PHP配置
RUN echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/memory.ini \
    && echo "upload_max_filesize = 64M" >> /usr/local/etc/php/conf.d/upload.ini \
    && echo "post_max_size = 64M" >> /usr/local/etc/php/conf.d/upload.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/timeout.ini \
    && echo "max_input_vars = 3000" >> /usr/local/etc/php/conf.d/vars.ini

# 暴露9000端口
EXPOSE 9000

# 启动PHP-FPM
CMD ["php-fpm"]

