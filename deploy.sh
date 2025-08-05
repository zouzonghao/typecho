#!/bin/bash

echo "开始部署Typecho项目..."

# 创建必要的目录
echo "创建必要的目录..."
mkdir -p nginx/logs
mkdir -p php
mkdir -p usr/sqlite

# 设置文件权限
echo "设置文件权限..."
chmod -R 755 .
chmod -R 777 usr/sqlite
chmod -R 777 var

# 构建并启动容器
echo "构建并启动Docker容器..."
docker compose up -d --build

# 等待服务启动
echo "等待服务启动..."
sleep 10

# 检查服务状态
echo "检查服务状态..."
docker compose ps

echo "部署完成！"
echo "访问地址: http://localhost"
echo "SQLite数据库文件位置: usr/sqlite/"
echo ""
echo "常用命令:"
echo "  查看日志: docker compose logs"
echo "  停止服务: docker compose down"
echo "  重启服务: docker compose restart" 