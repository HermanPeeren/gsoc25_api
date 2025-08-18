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
        $sourceAuthentication = $sourceCms->authentication;

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
                if ($sourceAuthentication) {
                    $authHeaders = MigrationHelper::parseAuthentication($sourceAuthentication);
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

        if ($sourceAuthentication) {
            $authHeaders = MigrationHelper::parseAuthentication($sourceAuthentication);
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
                            // Handle array in source -> array in target
                            // e.g. tags in wordpress: tags:{"tag_name":{..tag_data}} is converted to tags:[id1, id2, ..]
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
                            // Handle array in source -> one value in target
                            // e.g. categories in wordpress: categories:{"category_name":{..category_data}} is converted to catid as one category id in joomla
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
                                            }
                                        }
                                    }
                                }
                                $value = $requestObject;
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
                                if (is_string($value)) {
                                    // Handle the links within the text
                                    // e.g. <a href="old-url">Link</a> or <img src="old-image.jpg" />
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
            $targetItems[] = $targetItem;
        }

        return [
            'items' => $targetItems,
            'config' => $config
        ];
    }

    private function migrateItemsToTargetCms($targetCms, $targetType, $ccmToTargetItems, $config = [], $sourceCms = null) {
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
            }
        }

        foreach ($ccmToTargetItems as $idx => $item) {
            $headers = [
                'Accept' => 'application/vnd.api+json',
                'Content-Type' => 'application/json'
            ];

            if ($targetAuthentication) {
                $authHeaders = MigrationHelper::parseAuthentication($targetAuthentication);
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
                } else {
                    throw new \RuntimeException('Error extracting old/new IDs for mapping');
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
                } else {
                    throw new \RuntimeException('Error extracting old/new URLs for mapping');
                }
            } else {
                throw new \RuntimeException('Error migrating item #' . ($idx + 1) . ' - HTTP ' . $response->code . ': ' . $response->body);
            }
        }
        $saveResult = file_put_contents($this->migrationMapFile, json_encode($this->migrationMap, JSON_PRETTY_PRINT));
        if ($saveResult === false) {
            throw new \RuntimeException('✗ Failed to save migration map to file');
        }
        return true;
    }
}