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
}
