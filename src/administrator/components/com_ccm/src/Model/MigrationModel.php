<?php
namespace Reem\Component\CCM\Administrator\Model;

use Joomla\CMS\MVC\Model\FormModel;
use Joomla\CMS\Http\HttpFactory;
use \Joomla\CMS\Http\Http;
use Joomla\CMS\Factory;
use Reem\Component\CCM\Administrator\Helper\MigrationHelper;

/**
 * Class Migration
 *
 * @since  4.0.0
 */
class MigrationModel extends FormModel
{
    private $migrationMap = [
    // 'categories' => ['ids' => [oldId => newId, ...]]
    // 'users'      => ['ids' => [oldId => newId, ...]]
    // 'media'      => ['ids' => [oldId => newId, ...], 'urls' => [oldUrl => newUrl, ...]]
    ];
    protected $migrationMapFile;
    protected $http;

    public function __construct($config = [], $http = null)
    {
        parent::__construct($config);
        $this->migrationMapFile = dirname(__DIR__, 1) . '/Schema/migrationMap.json';

        if ($http && !($http instanceof Http)) {
            $http = null;
        }
        $this->http = $http ?: HttpFactory::getHttp();

        error_log("[MigrationModel] Initialized http client: " . get_class($this->http));
    }

    public function getItem($pk = null)
    {
        return [];
    }

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_ccm.migration',
            'migration',
            [
                'control'   => 'jform',
                'load_data' => $loadData,
            ]
        );

        if (empty($form)) {
            return false;
        }

        return $form;
    }

    protected function loadFormData()
    {
        $app  = Factory::getApplication();
        $data = $app->getUserState('com_ccm.edit.migration.data', []);

        if (empty($data)) {
            $data = $this->getItem();
        }
        return $data;
    }
    /**
     * Migrate from source CMS to target CMS.
     *
     * @return  void
     *
     * @since   4.0.0
     */
    public function migrate($sourceCmsId, $targetCmsId, $sourceType, $targetType)
    {
        $db = $this->getDatabase();

        // Get source CMS info
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__ccm_cms'))
            ->where($db->quoteName('id') . ' = ' . (int) $sourceCmsId);
        $db->setQuery($query);
        $sourceCms = $db->loadObject();

        // Get target CMS info
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__ccm_cms'))
            ->where($db->quoteName('id') . ' = ' . (int) $targetCmsId);
        $db->setQuery($query);
        $targetCms = $db->loadObject();

        // error_log("[MigrationModel] Source CMS: {$sourceCms->name} ({$sourceCms->url})");
        // error_log("[MigrationModel] Target CMS: {$targetCms->name} ({$targetCms->url})");
        // error_log("[MigrationModel] Migration: $sourceType -> $targetType");

        $sourceItems = $this->getSourceItems($sourceCms, $sourceType);
        // error_log("[MigrationModel] Retrieved " . count($sourceItems) . " items from source");
        
        $sourceToCcmItems = $this->convertSourceCmsToCcm($sourceCms, $sourceItems, $sourceType);
        // error_log("[MigrationModel] Converted " . count($sourceToCcmItems) . " items to CCM format");
        
        $result           = $this->convertCcmToTargetCms($sourceToCcmItems, $targetCms, $targetType);
        $config           = $result['config'];
        $ccmToTargetItems = $result['items'];
        
        // error_log("[MigrationModel] Converted " . count($ccmToTargetItems) . " items to target format");
        // error_log("[MigrationModel] Using config: " . json_encode($config));

        $targetMigrationStatus = $this->migrateItemsToTargetCms($targetCms, $targetType, $ccmToTargetItems, $config, $sourceCms);

        return $targetMigrationStatus;
    }

    private function getSourceItems($sourceCms, $sourceType) {
        $sourceUrl = $sourceCms->url;
        $sourceAuthentication = $sourceCms->authentication;

        // Load source schema to get endpoint info
        $sourceSchemaFile = strtolower($sourceCms->name) . '-ccm.json';        
        $schemaPath = dirname(__DIR__, 1) . '/Schema/';
        $schema = json_decode(file_get_contents($schemaPath . $sourceSchemaFile), true);

        // Find the endpoint for this source type
        $endpoint = $sourceType;
        if (isset($schema['ContentItem']) && is_array($schema['ContentItem'])) {
            foreach ($schema['ContentItem'] as $contentItem) {
                if (isset($contentItem['type']) && $contentItem['type'] === $sourceType) {
                    $endpoint = $contentItem['config']['endpoint'] ?? $sourceType;
                    break;
                }
            }
        }

        $sourceEndpoint = $sourceUrl . '/' . $endpoint;

        $headers = [
            'Accept' => 'application/json',
        ];

        if ($sourceAuthentication) {
            $authHeaders = MigrationHelper::parseAuthentication($sourceAuthentication);
            $headers = array_merge($headers, $authHeaders);
            error_log("[MigrationModel] Using authentication headers: " . json_encode($authHeaders));
        }

        $sourceResponse = $this->http->get($sourceEndpoint, $headers);
        // error_log("[MigrationModel] Source response code: " . $sourceResponse->code);
        // error_log("[MigrationModel] Source response body: " . $sourceResponse->body);

        $sourceResponseBody = json_decode($sourceResponse->body, true);

        if (is_array($sourceResponseBody) && isset($sourceResponseBody[$sourceType]) && is_array($sourceResponseBody[$sourceType])) {
            // error_log("[MigrationModel] Found items under key: $sourceType");
            return $sourceResponseBody[$sourceType];
        } elseif (is_array($sourceResponseBody) && isset($sourceResponseBody['items']) && is_array($sourceResponseBody['items'])) {
            // error_log("[MigrationModel] Found items under key: items");
            return $sourceResponseBody['items'];
        } elseif (is_array($sourceResponseBody)) {
            // error_log("[MigrationModel] Source response body is array, returning as items");
            return $sourceResponseBody;
        }

        // error_log("[MigrationModel] Could not find items to migrate in source response.");
        throw new \RuntimeException('Could not find items to migrate in source response.');
    }

    private function convertSourceCmsToCcm($sourceCms, $sourceItems, $sourceType) {
        $sourceSchemaFile = strtolower($sourceCms->name) . '-ccm.json';        
        $schemaPath       = dirname(__DIR__, 1) . '/Schema/';
        // error_log("[MigrationModel] Loading source schema: " . $schemaPath . $sourceSchemaFile);
        $schema = json_decode(file_get_contents($schemaPath . $sourceSchemaFile), true);
        // error_log("schema: " . json_encode($schema, JSON_PRETTY_PRINT));
        // Find the ContentItem with the matching type
        $sourceToCcm = [];
        if (isset($schema['ContentItem']) && is_array($schema['ContentItem'])) {
            foreach ($schema['ContentItem'] as $contentItem) {
                if (isset($contentItem['type']) && $contentItem['type'] === $sourceType && isset($contentItem['properties'])) {
                    $sourceToCcm = $contentItem['properties'];
                    // error_log("[MigrationModel] Found mapping for source type: $sourceType");
                    break;
                }
            }
        }
        // error_log("sourceToCcm: " . json_encode($sourceToCcm, JSON_PRETTY_PRINT));

        $ccmItems = [];
        foreach ($sourceItems as $item) {
            $ccmItem = [];
            foreach ($sourceToCcm as $sourceKey => $ccmKey) {
                if ($ccmKey && isset($item[$sourceKey])) {
                    $ccmItem[$ccmKey] = $item[$sourceKey];
                }
            }
            $ccmItems[] = $ccmItem;
        }

        // error_log("[MigrationModel] Converted " . count($ccmItems) . " source items to CCM format.");
        // error_log("[MigrationModel] CCM items: " . json_encode($ccmItems));
        return $ccmItems;
    }

    private function convertCcmToTargetCms($ccmItems, $targetCms, $targetType) {
        $targetSchemaFile = strtolower($targetCms->name) . '-ccm.json';
        $schemaPath       = dirname(__DIR__, 1) . '/Schema/';
        $ccmToTarget      = json_decode(file_get_contents($schemaPath . $targetSchemaFile), true);

        // Find the ContentItem with the matching type
        $targetToCcm = [];
        $config = [];
        if (isset($ccmToTarget['ContentItem']) && is_array($ccmToTarget['ContentItem'])) {
            // error_log("[MigrationModel] Looking for mapping for target type: $targetType");
            foreach ($ccmToTarget['ContentItem'] as $contentItem) {
                // error_log("[MigrationModel] Content item type: " . ($contentItem['type'] ?? 'undefined'));
                if (isset($contentItem['type']) && $contentItem['type'] === $targetType && isset($contentItem['properties'])) {
                    $targetToCcm = $contentItem['properties'];
                    $config = $contentItem['config'] ?? [];
                    // error_log("[MigrationModel] Found mapping for target type: $targetType");
                    break;
                }
            }
        }
        if (empty($targetToCcm)) {
            // error_log("[MigrationModel] No mapping found for target CMS type: $targetType");
            throw new \RuntimeException('No mapping found for target CMS type: ' . $targetType);
        }


        // Load migration map once for this item
        if (file_exists($this->migrationMapFile)) {
            $this->migrationMap = json_decode(file_get_contents($this->migrationMapFile), true) ?: [];
            error_log("[MigrationModel] Loaded migration map from file: " . json_encode($this->migrationMap, JSON_PRETTY_PRINT));
        } else {
            error_log("[MigrationModel] Migration map file does not exist: " . $this->migrationMapFile);
        }

        $targetItems = [];
        foreach ($ccmItems as $ccmItem) {
            $targetItem = [];
            foreach ($targetToCcm as $targetKey => $ccmMap) {
                if (is_array($ccmMap)) {
                    $ccmKey = $ccmMap['ccm'] ?? null;
                    $type   = $ccmMap['type'] ?? null;
                    $value  = null;

                    if ($ccmKey && isset($ccmItem[$ccmKey])) {
                        $value = $ccmItem[$ccmKey];
                        // ------------------- Map the Referenced IDs -------------------
                        if (($type === 'string' || $type === 'integer') && (is_array($value) || is_object($value))) {
                            $arr = is_object($value) ? (array)$value : $value;
                            // error_log("[MigrationModel] Extracting value from array/object for target key '$targetKey': " . json_encode($arr));
                            $first = reset($arr);
                            // error_log("[MigrationModel] First element extracted: " . json_encode($first));
                            if (is_array($first) && (isset($first['id']) || isset($first['ID']))) {
                                $value = $first['id'] ?? $first['ID'];
                            } elseif (is_array($first)) {
                                $value = reset($first);
                            }
                            // error_log("[MigrationModel] Extracted value from array/object for target key '$targetKey': " . json_encode($value));
                        }

                        if (($type === 'string' || $type === 'integer') && !empty($value)) {
                            // error_log("[MigrationModel] Checking for ID mapping for target key '$targetKey' with value: $value");
                            // error_log('$this->migrationMap: ' . json_encode($this->migrationMap));
                            foreach ($this->migrationMap as $entityType => $mappings) {
                                // Check for ID mapping
                                if (isset($mappings['ids']) && isset($mappings['ids'][$value])) {
                                    // error_log("[MigrationModel] Mapping ID '$value' for target key '$targetKey' in type '$targetType' using entity type '$entityType'.");
                                    $value = $mappings['ids'][$value];
                                    break;
                                }
                            }
                        }

                        // Handle URL mapping for string fields that likely contain content with URLs
                        // $contentFields = ['articletext', 'context', 'content', 'text', 'description', 'summary', 'excerpt', 'body'];
                        if ($type === 'string' && !empty($value) && is_string($value) 
                            // && in_array($targetKey, $contentFields)
                            ) {
                            error_log("[MigrationModel] Processing content field '$targetKey' for URL replacement. Original value: " . substr($value, 0, 200) . "...");
                            $originalValue = $value;
                            $replacementCount = 0;
                            
                            foreach ($this->migrationMap as $entityType => $mappings) {
                                if (isset($mappings['urls']) && is_array($mappings['urls'])) {
                                    error_log("[MigrationModel] Checking URL mappings for entity type '$entityType': " . count($mappings['urls']) . " URLs available");
                                    // Replace all URLs within the text using regex pattern matching
                                    foreach ($mappings['urls'] as $oldUrl => $newUrl) {
                                        // Handle WordPress image variants (e.g., image-300x200.jpg) and base URLs
                                        // Extract the base filename without size suffix
                                        $oldUrlParsed = parse_url($oldUrl);
                                        $oldPath = $oldUrlParsed['path'];
                                        $oldPathInfo = pathinfo($oldPath);
                                        $oldBasename = $oldPathInfo['filename']; // filename without extension
                                        $oldExtension = $oldPathInfo['extension'];
                                        $oldDirectory = dirname($oldPath);
                                        
                                        // Build the base pattern for all variants (including original)
                                        $basePattern = $oldUrlParsed['scheme'] . '://' . $oldUrlParsed['host'];
                                        if (isset($oldUrlParsed['port'])) {
                                            $basePattern .= ':' . $oldUrlParsed['port'];
                                        }
                                        $basePattern .= $oldDirectory . '/' . $oldBasename;
                                        
                                        // Pattern matches both: original image AND sized variants
                                        // e.g., image.jpg OR image-300x200.jpg OR image-1024x768.jpg
                                        $pattern = '/' . preg_quote($basePattern, '/') . '(?:-\d+x\d+)?\.' . preg_quote($oldExtension, '/') . '/';
                                        $matches = [];
                                        if (preg_match_all($pattern, $value, $matches)) {
                                            foreach ($matches[0] as $foundUrl) {
                                                error_log("[MigrationModel] ✓ Found URL '$foundUrl' matching pattern for '$oldUrl' in field '$targetKey'");
                                                $value = str_replace($foundUrl, $newUrl, $value);
                                                $replacementCount++;
                                                error_log("[MigrationModel] ✓ Replaced with '$newUrl'");
                                            }
                                        }
                                    }
                                }
                            }
                            
                            if ($replacementCount > 0) {
                                error_log("[MigrationModel] ✓ URL replacement complete for field '$targetKey': $replacementCount URLs replaced");
                                error_log("[MigrationModel] Final value: " . substr($value, 0, 200) . "...");
                            } else {
                                error_log("[MigrationModel] ✗ No URLs found to replace in content field '$targetKey'");
                            }
                        }

                        // Handle value mapping (e.g., status string to int)
                        if (isset($ccmMap['map']) && is_array($ccmMap['map'])) {
                            $value = $ccmMap['map'][$value] ?? ($ccmMap['default'] ?? $value);
                        }
                        // Handle date format if needed
                        if (isset($ccmMap['format_date']) && !empty($value)) {
                            $format = $ccmMap['format_date'];
                            $value = MigrationHelper::formatDate($value, $format);
                        }

                    }
                    if (empty($value) && isset($ccmMap['default'])) {
                        $value = $ccmMap['default'];
                    }

                    $targetItem[$targetKey] = $value;
                } else {
                    // Simple mapping
                    if ($ccmMap && isset($ccmItem[$ccmMap])) {
                        $targetItem[$targetKey] = $ccmItem[$ccmMap];
                    }
                }
            }
            // error_log("[MigrationModel] Mapped CCM item to target item: " . json_encode($targetItem));
            $targetItems[] = $targetItem;
        }

        // error_log("[MigrationModel] Converted " . count($targetItems) . " CCM items to target CMS format.");
        // error_log("[MigrationModel] Target items after conversion from CCM: " . json_encode($targetItems));
        return [
            'items' => $targetItems,
            'config' => $config
        ];
    }

    private function migrateItemsToTargetCms($targetCms, $targetType, $ccmToTargetItems, $config = [], $sourceCms = null) {
        // error_log("[MigrationModel] Starting migration to target CMS: {$targetCms->name}");
        // error_log("[MigrationModel] Target type: $targetType, Items count: " . count($ccmToTargetItems));
        // error_log("[MigrationModel] Config: " . json_encode($config));
        
        $targetAuthentication = $targetCms->authentication;        
        $endpoint             = $config['endpoint'] ?? $targetType;
        $targetUrl            = $targetCms->url;
        $targetEndpoint       = $targetUrl . '/' . $endpoint;

        // Create migration folder name once for this entire migration batch
        if ($targetType === 'media') {
            $sourceCmsName = $sourceCms ? strtolower($sourceCms->name) : 'unknown';
            $migrationFolderName_ForMedia = null;
            if ($targetType === 'media') {
                $dateTimeFolder = date('Y_m_d_H_i_s'); // e.g., "2025_07_19_14_30_45"
                $migrationFolderName_ForMedia = "migration/{$sourceCmsName}/{$dateTimeFolder}";
                error_log("[MigrationModel] Using migration folder: $migrationFolderName_ForMedia");
            }
        }

        foreach ($ccmToTargetItems as $idx => $item) {
            error_log("[MigrationModel] Processing item #" . ($idx + 1) . "/" . count($ccmToTargetItems));
            error_log("[MigrationModel] Item data: " . json_encode($item, JSON_UNESCAPED_SLASHES));
            
            $headers = [
                'Accept' => 'application/vnd.api+json',
            ];

            if ($targetAuthentication) {
                $authHeaders = MigrationHelper::parseAuthentication($targetAuthentication);
                $headers = array_merge($headers, $authHeaders);
                error_log("[MigrationModel] Using authentication headers: " . json_encode($authHeaders));
            }

            if ($targetType === 'media') {
                error_log("[MigrationModel] Using JSON media upload for item #" . ($idx + 1));
                $sourceUrl = $item['source_url'] ?? $item['URL'] ?? $item['url'] ?? '';
                if (!MigrationHelper::isSupportedFileType($sourceUrl)) {
                    $fileName = basename(parse_url($sourceUrl, PHP_URL_PATH));
                    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    error_log("[MigrationModel] Skipping unsupported file type: $fileName (.$extension)");
                    continue; // Skip this item and move to next
                }

                $uploadData = MigrationHelper::handleMediaUpload($item, $sourceCmsName, $migrationFolderName_ForMedia);
                $headers['Content-Type'] = 'application/json';
                error_log("[MigrationModel] headers: " . json_encode($headers));
                error_log("[MigrationModel] targetEndpoint: $targetEndpoint");
                $response = $this->http->post($targetEndpoint, json_encode($uploadData), $headers);
            } else {
                error_log("[MigrationModel] Using JSON POST for item #" . ($idx + 1));
                $headers['Content-Type'] = 'application/json';
                $requestBody = json_encode($item);
                error_log("[MigrationModel] Request body: " . $requestBody);
                $response = $this->http->post($targetEndpoint, $requestBody, $headers);
            }

            error_log("[MigrationModel] Response for item #" . ($idx + 1) . " - Code: {$response->code}, Body: " . substr($response->body, 0, 1000));

            if ($response->code === 201 || $response->code === 200) {
                error_log("[MigrationModel] Successfully migrated item #" . ($idx + 1));
                $responseBody = json_decode($response->body, true);
                $newId = $responseBody['id'] ?? $responseBody["data"]['id'] ?? $responseBody['ID'] ?? $responseBody["data"]['ID'] ?? $responseBody['Id'] ?? $responseBody["data"]['Id'] ?? null;
                $oldId = $item['id'] ?? $item["data"]['id'] ?? $item['ID'] ?? $item["data"]['ID'] ?? $item['Id'] ?? $item["data"]['Id'] ?? null;

                error_log("[MigrationModel] ID extraction - Old ID: $oldId, New ID: $newId");

                if ($oldId && $newId) {
                    if (!isset($this->migrationMap[$targetType])) {
                        $this->migrationMap[$targetType] = [];
                    }
                    if (!isset($this->migrationMap[$targetType]['ids'])) {
                        $this->migrationMap[$targetType]['ids'] = [];
                    }
                    $this->migrationMap[$targetType]['ids'][$oldId] = $newId;
                    error_log("[MigrationModel] Added to ID map: $targetType.ids[$oldId] = $newId");
                } else {
                    error_log("[MigrationModel] Warning: Could not extract old/new IDs for mapping");
                }

                // Store URL mapping if both old and new URLs exist
                $oldUrl = $item['source_url'] ?? $item['URL'] ?? $item['url'] ?? '';
                $newPath = $responseBody['data']['attributes']['path'] ?? $responseBody['attributes']['path'] ?? $responseBody['path'] ?? '';
                $newThumbPath = $responseBody['data']['attributes']['thumb_path'] ?? $responseBody['attributes']['thumb_path'] ?? $responseBody['thumb_path'] ?? '';

                error_log("[MigrationModel] URL mapping check - oldUrl: '$oldUrl', newPath: '$newPath', newThumbPath: '$newThumbPath'");
                // error_log("[MigrationModel] Full response structure: " . json_encode($responseBody, JSON_PRETTY_PRINT));
                
                if ($oldUrl && ($newPath || $newThumbPath)) {
                    if (!isset($this->migrationMap[$targetType])) { 
                        $this->migrationMap[$targetType] = [];
                    }
                    if (!isset($this->migrationMap[$targetType]['urls'])) {
                        $this->migrationMap[$targetType]['urls'] = [];
                    }
                    
                    // Use the actual accessible URL (thumb_path) if available, otherwise use path
                    $newUrl = $newThumbPath ?: $newPath;
                    $this->migrationMap[$targetType]['urls'][$oldUrl] = $newUrl;
                    error_log("[MigrationModel] ✓ Added to URL map: $targetType.urls['$oldUrl'] = '$newUrl'");
                } else {
                    error_log("[MigrationModel] ✗ No URL mapping stored - missing oldUrl or newUrl");
                }
            } else {
                error_log("[MigrationModel] Migration failed for item #" . ($idx + 1) . " - HTTP {$response->code}");
                error_log("[MigrationModel] Error response body: " . $response->body);
                throw new \RuntimeException('Error migrating item #' . ($idx + 1) . ' - HTTP ' . $response->code . ': ' . $response->body);
            }
        }
        error_log("[MigrationModel] Migration completed. Final migration map: " . json_encode($this->migrationMap, JSON_PRETTY_PRINT));
        error_log("[MigrationModel] Saving migration map to file: " . $this->migrationMapFile);
        $saveResult = file_put_contents($this->migrationMapFile, json_encode($this->migrationMap, JSON_PRETTY_PRINT));
        if ($saveResult !== false) {
            error_log("[MigrationModel] ✓ Migration map saved successfully. Bytes written: $saveResult");
        } else {
            error_log("[MigrationModel] ✗ Failed to save migration map to file");
        }
        return true;
    }
}