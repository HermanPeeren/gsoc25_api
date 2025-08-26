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

        $sourceItems = $this->getSourceItems($sourceCms, $sourceType);

        $sourceToCcmItems = $this->convertSourceCmsToCcm($sourceCms, $sourceItems, $sourceType);
        
        $result           = $this->convertCcmToTargetCms($sourceToCcmItems, $targetCms, $targetType);
        $config           = $result['config'];
        $ccmToTargetItems = $result['items'];

        $targetMigrationStatus = $this->migrateItemsToTargetCms($targetCms, $targetType, $ccmToTargetItems, $config, $sourceCms);

        return $targetMigrationStatus;
    }

    private function getSourceItems($sourceCms, $sourceType) {
        $sourceUrl = $sourceCms->url;
        $sourceCredentials = $sourceCms->credentials;

        // Load source schema to get endpoint info
        $sourceSchemaFile = strtolower($sourceCms->name) . '-ccm.json';        
        $schemaPath = dirname(__DIR__, 1) . '/Schema/';
        $schema = json_decode(file_get_contents($schemaPath . $sourceSchemaFile), true);

        // get the endpoint last elements e.g. content/articles
        $endpointConfig = null;
        if (isset($schema['ContentItem']) && is_array($schema['ContentItem'])) {
            foreach ($schema['ContentItem'] as $contentItem) {
                if (isset($contentItem['type']) && $contentItem['type'] === $sourceType) {
                    // If the endpoint is not set explicitly in the json file, then take the "type" field as the endpoint
                    $endpointConfig = $contentItem['config'] ?? ['endpoint' => $sourceType];
                    break;
                }
            }
        }

        if (!$endpointConfig) {
            throw new \RuntimeException("No endpoint configuration found for source type: $sourceType");
        }

        $endpoint  = $endpointConfig['endpoint'];
        $dependsOn = $endpointConfig['depends_on'] ?? null;

        // If the endpoint depends on another migrated type (e.g., menu items depending on menus)
        if ($dependsOn && isset($dependsOn['type']) && isset($dependsOn['param'])) {
            if (file_exists($this->migrationMapFile)) {
                $this->migrationMap = json_decode(file_get_contents($this->migrationMapFile), true) ?: [];
            }

            $dependencyType  = $dependsOn['type'];
            $dependencyParam = $dependsOn['param'];
            $dependencyIds   = $this->migrationMap[$dependencyType]['ids'] ?? [];

            if (empty($dependencyIds)) {
                throw new \RuntimeException("No migrated items of type '{$dependencyType}' found, which is a dependency for '{$sourceType}'.");
            }

            $allItems = [];
            foreach ($dependencyIds as $oldId => $newId) {
                $dependencyEndpoint = str_replace(':' . $dependencyParam, (string) $oldId, $endpoint);
                $sourceEndpoint     = $sourceUrl . '/' . $dependencyEndpoint;

                $headers = ['Accept' => 'application/json'];
                if ($sourceCredentials) {
                    $authHeaders = MigrationHelper::parseAuthentication($sourceCredentials);
                    $headers     = array_merge($headers, $authHeaders);
                }

                $sourceResponse     = $this->http->get($sourceEndpoint, $headers);
                $sourceResponseBody = json_decode($sourceResponse->body, true);

                $items = $sourceResponseBody['items'] ?? ($sourceResponseBody[$sourceType] ?? $sourceResponseBody);

                if (is_array($items)) {
                    foreach ($items as &$item) {
                        // Inject the original dependency ID for later mapping
                        // e.g. for menu items, we need to keep track of the original menu ID, so we add it to the item
                        // i.e. $item['original_menu_id'] = menu_source_id;
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

        if ($sourceCredentials) {
            $authHeaders = MigrationHelper::parseAuthentication($sourceCredentials);
            $headers = array_merge($headers, $authHeaders);
        }

        $sourceResponse     = $this->http->get($sourceEndpoint, $headers);
        $sourceResponseBody = json_decode($sourceResponse->body, true);

        // Considering different ways for the GET API response
        if (is_array($sourceResponseBody) && isset($sourceResponseBody[$sourceType]) && is_array($sourceResponseBody[$sourceType])) {
            return $sourceResponseBody[$sourceType];
        } elseif (is_array($sourceResponseBody) && isset($sourceResponseBody['items']) && is_array($sourceResponseBody['items'])) {
            return $sourceResponseBody['items'];
        } elseif (is_array($sourceResponseBody)) {
            return $sourceResponseBody;
        }

        throw new \RuntimeException('Could not find items to migrate in source response.');
    }

    private function convertSourceCmsToCcm($sourceCms, $sourceItems, $sourceType) {
        $sourceSchemaFile = strtolower($sourceCms->name) . '-ccm.json';        
        $schemaPath       = dirname(__DIR__, 1) . '/Schema/';
        $schema           = json_decode(file_get_contents($schemaPath . $sourceSchemaFile), true);

        // Find the ContentItem with the matching type
        $sourceToCcm = [];
        if (isset($schema['ContentItem']) && is_array($schema['ContentItem'])) {
            foreach ($schema['ContentItem'] as $contentItem) {
                if (isset($contentItem['type']) && $contentItem['type'] === $sourceType && isset($contentItem['properties'])) {
                    $sourceToCcm = $contentItem['properties'];
                    break;
                }
            }
        }

        $ccmItems = [];
        foreach ($sourceItems as $item) {
            $ccmItem = [];

            foreach ($sourceToCcm as $sourceKey => $ccmMap) {
                if (is_array($ccmMap)) {
                    // New style mapping with ccm + type + nested
                    $ccmKey = $ccmMap['ccm'] ?? null;
                    $nested = $ccmMap['nested'] ?? null;

                    if ($ccmKey && isset($item[$sourceKey])) {
                        $value = $item[$sourceKey];

                        // Handle one level nested key
                        if ($nested && is_array($value) && isset($value[$nested])) {
                            $value = $value[$nested];
                        }

                        $ccmItem[$ccmKey] = $value;
                    }
                } else {
                    // Old style mapping: "title": "title"
                    if ($ccmMap && isset($item[$sourceKey])) {
                        $ccmItem[$ccmMap] = $item[$sourceKey];
                    }
                }
            }

            if ($sourceType === 'users')
            {
                // only if schema didn’t already give us one
                if (empty($ccmItem['email']))
                {
                    $base     = $ccmItem['username'] ?? $ccmItem['name'] ?? 'user';
                    $domain   = 'nonexistent.com';
                    $ccmItem['email'] = $base . '@' . $domain;
                    error_log("[MigrationModel] Injected dummy email: " . $ccmItem['email']);
                }
            }

            // Keep special case for menu_id
            if (isset($item['menu_id'])) {
                $ccmItem['menu_id'] = $item['menu_id'];
            }
            $ccmItems[] = $ccmItem;
        }

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
        } else {
            throw new \RuntimeException('Migration map file does not exist: ' . $this->migrationMapFile);
        }

        $targetItems = [];
        $customFields = [];

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

                        // Handle custom fields
                        if ($format === 'custom_fields')
                        if ($format === 'custom_fields' && is_array($value)) {
                            foreach ($value as $fieldName => $fieldValues) {
                                if (strpos($fieldName, '_') === 0) {
                                    continue;
                                }

                                if (!is_string($fieldName)) {
                                    throw new \RuntimeException("Invalid custom field name: " . print_r($fieldName, true));
                                }

                                $targetItem[$fieldName] = is_array($fieldValues) ? reset($fieldValues) : $fieldValues;
                                $targetItem["custom_fields"][$fieldName] = $targetItem[$fieldName];
                            }
                            continue;
                        }

                        if (($type === 'string' || $type === 'integer') && (is_array($value) || is_object($value))) {
                            $arr = is_object($value) ? (array)$value : $value;

                            if ($format === 'array') {
                                // Source array → array in target
                                // e.g. tags in wordpress: tags:{"tag_name":{..tag_data}} is converted to tags:[id1, id2, ..]
                                $allIds = [];
                                foreach ($arr as $item) {
                                    if (is_array($item) && (isset($item['id']) || isset($item['ID']))) {
                                        $allIds[] = $item['id'] ?? $item['ID'];
                                    }
                                }
                                $value = !empty($allIds) ? $allIds : reset($arr);
                            } else {
                                // Source array → single value in target
                                // e.g. categories in wordpress: categories:{"category_name":{..category_data}} is converted to catid as one category id in joomla
                                $first = reset($arr);
                                if (is_array($first) && (isset($first['id']) || isset($first['ID']))) {
                                    $value = $first['id'] ?? $first['ID'];
                                } elseif (is_array($first)) {
                                    $value = reset($first);
                                }
                            }
                        }

                        // Handle value mapping
                        // e.g. status is publish(string) in wordpress while it is 1(int) in joomla
                        if (isset($ccmMap['map']) && is_array($ccmMap['map']) && !is_array($value)) {
                            $value = $ccmMap['map'][$value] ?? ($ccmMap['default'] ?? $value);
                        }

                        // Handle date formatting
                        if (isset($ccmMap['format_date']) && !empty($value)) {
                            $format = $ccmMap['format_date'];
                            $value = MigrationHelper::formatDate($value, $format);
                        }
                    }

                    // add the default value(if exists) for empty values
                    // e.g. catid can't be empty in joomla.
                    // For migrating pages from wordpress to joomla, we need to add default category bec, pages are not categorized in wordpress
                    if (empty($value) && isset($ccmMap['default'])) {
                        $value = $ccmMap['default'];
                    }

                    if (empty($value) && $format) {
                        switch ($format) {
                            case 'password':
                                $value = UserHelper::genRandomPassword(16);
                                break;

                            case 'alias':
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
                                    }
                                } else {
                                    throw new Exception("No menutype mapping found for menu_id $oldMenuId");
                                }
                                break;
                        }
                    }

                    if (!empty($value) && $format) {
                        switch ($format) {
                            case 'link_builder':
                                // If this is a custom link menu-item, use the original URL instead of the template
                                if (isset($ccmItem['type']) && $ccmItem['type'] === 'custom') {
                                    $value = $ccmItem['url'] ?? null;
                                    break;
                                }
                                $value = MigrationHelper::buildLink($ccmItem, $ccmMap, $this->migrationMap);
                                break;
                            case 'object_builder':
                                $value = MigrationHelper::buildObject($ccmItem, $ccmMap, $this->migrationMap);
                                break;
                            case 'array':
                                if (is_array($value)) {
                                    // Check if we have a role mapping configuration
                                    if (isset($ccmMap['map']) && is_array($ccmMap['map'])) {
                                        // Handle role mapping directly in the model
                                        $mappedValues = [];
                                        foreach ($value as $arrayValue) {
                                            // Map roles using the provided mapping
                                            // e.g. map the "editor" role in wordpress to the "editor" role id in joomla
                                            $mappedValue = $ccmMap['map'][$arrayValue] ?? ($ccmMap['default'] ?? $arrayValue);
                                            $mappedValues[] = $mappedValue;
                                        }
                                        $value = $mappedValues;
                                    } else {
                                        // Handle numeric ID mapping
                                        // e.g. map the "123" item ID in wordpress to the "456" item id in joomla
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
                                $value = MigrationHelper::replaceUrls($value, $this->migrationMap);
                                break;
                            case 'id_map':
                                if (!empty($value) && ($type === 'string' || $type === 'integer')) {
                                    // Handle array with ID extraction (like author array, it is just one author)
                                    if (is_array($value) && isset($value['ID'])) {
                                        $value = $value['ID'];
                                    } elseif (is_object($value) && isset($value->ID)) {
                                        // Handle list of arrays (like categories)
                                        $value = $value->ID;
                                    }
                                    $value = MigrationHelper::mapEntityId($value, $this->migrationMap, $entityType);
                                }
                                break;
                        }
                    }

                    $targetItem[$targetKey] = $value;
                } else {
                    if ($ccmMap && isset($ccmItem[$ccmMap])) {
                        $targetItem[$targetKey] = $ccmItem[$ccmMap];
                    }
                }
            }
            // Add custom fields to the target item
            foreach ($customFields as $fieldName => $fieldValue) {
                if (!is_string($fieldName)) {
                    throw new \RuntimeException("Invalid custom field name: " . print_r($fieldName, true));
                }
                $targetItem[$fieldName] = is_array($fieldValue) ? reset($fieldValue) : $fieldValue;
            }

            $targetItems[] = $targetItem;
        }

        return [
            'items' => $targetItems,
            'config' => $config
        ];
    }

    private function migrateItemsToTargetCms($targetCms, $targetType, $ccmToTargetItems, $config = [], $sourceCms = null) {
        $targetCredentials = $targetCms->credentials;
        $endpoint          = $config['endpoint'] ?? $targetType;
        $targetUrl         = $targetCms->url;
        $targetEndpoint    = $targetUrl . '/' . $endpoint;

        // Collect all unique custom fields from the items
        $allCustomFields = [];
        foreach ($ccmToTargetItems as $item) {
            // error_log("Processing item: " . print_r($item, true));
            if (!empty($item['custom_fields']) && is_array($item['custom_fields'])) {
                foreach ($item['custom_fields'] as $fieldName => $fieldValue) {
                    if ($fieldName[0] !== '_' && !isset($allCustomFields[$fieldName])) {
                        $allCustomFields[$fieldName] = $fieldValue;
                    }
                }
            }
        }

        // error_log("Collected Custom Fields: " . print_r($allCustomFields, true));
        // error_log("Custom Fields in migrateItemsToTargetCms: " . print_r($allCustomFields, true));
        if (!empty($allCustomFields)) {
            MigrationHelper::createCustomFields($targetUrl, $endpoint, $targetType, $allCustomFields, $targetCms);
        }

        // Create migration folder name once for this entire migration batch
        if ($targetType === 'media') {
            $sourceCmsName = $sourceCms ? strtolower($sourceCms->name) : 'unknown';
            $migrationFolderName_ForMedia = null;
            if ($targetType === 'media') {
                $dateTimeFolder = date('Y_m_d_H_i_s'); // e.g., "2025_07_19_14_30_45"
                $migrationFolderName_ForMedia = "migration/{$sourceCmsName}/{$dateTimeFolder}";
            }
        }

        foreach ($ccmToTargetItems as $idx => $item) {
            $headers = [
                'Accept' => 'application/vnd.api+json',
                'Content-Type' => 'application/json'
            ];

            if ($targetCredentials) {
                $authHeaders = MigrationHelper::parseAuthentication($targetCredentials);
                $headers = array_merge($headers, $authHeaders);
            }

            if ($targetType === 'media') {
                $sourceUrl = $item['source_url'] ?? $item['URL'] ?? $item['url'] ?? '';
                if (!MigrationHelper::isSupportedFileType($sourceUrl)) {
                    continue;
                }

                $uploadData = MigrationHelper::handleMediaUpload($item, $sourceCmsName, $migrationFolderName_ForMedia);

                $response = $this->http->post($targetEndpoint, json_encode($uploadData), $headers);
            } else {
                $requestBody = json_encode($item);

                $response = $this->http->post($targetEndpoint, $requestBody, $headers);
            }

            if ($response->code === 201 || $response->code === 200) {
                $responseBody = json_decode($response->body, true);

                $newId = $responseBody['id'] ?? $responseBody["data"]['id'] ?? $responseBody['ID'] ?? $responseBody["data"]['ID'] ?? $responseBody['Id'] ?? $responseBody["data"]['Id'] ?? null;
                $oldId = $item['id'] ?? $item["data"]['id'] ?? $item['ID'] ?? $item["data"]['ID'] ?? $item['Id'] ?? $item["data"]['Id'] ?? null;

                if ($oldId && $newId) {
                    if (!isset($this->migrationMap[$targetType])) {
                        $this->migrationMap[$targetType] = [];
                    }
                    if (!isset($this->migrationMap[$targetType]['ids'])) {
                        $this->migrationMap[$targetType]['ids'] = [];
                    }
                    
                    // For menus, store both newId and menutype
                    if ($targetType === 'menus') {
                        $menutype = $item['menutype'] ?? $item['alias'] ?? $item['slug'] ?? '';

                        $this->migrationMap[$targetType]['ids'][$oldId] = [$newId, $menutype];
                    } else {
                        $this->migrationMap[$targetType]['ids'][$oldId] = $newId;
                    }
                }

                // Store URL mapping if both old and new URLs exist
                $oldUrl       = $item['source_url'] ?? $item['URL'] ?? $item['url'] ?? '';
                $newPath      = $responseBody['data']['attributes']['path'] ?? $responseBody['attributes']['path'] ?? $responseBody['path'] ?? '';
                $newThumbPath = $responseBody['data']['attributes']['thumb_path'] ?? $responseBody['attributes']['thumb_path'] ?? $responseBody['thumb_path'] ?? '';

                if ($oldUrl && ($newPath || $newThumbPath)) {
                    if (!isset($this->migrationMap[$targetType])) { 
                        $this->migrationMap[$targetType] = [];
                    }
                    if (!isset($this->migrationMap[$targetType]['urls'])) {
                        $this->migrationMap[$targetType]['urls'] = [];
                    }
                    
                    $newUrl = $newThumbPath ?: $newPath;
                    $this->migrationMap[$targetType]['urls'][$oldUrl] = $newUrl;
                }
            } else {
                throw new \RuntimeException('Error migrating item #' . ($idx + 1) . ' - HTTP ' . $response->code . ': ' . $response->body);
            }
        }
        $saveResult = file_put_contents($this->migrationMapFile, json_encode($this->migrationMap, JSON_PRETTY_PRINT));
        if ($saveResult === false) {
            throw new \RuntimeException('✗ Failed to save migration map to file');
        }
        error_log('migration map: ' . print_r($this->migrationMap, true));
        return true;
    }
}