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
    private $migrationIdMap = [
    // 'categories' => [oldId => newId, ...]
    // 'users'      => [oldId => newId, ...]
    // 'media'      => [oldId => newId, ...]
    ];
    protected $migrationIdMapFile;
    protected $http;

    public function __construct($config = [], $http = null)
    {
        parent::__construct($config);
        $this->migrationIdMapFile = dirname(__DIR__, 1) . '/Schema/migrationIdMap.json';

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
                            // error_log('$this->migrationIdMap: ' . json_encode($this->migrationIdMap));
                            if (file_exists($this->migrationIdMapFile)) {
                                $this->migrationIdMap = json_decode(file_get_contents($this->migrationIdMapFile), true) ?: [];
                            }
                            foreach ($this->migrationIdMap as $entityType => $idMap) {
                                // error_log("[MigrationModel] Checking ID map for entity type: $entityType");
                                if (isset($idMap[$value])) {
                                    // error_log("[MigrationModel] Mapping value '$value' for target key '$targetKey' in type '$targetType' using entity type '$entityType'.");
                                    $value = $idMap[$value];
                                    break;
                                }
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
        $sourceCmsName = $sourceCms ? strtolower($sourceCms->name) : 'unknown';
        $migrationFolderName_ForMedia = null;
        if ($targetType === 'media') {
            $dateTimeFolder = date('Y_m_d_H_i_s'); // e.g., "2025_07_19_14_30_45"
            $migrationFolderName = "migration/{$sourceCmsName}/{$dateTimeFolder}";
            error_log("[MigrationModel] Using migration folder: $migrationFolderName");
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
                    if (!isset($this->migrationIdMap[$targetType])) {
                        $this->migrationIdMap[$targetType] = [];
                    }
                    $this->migrationIdMap[$targetType][$oldId] = $newId;
                    error_log("[MigrationModel] Added to ID map: $targetType[$oldId] = $newId");
                } else {
                    error_log("[MigrationModel] Warning: Could not extract old/new IDs for mapping");
                }
            } else {
                error_log("[MigrationModel] Migration failed for item #" . ($idx + 1) . " - HTTP {$response->code}");
                error_log("[MigrationModel] Error response body: " . $response->body);
                throw new \RuntimeException('Error migrating item #' . ($idx + 1) . ' - HTTP ' . $response->code . ': ' . $response->body);
            }
        }
        error_log("[MigrationModel] Migration completed. Final ID map: " . json_encode($this->migrationIdMap));
        file_put_contents($this->migrationIdMapFile, json_encode($this->migrationIdMap));
        return true;
    }
}