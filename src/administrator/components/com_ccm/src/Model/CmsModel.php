<?php
namespace Reem\Component\CCM\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\MVC\Model\AdminModel;

\defined('_JEXEC') or die;

//cms type = wordpress, joomla, drupal, etc.
//their APIs
class CmsModel extends AdminModel
{

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_ccm.cms',
            'cms',
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
        $data = $app->getUserState('com_ccm.edit.cms.data', []);
        if (empty($data)) {
            $data = $this->getItem();
        }
        // Set the mapped key as the default value for your field
        if (isset($data->ccm_mapping)) {
            $mapping = json_decode($data->ccm_mapping, true);
            if (is_array($mapping)) {
                $data->ccm_mapping = $mapping;
            }
        }
        return $data;
    }

    public function discoverCmsProperties($url)
    {
        $response = HttpFactory::getHttp()->get($url, [
            'Accept' => 'application/json',
        ]);
        $body = json_decode($response->body, true);

        // the content is the array object in the response body
        $contentItem = null;
        foreach ($body as $value) {
            if (is_array($value) && !empty($value)) {
                $contentItem = $value[0];
                break;
            }
        }

        // Extract keys and their types from the content item
        $sourceKeysTypesWithTypes = [];
        if (is_array($contentItem)) {
            foreach ($contentItem as $key => $value) {
                if (is_string($value)) {
                    if (filter_var($value, FILTER_VALIDATE_URL)) {
                        $sourceKeysTypesWithTypes[$key] = 'url';
                    } elseif (preg_match('/<[^>]+>/', $value)) {
                        $sourceKeysTypesWithTypes[$key] = 'html';
                    } elseif (strtotime($value) !== false && preg_match('/\d{4}-\d{2}-\d{2}/', $value)) {
                        $sourceKeysTypesWithTypes[$key] = 'date';
                    } else {
                        $sourceKeysTypesWithTypes[$key] = 'string';
                    }
                } elseif (is_null($value)) {
                    $sourceKeysTypesWithTypes[$key] = 'string';
                } else {
                    $sourceKeysTypesWithTypes[$key] = gettype($value);
                }
            }
        }

        $id = (int) ($this->getState($this->getName() . '.id') ?: Factory::getApplication()->input->getInt('id'));
        // error_log("sourceKeysTypesWithTypes: " . print_r($sourceKeysTypesWithTypes, true));
        $data = [
            'id'           => $id,
            'content_keys_types' => json_encode($sourceKeysTypesWithTypes, JSON_UNESCAPED_UNICODE),
        ];
        $this->save($data);

        return $this->getItem($id);
    }

    public function mapCmsToCCM()
    {
        $ccmSchema  = json_decode(file_get_contents(__DIR__ . '/ccm.json'), true); // this need to be changed
        $ccmKeys    = array_keys($ccmSchema['ContentItem']['properties']); // ContentItem is list now  
        $mapping    = [];
        $id         = (int) ($this->getState($this->getName() . '.id') ?: Factory::getApplication()->input->getInt('id'));
        $item       = $this->getItem($id);
        $sourceKeysTypes = json_decode($item->content_keys_types, true);

        // First mapping: Perfect matches
        foreach ($ccmKeys as $ccmKey) {
            $bestMatch = null;
            // Build a lowercased lookup map of source keys to their original keys
            static $sourceKeyMap = null;
            if ($sourceKeyMap === null) {
                $sourceKeyMap = [];
                foreach ($sourceKeysTypes as $sourceKey => $sourceType) {
                    $sourceKeyMap[strtolower($sourceKey)] = $sourceKey;
                }
            }
            $lowerCcmKey = strtolower($ccmKey);
            if (isset($sourceKeyMap[$lowerCcmKey])) {
                $bestMatch = $sourceKeyMap[$lowerCcmKey];
            }

            if ($bestMatch !== null) {
                $mapping[$ccmKey] = $bestMatch;
                // error_log("Mapping: " . $ccmKey . " => " . $bestMatch);
            }
        }
        // error_log("Initial Mapping: " . print_r($mapping, true));

        // Second mapping: Map with type checking
        foreach ($ccmKeys as $ccmKey) {
            if (isset($mapping[$ccmKey]) && $mapping[$ccmKey] !== null) {
                continue; // skip already mapped
            }

            $ccmType    = $ccmSchema['ContentItem']['properties'][$ccmKey]['type'] ?? null;
            $candidates = [];
            foreach ($sourceKeysTypes as $sourceKey => $sourceType) {
                if ($sourceType === $ccmType && !in_array($sourceKey, $mapping, true)) {
                    $candidates[] = $sourceKey;
                }
            }
            // error_log("Candidates for CCM key '$ccmKey' (type '$ccmType'): " . print_r($candidates, true));

            if (count($candidates) === 1) {
                $mapping[$ccmKey] = $candidates[0];
                continue;
            }

            // If multiple, use name similarity
            $bestMatch = null;
            $highestScore = 0;
            foreach ($candidates as $candidate) {
                similar_text(strtolower($ccmKey), strtolower($candidate), $percent);
                if ($percent > $highestScore) {
                    $highestScore = $percent;
                    $bestMatch = $candidate;
                }
            }

            if ($highestScore > 40) {
                $mapping[$ccmKey] = $bestMatch;
            } else {
                $mapping[$ccmKey] = null;
            }
            error_log("Mapping: " . $ccmKey . " => " . ($bestMatch ?? 'null') . " (Score: $highestScore)");
        }       

        //map to custom fields
        //add another view for migration, and another view for editing the ccm mapping
        //map nested fields (e.g. author fields)

        // Third mapping: levenshtein distance matches
        // foreach ($ccmKeys as $ccmKey) {
        //     if (isset($mapping[$ccmKey]) && $mapping[$ccmKey] !== null) {
        //         continue; // Skip if already mapped
        //     }

        //     $bestMatch      = null;
        //     $lowestDistance = PHP_INT_MAX;

        //     foreach ($sourceKeysTypes as $sourceKey) {
        //         // Skip if this sourceKey is already used in mapping
        //         if (in_array($sourceKey, $mapping, true)) {
        //             continue;
        //         }
        //         $distance = levenshtein(strtolower($ccmKey), strtolower($sourceKey));
        //         if ($distance < $lowestDistance && $distance < 5) { // Threshold of 5
        //             $bestMatch      = $sourceKey;
        //             $lowestDistance = $distance;
        //         }
        //     }

        //     // Only map if a suitable match is found
        //     if ($bestMatch !== null) {
        //         $mapping[$ccmKey] = $bestMatch;
        //         error_log("Levenshtein Mapping: " . $ccmKey . " => " . ($bestMatch ?? 'null'));
        //     } else {
        //         error_log("No suitable levenshtein mapping found for: " . $ccmKey);
        //     }
        // }

        // Fourth mapping: Levenshtein and similar_text combined
        // foreach ($ccmKeys as $ccmKey) {
        //     if (isset($mapping[$ccmKey]) && $mapping[$ccmKey] !== null) {
        //         continue; // Skip if already mapped
        //     }

        //     $highestScore = 0;
        //     $bestMatch    = null;

        //     foreach ($sourceKeysTypes as $sourceKey) {
        //         // Skip if this sourceKey is already used in mapping
        //         if (in_array($sourceKey, $mapping, true)) {
        //             continue;
        //         }
        //         $lev = levenshtein(strtolower($ccmKey), strtolower($sourceKey));
        //         similar_text(strtolower($ccmKey), strtolower($sourceKey), $percent);

        //         // Combine both metrics for a score (lower levenshtein and higher percent is better)
        //         $score = (100 - min($lev, 100)) + $percent;

        //         if ($score > $highestScore) {
        //             $highestScore = $score;
        //             $bestMatch    = $sourceKey;
        //         }
        //     }
        //     // Only map if similarity is reasonably high
        //     if ($highestScore > 120) {
        //         $mapping[$ccmKey] = $bestMatch;
        //     }
        // }

        error_log("Final Mapping: " . print_r($mapping, true));

        $data = [
            'id'          => $id,
            'ccm_mapping' => json_encode($mapping, JSON_UNESCAPED_UNICODE),
        ];
        $this->save($data);

    }
}
