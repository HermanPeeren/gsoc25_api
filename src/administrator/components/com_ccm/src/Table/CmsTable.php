<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  CCM
 *
 * @copyright   (C) 2009 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Reem\Component\CCM\Administrator\Table;

defined('_JEXEC') or die;

use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;

class CmsTable extends Table
{
    protected $_jsonEncode = ['documents', "ccm_mapping"];

    public function __construct(DatabaseInterface $db)
    {
        parent::__construct('#__ccm_cms', 'id', $db);
    }
}