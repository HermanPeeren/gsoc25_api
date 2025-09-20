<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_ccm
 *
 * @copyright   (C) 2025 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\CCM\Administrator\Helper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Http\HttpFactory;

/**
 * Helper class for CCM Migration
 *
 * @since  4.0.0
 */
class MigrationHelper
{
    /**
     * Parse credentials for HTTP authentication headers.
     *
     * @param string $credentials Credentials string (token or username:password)
     *
     * @return array Headers array for HTTP requests
     *
     * @since 1.0.0
     */
    public static function parseAuthentication($credentials)
    {
        if (empty($credentials)) {
            return [];
        }

        $trimmed = trim($credentials, "\" \n\r\t");
        // Check for username:password
        if (strpos($trimmed, ':') !== false) {
            $encoded = base64_encode($trimmed);
            return ['Authorization' => 'Basic ' . $encoded];
        }
        // Otherwise treat as Bearer token
        return ['Authorization' => 'Bearer ' . $trimmed];
    }

    /**
     * Format date according to specified format
     */
    
    public static function formatDate($date, $format) {
        if (empty($date)) {
            return null;
        }
        try {
            $dt = new \DateTime($date);
            return $dt->format($format);
        } catch (\Exception $e) {
            return $date; // fallback to original if parsing fails
        }
    }

