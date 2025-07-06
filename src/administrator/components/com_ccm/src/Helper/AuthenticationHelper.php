<?php
namespace Reem\Component\CCM\Administrator\Helper;

/**
 * Authentication Helper Class
 * 
 * Handles different authentication methods for various CMS platforms
 */
class AuthenticationHelper
{
    /**
     * Parse authentication data from database
     * 
     * @param string $authenticationJson JSON string containing authentication data
     * @return array Headers array for HTTP requests
     */
    public static function parseAuthentication($authenticationJson)
    {
        if (empty($authenticationJson)) {
            return [];
        }

        $authData = json_decode($authenticationJson, true);
        if (!$authData || !isset($authData['headers'])) {
            return [];
        }

        return $authData['headers'];
    }

    /**
     * Create WordPress Application Password authentication
     * 
     * @param string $username WordPress username
     * @param string $applicationPassword Application password
     * @return string JSON authentication string
     */
    public static function createWordPressAuth($username, $applicationPassword)
    {
        $credentials = base64_encode($username . ':' . $applicationPassword);
        
        return json_encode([
            'type' => 'wordpress_app_password',
            'headers' => [
                'Authorization' => 'Basic ' . $credentials
            ]
        ]);
    }

    /**
     * Create Bearer Token authentication
     * 
     * @param string $token Bearer token
     * @return string JSON authentication string
     */
    public static function createBearerAuth($token)
    {
        return json_encode([
            'type' => 'bearer',
            'headers' => [
                'Authorization' => 'Bearer ' . $token
            ]
        ]);
    }

    /**
     * Create Basic Authentication
     * 
     * @param string $username Username
     * @param string $password Password
     * @return string JSON authentication string
     */
    public static function createBasicAuth($username, $password)
    {
        $credentials = base64_encode($username . ':' . $password);
        
        return json_encode([
            'type' => 'basic',
            'headers' => [
                'Authorization' => 'Basic ' . $credentials
            ]
        ]);
    }

    /**
     * Create OAuth authentication
     * 
     * @param string $token OAuth token
     * @param string $apiKey Optional API key
     * @return string JSON authentication string
     */
    public static function createOAuthAuth($token, $apiKey = null)
    {
        $headers = [
            'Authorization' => 'Bearer ' . $token
        ];

        if ($apiKey) {
            $headers['X-API-Key'] = $apiKey;
        }

        return json_encode([
            'type' => 'oauth',
            'headers' => $headers
        ]);
    }

    /**
     * Create custom authentication with multiple headers
     * 
     * @param array $headers Array of headers
     * @return string JSON authentication string
     */
    public static function createCustomAuth($headers)
    {
        return json_encode([
            'type' => 'custom',
            'headers' => $headers
        ]);
    }

    /**
     * Validate authentication JSON structure
     * 
     * @param string $authenticationJson JSON string
     * @return bool True if valid, false otherwise
     */
    public static function validateAuthentication($authenticationJson)
    {
        if (empty($authenticationJson)) {
            return true; // Empty auth is valid (no authentication)
        }

        $authData = json_decode($authenticationJson, true);
        
        if (!$authData) {
            return false; // Invalid JSON
        }

        // Must have type and headers
        if (!isset($authData['type']) || !isset($authData['headers'])) {
            return false;
        }

        // Headers must be an array
        if (!is_array($authData['headers'])) {
            return false;
        }

        return true;
    }

    /**
     * Get authentication type from JSON
     * 
     * @param string $authenticationJson JSON string
     * @return string|null Authentication type or null if not found
     */
    public static function getAuthenticationType($authenticationJson)
    {
        if (empty($authenticationJson)) {
            return null;
        }

        $authData = json_decode($authenticationJson, true);
        
        return $authData['type'] ?? null;
    }
}
