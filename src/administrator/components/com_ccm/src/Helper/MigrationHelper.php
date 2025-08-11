<?php
namespace Reem\Component\CCM\Administrator\Helper;

use Joomla\CMS\Http\HttpFactory;

/**
 * Helper class for CCM Migration
 *
 * @since  4.0.0
 */
class MigrationHelper
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
        error_log("[MigrationHelper] Starting media upload for item: " . json_encode($item));
        
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
                error_log("[MigrationHelper] Cleaned up temp file: $tempFile");
            }
        }
    }

    /**
     * Download file from source URL to temporary location
     */
    private static function downloadFile($item) {
        $sourceUrl = $item['source_url'] ?? $item['URL'] ?? $item['url'] ?? '';
        
        if (empty($sourceUrl)) {
            error_log("[MigrationHelper] No source URL found in item");
            return false;
        }
        
        error_log("[MigrationHelper] Downloading file from: $sourceUrl");
        
        // Create temp file
        $tempFile = tempnam(sys_get_temp_dir(), 'ccm_media_');
        
        try {
            $http = HttpFactory::getHttp();
            $response = $http->get($sourceUrl);
            
            if ($response->code !== 200) {
                error_log("[MigrationHelper] Failed to download file - HTTP {$response->code}");
                return false;
            }
            
            file_put_contents($tempFile, $response->body);
            error_log("[MigrationHelper] Downloaded " . strlen($response->body));
            
            return $tempFile;
        } catch (\Exception $e) {
            error_log("[MigrationHelper] Download exception: " . $e->getMessage());
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
        $fileName = basename(parse_url($sourceUrl, PHP_URL_PATH));
        
        error_log("[MigrationHelper] Preparing upload - File: $fileName");
        
        $fileContent = file_get_contents($tempFile);
        $base64Content = base64_encode($fileContent);
        $fullPath = $migrationFolderName . '/' . $fileName;
        
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
    public static function mapEntityId($value, $migrationMap) {
        if (empty($value)) {
            return $value;
        }

        foreach ($migrationMap as $entityType => $mappings) {
            // Check for ID mapping
            if (isset($mappings['ids']) && isset($mappings['ids'][$value])) {
                error_log("[MigrationHelper] 🔗 Mapping ID '$value' using entity type '$entityType' → '{$mappings['ids'][$value]}'");
                return $mappings['ids'][$value];
            }
        }
        
        return $value; // Return original value if no mapping found
    }
}
