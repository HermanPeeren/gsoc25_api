# Authentication Configuration Guide

## Overview

The CCM component now supports full authentication headers instead of simple credentials. This allows for more flexible authentication methods including OAuth, API Keys, and custom headers.

## Database Changes

The `authentication` field stores JSON data containing:
- `type`: Authentication type (basic, bearer, oauth, custom)
- `headers`: Complete HTTP headers for authentication

## Authentication Structure

### Basic Authentication
```json
{
  "type": "basic",
  "headers": {
    "Authorization": "Basic base64_encoded_username:password"
  }
}
```

### Bearer Token Authentication
```json
{
  "type": "bearer",
  "headers": {
    "Authorization": "Bearer your_token_here"
  }
}
```

### OAuth Authentication
```json
{
  "type": "oauth",
  "headers": {
    "Authorization": "Bearer oauth_token",
    "X-API-Key": "api_key"
  }
}
```

### Custom Authentication
```json
{
  "type": "custom",
  "headers": {
    "Authorization": "Custom auth_value",
    "X-API-Key": "api_key",
    "X-Custom-Header": "custom_value"
  }
}
```
