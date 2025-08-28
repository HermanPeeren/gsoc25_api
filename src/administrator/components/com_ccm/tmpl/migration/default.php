<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  CCM
 *
 * @copyright   (C) 2009 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;
use Joomla\CMS\Router\Route;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
HTMLHelper::_('behavior.formvalidator');
HTMLHelper::_('behavior.keepalive');
?>
<form action="<?php echo Route::_('index.php?option=com_ccm&task=migration.apply'); ?>" method="post" id="migration-form" name="adminForm" class="form-validate">
    
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><?php echo Text::_('COM_CCM_MIGRATION_FIELDSET_LABEL'); ?></h3>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <fieldset class="options-form">
                        <legend><?php echo Text::_('MIGRATION_SOURCE_CMS'); ?></legend>
                        <?php echo $this->form->renderField('source_cms'); ?>
                        <?php echo $this->form->renderField('source_cms_object_type'); ?>
                    </fieldset>
                </div>
                <div class="col-md-6">
                    <fieldset class="options-form">
                        <legend><?php echo Text::_('MIGRATION_TARGET_CMS'); ?></legend>
                        <?php echo $this->form->renderField('target_cms'); ?>
                        <?php echo $this->form->renderField('target_cms_object_type'); ?>
                    </fieldset>
                </div>
            </div>
            
            <div class="row mt-3">
                <div class="col-md-12">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <span class="icon-upload" aria-hidden="true"></span>
                        <?php echo Text::_('APPLY_MIGRATION_BTN'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php echo HTMLHelper::_('form.token'); ?>
</form>