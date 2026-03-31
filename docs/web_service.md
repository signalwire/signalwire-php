# WebService Documentation

The `WebService` class provides static file serving capabilities for the SignalWire AI Agents SDK. It follows the same architectural pattern as other services, allowing it to run as a standalone service or alongside your AI agents.

## Table of Contents
- [Overview](#overview)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [Security Features](#security-features)
- [HTTPS/SSL Support](#httpsssl-support)
- [API Endpoints](#api-endpoints)
- [Usage Examples](#usage-examples)
- [Deployment Patterns](#deployment-patterns)

## Overview

WebService is designed to serve static files with configurable security features. It is ideal for:
- Serving agent documentation and API specs
- Hosting static assets (images, CSS, JavaScript)
- Serving generated reports and exports
- Providing configuration files and templates
- Hosting agent UI components

### Key Features
- **Multiple directory mounting** - Serve different directories at different URL paths
- **Security-first design** - Authentication, CORS, security headers, file filtering
- **HTTPS support** - Full SSL/TLS support with PEM files
- **Directory browsing** - Optional HTML directory listings
- **MIME type handling** - Automatic content-type detection
- **Path traversal protection** - Prevents access outside designated directories
- **File filtering** - Allow/block specific file extensions

## Installation

WebService is included in the core SignalWire AI Agents SDK:

```bash
composer require signalwire/signalwire-agents
```

## Quick Start

```php
<?php
require 'vendor/autoload.php';

use SignalWire\Service\WebService;

// Create a service to serve files
$service = new WebService(
    port: 8002,
    directories: [
        '/docs'   => './documentation',
        '/assets' => './static/assets',
    ]
);

// Start the service
$service->start();
// Service available at http://localhost:8002
// Basic Auth: dev:w00t (auto-generated)
```

## Configuration

WebService can be configured through multiple methods (in order of priority):

### 1. Constructor Parameters

```php
$service = new WebService(
    port:                    8002,
    directories:             [
        '/docs'   => './documentation',
        '/assets' => './static',
    ],
    basicAuth:               ['admin', 'secret'],
    enableDirectoryBrowsing: true,
    allowedExtensions:       ['.html', '.css', '.js'],
    blockedExtensions:       ['.env', '.key'],
    maxFileSize:             100 * 1024 * 1024, // 100 MB
    enableCors:              true,
);
```

### 2. Environment Variables

```bash
# Basic authentication
export SWML_BASIC_AUTH_USER="admin"
export SWML_BASIC_AUTH_PASS="secretpassword"

# SSL/HTTPS configuration
export SWML_SSL_ENABLED=true
export SWML_SSL_CERT="/path/to/cert.pem"
export SWML_SSL_KEY="/path/to/key.pem"

# Security settings
export SWML_ALLOWED_HOSTS="example.com,*.example.com"
export SWML_CORS_ORIGINS="https://app.example.com"
```

### 3. Configuration File

Create a `web.json` or `swml_web.json` file:

```json
{
    "service": {
        "port": 8002,
        "directories": {
            "/docs": "./documentation",
            "/api": "./api-specs",
            "/reports": "./generated/reports"
        },
        "enable_directory_browsing": true,
        "max_file_size": 52428800,
        "allowed_extensions": [".html", ".css", ".js", ".json", ".pdf"],
        "blocked_extensions": [".env", ".key", ".pem"]
    },
    "security": {
        "basic_auth": {
            "username": "admin",
            "password": "secure123"
        },
        "ssl_enabled": true,
        "ssl_cert": "/etc/ssl/certs/server.crt",
        "ssl_key": "/etc/ssl/private/server.key",
        "allowed_hosts": ["*"],
        "cors_origins": ["*"]
    }
}
```

## Security Features

### Basic Authentication

WebService implements HTTP Basic Authentication. Credentials can be set via:

1. **Constructor**: `basicAuth: ['username', 'password']`
2. **Environment**: `SWML_BASIC_AUTH_USER` and `SWML_BASIC_AUTH_PASS`
3. **Config file**: `security.basic_auth` section
4. **Auto-generated**: If not specified, generates random credentials

### File Security

#### Default Blocked Extensions/Files
- `.env`, `.git`, `.gitignore`
- `.key`, `.pem`, `.crt`
- `.php` (server-side scripts), `__pycache__`
- `.DS_Store`, `.swp`

#### Path Traversal Protection
WebService prevents access outside designated directories:
```
# These attempts will be blocked:
# GET /docs/../../../etc/passwd
# GET /docs/./././../config.json
```

#### File Size Limits
Default maximum file size is 100 MB. Configure with:
```php
$service = new WebService(maxFileSize: 50 * 1024 * 1024); // 50 MB
```

### Security Headers

Automatically adds security headers to all responses:
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `X-XSS-Protection: 1; mode=block`
- `Strict-Transport-Security` (when HTTPS is enabled)

## HTTPS/SSL Support

WebService provides multiple ways to enable HTTPS:

### Method 1: Environment Variables

```bash
# Using file paths
export SWML_SSL_CERT="/path/to/cert.pem"
export SWML_SSL_KEY="/path/to/key.pem"
```

### Method 2: Direct Parameters

```php
$service = new WebService(directories: ['/docs' => './docs']);
$service->start(
    sslCert: '/path/to/cert.pem',
    sslKey:  '/path/to/key.pem',
);
// Service available at https://localhost:8002
```

### Method 3: Configuration File

```json
{
    "security": {
        "ssl_enabled": true,
        "ssl_cert": "/etc/ssl/certs/server.crt",
        "ssl_key": "/etc/ssl/private/server.key"
    }
}
```

### Generating Self-Signed Certificates

For development/testing:

```bash
# Generate a self-signed certificate
openssl req -x509 -newkey rsa:4096 -keyout key.pem -out cert.pem \
    -days 365 -nodes -subj "/CN=localhost"

# Use with WebService
export SWML_SSL_CERT="cert.pem"
export SWML_SSL_KEY="key.pem"
```

## API Endpoints

### GET /health
Health check endpoint (no authentication required)

**Response:**
```json
{
    "status": "healthy",
    "directories": ["/docs", "/assets"],
    "ssl_enabled": false,
    "auth_required": true,
    "directory_browsing": true
}
```

### GET /
Root endpoint showing available directories

**Response:** HTML page listing all mounted directories

### GET /{route}/{file_path}
Serve files from mounted directories

**Parameters:**
- `route`: The mounted directory route (e.g., `/docs`)
- `file_path`: Path to file within the directory

**Response:**
- File content with appropriate MIME type
- 404 if file not found
- 403 if file type blocked or directory browsing disabled

## Usage Examples

### Basic File Serving

```php
use SignalWire\Service\WebService;

// Serve documentation
$service = new WebService(
    directories: [
        '/docs' => './documentation',
        '/api'  => './api-specs',
    ]
);
$service->start();

// Files accessible at:
// http://localhost:8002/docs/index.html
// http://localhost:8002/api/swagger.json
```

### With Directory Browsing

```php
$service = new WebService(
    directories:             ['/files' => './public'],
    enableDirectoryBrowsing: true,
);
$service->start();

// Browse files at: http://localhost:8002/files/
```

### Restricted File Types

```php
// Only serve web assets
$service = new WebService(
    directories:             ['/web' => './www'],
    allowedExtensions:       ['.html', '.css', '.js', '.png', '.jpg', '.woff2'],
    enableDirectoryBrowsing: false,
);
```

### Dynamic Directory Management

```php
$service = new WebService();

// Add directories after initialisation
$service->addDirectory('/docs', './documentation');
$service->addDirectory('/reports', './generated/reports');

// Remove a directory
$service->removeDirectory('/reports');

$service->start();
```

### With Custom Authentication

```php
$service = new WebService(
    directories: ['/private' => './sensitive-docs'],
    basicAuth:   ['admin', 'super-secret-password'],
);
$service->start();
```

## Deployment Patterns

### Standalone Service

Run WebService as a dedicated static file server:

```php
<?php
// web_server.php
require 'vendor/autoload.php';

use SignalWire\Service\WebService;

$service = new WebService(
    port:        8002,
    directories: [
        '/docs'      => '/var/www/docs',
        '/assets'    => '/var/www/assets',
        '/downloads' => '/var/www/downloads',
    ]
);
$service->start();
```

### Alongside AI Agents

Run WebService alongside your AI agents on different ports:

```php
<?php
// main.php
require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\Service\WebService;

// Start WebService in the background
$web = new WebService(
    port:        8002,
    directories: ['/docs' => './agent-docs'],
);
// Start web service (non-blocking in a real setup)
$web->start();

// Run your agent
$agent = new AgentBase(name: 'My Agent', route: '/agent', port: 3000);
$agent->promptAddSection('Documentation', 'User docs available at https://example.com:8002/docs/');
$agent->run();
```

### Docker Deployment

```dockerfile
FROM php:8.2-cli

WORKDIR /app

# Install Composer and dependencies
COPY composer.json composer.lock ./
RUN curl -sS https://getcomposer.org/installer | php && \
    php composer.phar install --no-dev

# Copy static files
COPY ./static /app/static
COPY ./web_config.json /app/web_config.json

# Expose port
EXPOSE 8002

CMD ["php", "web_server.php"]
```

### Nginx Reverse Proxy

For production, use Nginx as a reverse proxy:

```nginx
server {
    listen 80;
    server_name static.example.com;

    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name static.example.com;

    ssl_certificate /etc/ssl/certs/example.com.crt;
    ssl_certificate_key /etc/ssl/private/example.com.key;

    location / {
        proxy_pass http://localhost:8002;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        location ~* \.(jpg|jpeg|png|gif|ico|css|js)$ {
            proxy_pass http://localhost:8002;
            expires 1h;
            add_header Cache-Control "public, immutable";
        }
    }
}
```

## Best Practices

### Security
1. **Always use HTTPS in production** - Protect data in transit
2. **Change default credentials** - Never use auto-generated auth in production
3. **Restrict file types** - Use `allowedExtensions` to whitelist safe files
4. **Disable directory browsing** - Turn off in production environments
5. **Use reverse proxy** - Put Nginx/Apache in front for additional security

### Performance
1. **Set appropriate cache headers** - WebService adds 1-hour cache by default
2. **Limit file sizes** - Adjust `maxFileSize` based on your needs
3. **Use CDN for static assets** - Offload traffic for better performance
4. **Compress large files** - Use gzip/brotli at reverse proxy level

### Organisation
1. **Separate content types** - Use different routes for different file types
2. **Version your assets** - Include version in path (e.g., `/assets/v1/`)
3. **Use index.html** - Provide default files for directories
4. **Document your structure** - Maintain clear directory organisation

## API Reference

### WebService Class

```php
class WebService
{
    public function __construct(
        int     $port = 8002,
        array   $directories = [],
        ?array  $basicAuth = null,
        ?string $configFile = null,
        bool    $enableDirectoryBrowsing = false,
        ?array  $allowedExtensions = null,
        ?array  $blockedExtensions = null,
        int     $maxFileSize = 104857600, // 100 MB
        bool    $enableCors = true
    );
}
```

#### Parameters
- `port`: Port to bind to (default: 8002)
- `directories`: Associative array mapping URL paths to local directories
- `basicAuth`: Array of `[username, password]` for authentication
- `configFile`: Path to JSON configuration file
- `enableDirectoryBrowsing`: Allow directory listing (default: false)
- `allowedExtensions`: Array of allowed file extensions
- `blockedExtensions`: Array of blocked file extensions
- `maxFileSize`: Maximum file size in bytes (default: 100 MB)
- `enableCors`: Enable CORS headers (default: true)

#### Methods

##### start()
```php
public function start(
    string  $host = '0.0.0.0',
    ?int    $port = null,
    ?string $sslCert = null,
    ?string $sslKey = null
): void
```
Start the web service.

##### addDirectory()
```php
public function addDirectory(string $route, string $directory): void
```
Add a new directory to serve.

##### removeDirectory()
```php
public function removeDirectory(string $route): void
```
Remove a directory from being served.

## Summary

WebService provides a secure, configurable static file server that integrates with the SignalWire AI Agents SDK. It follows the same architectural patterns as other SDK services, making it familiar and easy to use while providing configurable security features and flexible deployment options.
