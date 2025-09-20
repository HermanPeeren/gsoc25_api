<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_ccm
 *
 * @copyright   (C) 2025 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\CCM\Administrator\Fields;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Form\Field\ListField;

class CmsObjTypeField extends ListField {

    // the name of the type for our new field
    protected $type = 'cmsobjtype';

    public function getOptions() // i need the cms id so I can get its objects types
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);
        // $query->select('a.id, a.name')
        //     ->from('#__ccm_cms AS a')
        //     ->order('a.name', 'asc');
        // $db->setQuery($query);
        // $options = [
        //     (object)[
        //         'value' => '',
        //         'text'  => '-Select-'
        //     ]
        // ];
        foreach ($db->loadAssocList() as $row) {
            $options[] = (object)[
                'value' => $row['id'],
                'text'  => $row['name']
            ];
        }

        return array_merge(parent::getOptions(), $options);
    }
}