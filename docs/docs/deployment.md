# Deployment
[[toc]]

## Introduction

When you're ready to deploy your Hypervel application to production, there are some important things you can do to make sure your application is running as efficiently as possible. In this document, we'll cover some great starting points for making sure your Hypervel application is deployed properly.

## Server Requirements

Hypervel has a few system requirements. You should ensure that your web server has the following minimum PHP version and extensions:

 - PHP >= 8.2
 - [Swoole PHP extension](https://github.com/swoole/swoole-src) >= 5.1
 - JSON PHP extension
 - PDO PHP extension
 - Pcntl PHP extension
 - Session extension (If you need to use session)
 - OpenSSL PHP extension (If you need to use the HTTPS)
 - Redis PHP extension (If you need to use the Redis Client)

### Docker

If you plan to deploy your application via docker, you can find various Dockerfiles in the [hyperf/hyperf-docker](https://github.com/hyperf/hyperf-docker) project, or use a pre-built image based on [hyperf/hyperf](https://hub.docker.com/r/hyperf/hyperf).

## Server Configuration

### Nginx for HTTP

If you are deploying your application to a server that is running Nginx, you may use the following configuration file as a starting point for configuring your web server. Most likely, this file will need to be customized depending on your server's configuration.

```nginx
server {
    # listening port
    listen 80;
    # Bound domain name, fill in your domain name
    server_name proxy.hypervel.org;

    location / {
        # Forward the client's Host and IP information to the corresponding node
        proxy_set_header Host $http_host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;

        # Forward cookies, set SameSite
        proxy_cookie_path / "/; secure; HttpOnly; SameSite=strict";

        # Execute proxy access to real server
        proxy_pass http://127.0.0.1:9501;
    }
}
```

### Nginx for Websocket

If you plan to proxy your websocket services, you may use the following configuration file.

```nginx
server {
    listen 80;
    server_name websocket.hypervel.org;

    location / {
        # WebSocket Header
        proxy_http_version 1.1;
        proxy_set_header Upgrade websocket;
        proxy_set_header Connection "Upgrade";

        # Forward the client's Host and IP information to the corresponding node
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header Host $http_host;

        # The connection between the client and the server is automatically disconnected after 60s of no interaction, please set according to the actual business scenario
        proxy_read_timeout 60s ;

        # Execute proxy access to real server
        proxy_pass http://127.0.0.1:9502;
    }
}
```