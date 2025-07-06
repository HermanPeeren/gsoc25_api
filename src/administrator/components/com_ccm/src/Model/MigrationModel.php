<?php
namespace Reem\Component\CCM\Administrator\Model;

use Joomla\CMS\MVC\Model\FormModel;
use Joomla\CMS\Http\HttpFactory;
use \Joomla\CMS\Http\Http;
use Joomla\CMS\Factory;
use Reem\Component\CCM\Administrator\Helper\AuthenticationHelper;

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

        // if (!$sourceCms || !$targetCms) {
        //     throw new \RuntimeException('Invalid source or target CMS.');
        // }

        $sourceItems = $this->getSourceItems($sourceCms, $sourceType);
        // if (empty($sourceItems)) {
        //     throw new \RuntimeException('No items found in source CMS.');
        // }
        $sourceToCcmItems = $this->convertSourceCmsToCcm($sourceCms, $sourceItems, $sourceType);
        // if (empty($sourceToCcmItems)) {
        //     throw new \RuntimeException('No items found to migrate from source CMS.');
        // }
        $ccmToTargetItems = $this->convertCcmToTargetCms($sourceToCcmItems, $targetCms, $targetType);

        $targetMigrationStatus = $this->migrateItemsToTargetCms($targetCms, $targetType, $ccmToTargetItems);

        return $targetMigrationStatus;
    }

    private function getSourceItems($sourceCms, $sourceType) {
        $sourceUrl = $sourceCms->url;
        $sourceEndpoint = $sourceUrl . '/' . $sourceType;
        $sourceAuthentication = $sourceCms->authentication;

        $headers = [
            'Accept' => 'application/json',
        ];

        if ($sourceAuthentication) {
            $authHeaders = AuthenticationHelper::parseAuthentication($sourceAuthentication);
            $headers = array_merge($headers, $authHeaders);
            error_log("[MigrationModel] Using authentication headers: " . json_encode($authHeaders));
        }

        $sourceResponse = $this->http->get($sourceEndpoint, $headers);
        // error_log("[MigrationModel] Source response code: " . $sourceResponse->code);
        // error_log("[MigrationModel] Source response body: " . $sourceResponse->body);

        $sourceResponseBody = json_decode($sourceResponse->body, true);

        if (isset($sourceResponseBody[$sourceType]) && is_array($sourceResponseBody[$sourceType])) {
            // error_log("[MigrationModel] Found items under key: $sourceType");
            return $sourceResponseBody[$sourceType];
        } elseif (isset($sourceResponseBody['items']) && is_array($sourceResponseBody['items'])) {
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

    private function formatDate($date, $format) {
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

    private function convertCcmToTargetCms($ccmItems, $targetCms, $targetType) {
        $targetSchemaFile = strtolower($targetCms->name) . '-ccm.json';
        $schemaPath       = dirname(__DIR__, 1) . '/Schema/';
        $ccmToTarget      = json_decode(file_get_contents($schemaPath . $targetSchemaFile), true);

        // Find the ContentItem with the matching type
        $targetToCcm = [];
        if (isset($ccmToTarget['ContentItem']) && is_array($ccmToTarget['ContentItem'])) {
            foreach ($ccmToTarget['ContentItem'] as $contentItem) {
                if (isset($contentItem['type']) && $contentItem['type'] === $targetType && isset($contentItem['properties'])) {
                    $targetToCcm = $contentItem['properties'];
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
                            $value = $this->formatDate($value, $format);
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
        return $targetItems;
    }

    private function migrateItemsToTargetCms($targetCms, $targetType, $ccmToTargetItems) {
        $targetUrl         = $targetCms->url;
        $targetEndpoint    = $targetUrl . '/' . $targetType;
        $targetAuthentication = $targetCms->authentication;

        $headers = [
            'Accept' => 'application/vnd.api+json',
            'Content-Type' => 'application/json'
        ];

        if ($targetAuthentication) {
            $authHeaders = AuthenticationHelper::parseAuthentication($targetAuthentication);
            $headers = array_merge($headers, $authHeaders);
            error_log("[MigrationModel] Using authentication headers: " . json_encode($authHeaders));
        }

        foreach ($ccmToTargetItems as $idx => $item) {
            // error_log("[MigrationModel] Migrating item #" . ($idx + 1) . ": " . json_encode($item));
            $response = $this->http->post($targetEndpoint, json_encode($item), $headers);

            if ($response->code === 201 || $response->code === 200) {
                // error_log("[MigrationModel] Successfully migrated item #" . ($idx + 1));
                $responseBody = json_decode($response->body, true);
                $newId = $responseBody['id'] ?? $responseBody["data"]['id'] ?? $responseBody['ID'] ?? $responseBody["data"]['ID'] ?? $responseBody['Id'] ?? $responseBody["data"]['Id'] ?? null;
                $oldId = $item['id'] ?? $item["data"]['id'] ?? $item['ID'] ?? $item["data"]['ID'] ?? $item['Id'] ?? $item["data"]['Id'] ?? null;

                if ($oldId && $newId) {
                    if (!isset($this->migrationIdMap[$targetType])) {
                        $this->migrationIdMap[$targetType] = [];
                    }
                    $this->migrationIdMap[$targetType][$oldId] = $newId;
                }
                // error_log("[MigrationModel] Mapped item #$idx: oldId = $oldId, newId = $newId");
            }
            else
            throw new \RuntimeException('Error migrating item: ' . $response->body);
        }
        // error_log("[MigrationModel] Migration ID full map: " . json_encode($this->migrationIdMap));
        file_put_contents($this->migrationIdMapFile, json_encode($this->migrationIdMap));
        return true;
    }
}