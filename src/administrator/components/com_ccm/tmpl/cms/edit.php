<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_ccm
 *
 * @copyright   (C) 2025 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var Joomla\CMS\WebAsset\WebAssetManager $wa */
$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('keepalive')
   ->useScript('form.validate');
?>   

<form action="<?php echo Route::_('index.php?option=com_ccm&view=cms&layout=edit&id=' . (int) $this->item->id); ?>"
      method="post" name="adminForm" id="cms-form" aria-label="<?php echo Text::_('COM_CCM_CMS_FORM_' . ((int) $this->item->id === 0 ? 'NEW' : 'EDIT'), true); ?>"
      class="main-card form-validate">
    <?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'details', 'recall' => true, 'breakpoint' => 768]); ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'details', Text::_('COM_CCM_CMS_DETAILS')); ?>
    <div class="form-grid">
        <?php foreach ($this->form->getFieldset() as $field) :?>
            <?php echo $field->renderField(); ?>
        <?php endforeach;?>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>
    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

    <?php echo $this->form->renderControlFields(); ?>
    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