    /**
     * Handle media upload - prepares data for upload
     */
    public static function handleMediaUpload($item, $sourceCmsName = 'wordpress', $migrationFolderName = null) {
        $tempFile = self::downloadFile($item);
        if (!$tempFile) {
            throw new \RuntimeException('Failed to download media file');
        }
        
        try {
            // Prepare upload data (no HTTP call here)
            $uploadData = self::uploadFromFile($tempFile, $item, $sourceCmsName, $migrationFolderName);
            return $uploadData;
        } finally {
            // Clean up temp file
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Download file from source URL to temporary location
     */
    private static function downloadFile($item) {
        $sourceUrl = $item['source_url'] ?? $item['URL'] ?? $item['url'] ?? '';
        
        if (empty($sourceUrl)) {
            return false;
        }

        // Create temp file
        $tempFile = tempnam(sys_get_temp_dir(), 'ccm_media_');
        
        try {
            $http = HttpFactory::getHttp();
            $response = $http->get($sourceUrl);
            
            if ($response->code !== 200) {
                return false;
            }
            
            file_put_contents($tempFile, $response->body);            
            return $tempFile;
        } catch (\Exception $e) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            return false;
        }
    }

    /**
     * Upload file from file content
     */
    private static function uploadFromFile($tempFile, $item, $sourceCmsName, $migrationFolderName = null) {
        $sourceUrl = $item['source_url'] ?? $item['URL'] ?? $item['url'] ?? '';
        $fileName  = basename(parse_url($sourceUrl, PHP_URL_PATH));
                
        $fileContent   = file_get_contents($tempFile);
        $base64Content = base64_encode($fileContent);
        $fullPath      = $migrationFolderName . '/' . $fileName;

        $uploadData = [
            'path' => $fullPath,
            'content' => $base64Content,
        ];
        
        return $uploadData;
    }

    /**
     * Check if file type is supported for media upload
     */
    public static function isSupportedFileType($sourceUrl) {
        if (empty($sourceUrl)) {
            return false;
        }
        
        $fileName = basename(parse_url($sourceUrl, PHP_URL_PATH));
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        // Define supported file extensions for media upload
        $supportedExtensions = [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico',
            
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            
            'zip', 'rar', '7z', 'tar', 'gz',
            
            'mp3', 'wav', 'ogg', 'flac',
            
            'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm',
            
            'html', 'htm', 'css', 'js', 'json', 'xml'
            
            // Note: txt, csv, and other plain text formats are excluded
            // as they may not be suitable for media library
        ];
        
        return in_array($extension, $supportedExtensions);
    }

    /**
     * Map entity IDs using the migration map
     *
     * @param mixed $value The ID value to map
     * @param mixed $migrationMap The migration map
     * @return mixed The mapped ID or original value if no mapping found
     */
    public static function mapEntityId($value, $migrationMap, $entityType = null) {
        if (empty($value)) {
            return $value;
        }

        if ($entityType && isset($migrationMap[$entityType]['ids'][$value])) {
            return $migrationMap[$entityType]['ids'][$value];
        }
        // fallback: search all types (old behavior, optional)
        foreach ($migrationMap as $type => $mappings) {
            if (isset($mappings['ids'][$value])) {
                return $mappings['ids'][$value];
            }
        }
        return $value;
    }

    /**
     * Build a link or object based on the CCM item and mapping
     *
     * @param array $ccmItem The CCM item data
     * @param array $ccmMap The CCM mapping configuration
     * @param array $migrationMap The migration map
     * @return string|array The built link or object
     */
    public static function buildLink($ccmItem, $ccmMap, $migrationMap) {
        $template = $ccmMap['template'] ?? '';
        $params = $ccmMap['params'] ?? [];
        $builtLink = $template;
        foreach ($params as $paramKey => $paramSource) {
            $replaceValue = '';
            if ($paramSource['source'] === 'id_map') {
                $sourceId = $ccmItem[$paramSource['ccm_key']] ?? null;
                if ($sourceId) {
                    $mapType = $paramSource['map_type'];
                    // Determine map_type based on content type if needed
                    if ($mapType === 'articles' && isset($ccmItem['type']) && $ccmItem['type'] === 'category') {
                        $mapType = 'categories';
                    }
                    $replaceValue = $migrationMap[$mapType]['ids'][$sourceId] ?? '';
                }
            } elseif ($paramSource['source'] === 'map') {
                $sourceValue = $ccmItem[$paramSource['ccm_key']] ?? null;
                if ($sourceValue && isset($paramSource['map'][$sourceValue])) {
                    $replaceValue = $paramSource['map'][$sourceValue];
                }
            }
            $builtLink = str_replace(':' . $paramKey, $replaceValue, $builtLink);
        }
        return $builtLink;
    }

    /**
     * Build an object based on the CCM item and mapping
     *
     * @param array $ccmItem The CCM item data
     * @param array $ccmMap The CCM mapping configuration
     * @param array $migrationMap The migration map
     * @return array The built object
     */
    public static function buildObject($ccmItem, $ccmMap, $migrationMap) {
        $params = $ccmMap['params'] ?? [];
        $requestObject = [];
        foreach ($params as $paramKey => $paramSource) {
            if ($paramSource['source'] === 'id_map') {
                $sourceId = $ccmItem[$paramSource['ccm_key']] ?? null;
                if ($sourceId) {
                    $mapType = $paramSource['map_type'];
                    // Determine map_type based on content type if needed
                    if ($mapType === 'articles' && isset($ccmItem['type']) && $ccmItem['type'] === 'category') {
                        $mapType = 'categories';
                    }
                    $mappedId = $migrationMap[$mapType]['ids'][$sourceId] ?? null;
                    if ($mappedId) {
                        $requestObject[$paramKey] = $mappedId;
                    }
                }
            }
        }
        return $requestObject;
    }

    /**
     * Replace URLs in the given value based on the migration map.
     *
     * @param string $value The input value
     * @param array $migrationMap The migration map
     * @return string The value with replaced URLs
     */
    public static function replaceUrls($value, $migrationMap) {
        // Handle the links within the text
        // e.g. <a href="old-url">Link</a> or <img src="old-image.jpg" />
        foreach ($migrationMap as $entityType => $mappings) {
            if (isset($mappings['urls']) && is_array($mappings['urls'])) {
                foreach ($mappings['urls'] as $oldUrl => $newUrl) {
                    $oldUrlParsed = parse_url($oldUrl);
                    $oldPath = $oldUrlParsed['path'];
                    $oldPathInfo = pathinfo($oldPath);
                    $oldBasename = $oldPathInfo['filename'];
                    $oldExtension = $oldPathInfo['extension'];
                    $oldDirectory = dirname($oldPath);

                    $basePattern = $oldUrlParsed['scheme'] . '://' . $oldUrlParsed['host'];
                    if (isset($oldUrlParsed['port'])) {
                        $basePattern .= ':' . $oldUrlParsed['port'];
                    }
                    $basePattern .= $oldDirectory . '/' . $oldBasename;

                    $pattern = '/' . preg_quote($basePattern, '/') . '(?:-\d+x\d+)?\.' . preg_quote($oldExtension, '/') . '/';
                    $matches = [];
                    if (preg_match_all($pattern, $value, $matches)) {
                        foreach ($matches[0] as $foundUrl) {
                            $value = str_replace($foundUrl, $newUrl, $value);
                        }
                    }
                }
            }
        }
        return $value;
    }

    public static function createCustomFields($targetUrl, $endpoint, $targetType, $fields, $targetCms)
    {
        $fieldsEndpoint = 'fields/' . $endpoint;
        
        $headers = [
            'Accept' => 'application/vnd.api+json',
            'Content-Type' => 'application/json',
        ];

        if ($targetCms->credentials) {
            $authHeaders = self::parseAuthentication($targetCms->credentials);
            $headers = array_merge($headers, $authHeaders);
        }

        $createdFields = [];
        
        foreach ($fields as $fieldName => $fieldValue) {
            if (!is_string($fieldName)) {
                throw new \RuntimeException("Invalid custom field name: " . print_r($fieldName, true));
            }
            
            if ($fieldName[0] === '_' || isset($createdFields[$fieldName])) {
                continue;
            }
            // Determine context based on endpoint
            $contextParts = explode('/', $endpoint);
            $component = 'com_' . $contextParts[0];
            $entityType = isset($contextParts[1]) ? rtrim($contextParts[1], 's') : $targetType;
            $context = $component . '.' . $entityType;

            $createdFields[$fieldName] = true;

            $fieldData = [
                "context" => $context,
                "description" => "",
                "language" => "*",
                "params" => [
                    "suffix" => ""
                ],
                "title" => $fieldName,
                "label" => $fieldName,
                "type" => "text",
                "required" => 0,
                "state" => 1,
                "access" => 1
            ];

            error_log("[MigrationModel] Creating custom field: $fieldName");
            error_log("[MigrationModel] Creating custom context: $context");
            error_log("[MigrationModel] Field data: " . json_encode($fieldData));

            $http = HttpFactory::getHttp();
            error_log("url: " . $targetUrl . '/' . $fieldsEndpoint);
            $response = $http->post($targetUrl . '/' . $fieldsEndpoint, json_encode($fieldData), $headers);

            if ($response->code !== 201 && $response->code !== 200) {
                throw new \RuntimeException("Failed to create custom field '$fieldName' - HTTP " . $response->code . ": " . $response->body);
            }

            error_log("[MigrationModel] Created custom field: $fieldName");
        }
    }
}
