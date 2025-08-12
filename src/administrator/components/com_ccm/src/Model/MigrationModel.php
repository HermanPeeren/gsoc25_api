<?php
namespace Reem\Component\CCM\Administrator\Model;

use Joomla\CMS\MVC\Model\FormModel;
use Joomla\CMS\Http\HttpFactory;
use \Joomla\CMS\Http\Http;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\User\UserHelper;
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
    // 'menus'      => ['ids' => [oldId => [newId, menuType], ...]]
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

        $endpointConfig = null;
        if (isset($schema['ContentItem']) && is_array($schema['ContentItem'])) {
            foreach ($schema['ContentItem'] as $contentItem) {
                if (isset($contentItem['type']) && $contentItem['type'] === $sourceType) {
                    $endpointConfig = $contentItem['config'] ?? ['endpoint' => $sourceType];
                    break;
                }
            }
        }

        if (!$endpointConfig) {
            throw new \RuntimeException("No endpoint configuration found for source type: $sourceType");
        }

        $endpoint = $endpointConfig['endpoint'];
        $dependsOn = $endpointConfig['depends_on'] ?? null;

        // If the endpoint depends on another migrated type (e.g., menu items depending on menus)
        if ($dependsOn && isset($dependsOn['type']) && isset($dependsOn['param'])) {
            if (file_exists($this->migrationMapFile)) {
                $this->migrationMap = json_decode(file_get_contents($this->migrationMapFile), true) ?: [];
            }

            $dependencyType = $dependsOn['type'];
            $dependencyParam = $dependsOn['param'];
            $dependencyIds = $this->migrationMap[$dependencyType]['ids'] ?? [];

            if (empty($dependencyIds)) {
                throw new \RuntimeException("No migrated items of type '{$dependencyType}' found, which is a dependency for '{$sourceType}'.");
            }

            $allItems = [];
            foreach ($dependencyIds as $oldId => $newId) {
                $dependencyEndpoint = str_replace(':' . $dependencyParam, (string) $oldId, $endpoint);
                $sourceEndpoint = $sourceUrl . '/' . $dependencyEndpoint;
                error_log("[MigrationModel] Fetching source items from: $sourceEndpoint");

                $headers = ['Accept' => 'application/json'];
                if ($sourceAuthentication) {
                    $authHeaders = MigrationHelper::parseAuthentication($sourceAuthentication);
                    $headers = array_merge($headers, $authHeaders);
                }

                $sourceResponse = $this->http->get($sourceEndpoint, $headers);
                $sourceResponseBody = json_decode($sourceResponse->body, true);
                error_log("[MigrationModel] Menu Items - Source response body: " . $sourceResponse->body);

                $items = $sourceResponseBody['items'] ?? ($sourceResponseBody[$sourceType] ?? $sourceResponseBody);

                if (is_array($items)) {
                    foreach ($items as &$item) {
                        // Inject the original dependency ID for later mapping
                        $item[$dependencyParam] = $oldId;
                    }
                    $allItems = array_merge($allItems, $items);
                }
            }
            return $allItems;
        }

        // Default flow for endpoints without dependencies
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
            // Preserve injected dependency parameter (e.g., menu_id)
            if (isset($item['menu_id'])) {
                $ccmItem['menu_id'] = $item['menu_id'];
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
        $targetToCcm = [];
        $config = [];
        if (isset($ccmToTarget['ContentItem']) && is_array($ccmToTarget['ContentItem'])) {
            foreach ($ccmToTarget['ContentItem'] as $contentItem) {
                if (isset($contentItem['type']) && $contentItem['type'] === $targetType && isset($contentItem['properties'])) {
                    $targetToCcm = $contentItem['properties'];
                    $config = $contentItem['config'] ?? [];
                    break;
                }
            }
        }

        if (empty($targetToCcm)) {
            throw new \RuntimeException('No mapping found for target CMS type: ' . $targetType);
        }

        if (file_exists($this->migrationMapFile)) {
            $this->migrationMap = json_decode(file_get_contents($this->migrationMapFile), true) ?: [];
            // error_log("[MigrationModel] Loaded migration map from file: " . json_encode($this->migrationMap, JSON_PRETTY_PRINT));
        } else {
            // error_log("[MigrationModel] Migration map file does not exist: " . $this->migrationMapFile);
            throw new \RuntimeException('Migration map file does not exist: ' . $this->migrationMapFile);
        }

        $targetItems = [];
        foreach ($ccmItems as $ccmItem) {
            $targetItem = [];
            foreach ($targetToCcm as $targetKey => $ccmMap) {
                if (is_array($ccmMap)) {
                    $ccmKey     = $ccmMap['ccm'] ?? null;
                    $type       = $ccmMap['type'] ?? null;
                    $format     = $ccmMap['format'] ?? null;
                    $entityType = $ccmMap['entityType'] ?? null;
                    $value      = null;

                    if ($ccmKey && isset($ccmItem[$ccmKey])) {
                        $value = $ccmItem[$ccmKey];

                        if ($format === 'array' && ($type === 'string' || $type === 'integer') && (is_array($value) || is_object($value))) {
                            $arr = is_object($value) ? (array)$value : $value;
                            $allIds = [];

                            foreach ($arr as $item) {
                                if (is_array($item) && (isset($item['id']) || isset($item['ID']))) {
                                    $id = $item['id'] ?? $item['ID'];
                                    $allIds[] = $id;
                                }
                            }

                            if (!empty($allIds)) {
                                $value = $allIds;
                            } else {
                                $first = reset($arr);
                                if (is_array($first)) {
                                    $value = reset($first);
                                } else {
                                    $value = $first;
                                }
                            }
                        } elseif (($type === 'string' || $type === 'integer') && (is_array($value) || is_object($value))) {
                            $arr = is_object($value) ? (array)$value : $value;
                            $first = reset($arr);
                            if (is_array($first) && (isset($first['id']) || isset($first['ID']))) {
                                $value = $first['id'] ?? $first['ID'];
                            } elseif (is_array($first)) {
                                $value = reset($first);
                            }
                        }

                        // Handle value mapping (skip for array values as they need special handling)
                        if (isset($ccmMap['map']) && is_array($ccmMap['map']) && !is_array($value)) {
                            $value = $ccmMap['map'][$value] ?? ($ccmMap['default'] ?? $value);
                        }

                        // Handle date formatting
                        if (isset($ccmMap['format_date']) && !empty($value)) {
                            $format = $ccmMap['format_date'];
                            $value = MigrationHelper::formatDate($value, $format);
                        }
                    }

                    if (empty($value) && isset($ccmMap['default'])) {
                        $value = $ccmMap['default'];
                    }

                    if (empty($value) && $format) {
                        switch ($format) {
                            case 'password':
                                error_log("[MigrationModel] Generating password for the user: " . $ccmItem['username']);
                                $value = UserHelper::genRandomPassword(16);
                                error_log("[MigrationModel] Generated password: " . $value);
                                break;

                            case 'alias':
                                error_log("[MigrationModel] Formatting alias for the title: " . $ccmItem['title']);
                                $value = OutputFilter::stringURLSafe($ccmItem['title']);
                                break;

                            case 'name_map':
                                // Map using injected dependency (e.g., 'menu') to find correct menutype
                                error_log("[MigrationModel] Injected ccmItem2: " . json_encode($ccmItem));
                                $oldMenuId = $ccmItem['menu_id'] ?? null;
                                $value = null;
                                if ($oldMenuId !== null && isset($this->migrationMap['menus']['ids'][$oldMenuId])) {
                                    $mapping = $this->migrationMap['menus']['ids'][$oldMenuId];
                                    if (is_array($mapping) && isset($mapping[1])) {
                                        $value = $mapping[1];
                                        error_log("[MigrationModel] Mapped menutype for menu_id $oldMenuId: $value");
                                    }
                                } else {
                                    error_log("[MigrationModel] No menutype mapping found for menu_id $oldMenuId");
                                }
                                break;
                        }
                    }

                    // Format handling (array, url_replace, id_map)
                    if (!empty($value) && $format) {
                        switch ($format) {
                            case 'link_builder':
                                // If this is a custom link menu-item, use the original URL instead of the template
                                if (isset($ccmItem['type']) && $ccmItem['type'] === 'custom') {
                                    $value = $ccmItem['url'] ?? null;
                                    error_log("[MigrationModel] Using custom link URL: " . $value);
                                    break;
                                }
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
                                            $replaceValue = $this->migrationMap[$mapType]['ids'][$sourceId] ?? '';
                                            error_log("[MigrationModel] Mapping ID for $mapType: $sourceId -> $replaceValue");
                                        }
                                    } elseif ($paramSource['source'] === 'map') {
                                        $sourceValue = $ccmItem[$paramSource['ccm_key']] ?? null;
                                        if ($sourceValue && isset($paramSource['map'][$sourceValue])) {
                                            $replaceValue = $paramSource['map'][$sourceValue];
                                        }
                                    }
                                    $builtLink = str_replace(':' . $paramKey, $replaceValue, $builtLink);
                                }
                                $value = $builtLink;
                                error_log("[MigrationModel] Built link for key '$targetKey': " . json_encode($value));
                                break;
                            case 'object_builder':
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
                                            $mappedId = $this->migrationMap[$mapType]['ids'][$sourceId] ?? null;
                                            if ($mappedId) {
                                                $requestObject[$paramKey] = $mappedId;
                                                error_log("[MigrationModel] Mapped ID for request object: $sourceId -> $mappedId ($mapType)");
                                            }
                                        }
                                    }
                                }
                                $value = $requestObject;
                                error_log("[MigrationModel] Built object for key '$targetKey': " . json_encode($value));
                                break;
                            case 'array':
                                if (is_array($value)) {
                                    // Check if we have a role mapping configuration
                                    error_log("ccmMap: " . json_encode($ccmMap));
                                    if (isset($ccmMap['map']) && is_array($ccmMap['map'])) {
                                        // Handle role mapping directly in the model
                                        error_log("[MigrationModel] Role mapping configuration found for array value: " . json_encode($value));
                                        $mappedValues = [];
                                        foreach ($value as $arrayValue) {
                                            // Map roles using the provided mapping
                                            $mappedValue = $ccmMap['map'][$arrayValue] ?? ($ccmMap['default'] ?? $arrayValue);
                                            error_log("[MigrationModel] Role mapping: $arrayValue -> $mappedValue");
                                            $mappedValues[] = $mappedValue;
                                        }
                                        $value = $mappedValues;
                                    } else {
                                        // Handle numeric ID mapping (existing functionality)
                                        error_log("[MigrationModel] Numeric ID mapping for array value: " . json_encode($value));
                                        $mappedValues = [];
                                        foreach ($value as $arrayValue) {
                                            if (is_numeric($arrayValue) || (is_string($arrayValue) && ctype_digit(trim($arrayValue)))) {
                                                $numericValue = intval($arrayValue);
                                                $mappedValue = MigrationHelper::mapEntityId($numericValue, $this->migrationMap, $entityType);
                                                $mappedValues[] = $mappedValue;
                                            }
                                        }
                                        $value = $mappedValues;
                                    }
                                } elseif (is_numeric($value) || (is_string($value) && ctype_digit(trim($value)))) {
                                    $mappedValue = MigrationHelper::mapEntityId(intval($value), $this->migrationMap, $entityType);
                                    $value = [$mappedValue];
                                } elseif (is_string($value) && strpos($value, ',') !== false) {
                                    $ids = array_map('trim', explode(',', $value));
                                    $mappedValues = [];
                                    foreach ($ids as $id) {
                                        if (is_numeric($id) || ctype_digit($id)) {
                                            $mappedValue = MigrationHelper::mapEntityId(intval($id), $this->migrationMap, $entityType);
                                            $mappedValues[] = $mappedValue;
                                        }
                                    }
                                    $value = $mappedValues;
                                } else {
                                    $value = [];
                                }

                                if (empty($value)) {
                                    continue 2; // Skip adding this field
                                }
                                break;

                            case 'url_replace':
                                if (is_string($value)) {
                                    foreach ($this->migrationMap as $entityType => $mappings) {
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
                                }
                                break;

                            case 'id_map':
                                if (!empty($value) && ($type === 'string' || $type === 'integer')) {
                                    // Handle object with ID extraction (like author object)
                                    if (is_array($value) && isset($value['ID'])) {
                                        $value = $value['ID'];
                                        error_log("[MigrationModel] Extracted ID from object for id_map: " . $value);
                                    } elseif (is_object($value) && isset($value->ID)) {
                                        $value = $value->ID;
                                        error_log("[MigrationModel] Extracted ID from object for id_map: " . $value);
                                    }
                                    $value = MigrationHelper::mapEntityId($value, $this->migrationMap, $entityType);
                                }
                                break;
                        }
                    } else {
                        if (($type === 'string' || $type === 'integer') && !empty($value)) {
                            $value = MigrationHelper::mapEntityId($value, $this->migrationMap, $entityType);
                        }
                    }

                    $targetItem[$targetKey] = $value;
                } else {
                    if ($ccmMap && isset($ccmItem[$ccmMap])) {
                        $targetItem[$targetKey] = $ccmItem[$ccmMap];
                    }
                }
            }
            $targetItems[] = $targetItem;
        }

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
                    
                    // For menus, store both newId and menutype
                    if ($targetType === 'menus') {
                        error_log("[MigrationModel] Storing menu item with menutype for item: " . json_encode($item));
                        $menutype = $item['menutype'] ?? $item['alias'] ?? $item['slug'] ?? '';
                        $this->migrationMap[$targetType]['ids'][$oldId] = [$newId, $menutype];
                    } else {
                        $this->migrationMap[$targetType]['ids'][$oldId] = $newId;
                    }
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