#!/bin/bash

echo "开始部署Typecho项目..."

# 创建必要的目录
echo "创建必要的目录..."
mkdir -p nginx/logs
mkdir -p php
mkdir -p usr/sqlite

# 设置文件权限
echo "设置文件权限..."
chmod -R 777 .

# 构建并启动容器
echo "构建并启动Docker容器..."
docker compose up -d --build

# 等待服务启动
echo "等待服务启动..."
sleep 20

# 检查服务状态
echo "检查服务状态..."
docker compose ps

echo "部署完成！"
