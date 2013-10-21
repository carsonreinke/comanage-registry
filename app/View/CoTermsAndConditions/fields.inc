<!--
/**
 * COmanage Registry CO Terms and Conditions Fields
 *
 * Copyright (C) 2013 University Corporation for Advanced Internet Development, Inc.
 * 
 * Licensed under the Apache License, Version 2.0 (the "License"); you may not use this file except in compliance with
 * the License. You may obtain a copy of the License at
 * 
 * http://www.apache.org/licenses/LICENSE-2.0
 * 
 * Unless required by applicable law or agreed to in writing, software distributed under
 * the License is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY
 * KIND, either express or implied. See the License for the specific language governing
 * permissions and limitations under the License.
 *
 * @copyright     Copyright (C) 201 University Corporation for Advanced Internet Development, Inc.
 * @link          http://www.internet2.edu/comanage COmanage Project
 * @package       registry
 * @since         COmanage Registry v0.8.3
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 * @version       $Id$
 */
-->
<?php
  // Determine if fields are editable
  $e = false;
  
  if(($this->action == "add" && $permissions['add']) || ($this->action == "edit" && $permissions['edit']))
    $e = true;
    
  // We shouldn't get here if we don't have at least read permission, but check just in case
  
  if(!$e && !$permissions['view'])
    return(false);
  
  print $this->Form->hidden('co_id', array('default' => $cur_co['Co']['id'])) . "\n";
?>
<table id="<?php print $this->action; ?>_co_terms_and_conditions" class="ui-widget">
  <tbody>
    <tr class="line1">
      <td>
        <b><?php print _txt('fd.desc'); ?></b><font class="required">*</font>
      </td>
      <td>
        <?php print ($e
                     ? $this->Form->input('description', array('size' => '60'))
                     : Sanitize::html($co_terms_and_conditions[0]['CoTermsAndConditions']['description'])); ?>
      </td>
    </tr>
    <tr class="line2">
      <td>
        <b><?php print _txt('fd.status'); ?></b><font class="required">*</font>
      </td>
      <td>
        <?php
          global $cm_lang, $cm_texts;
          $attrs['value'] = (isset($co_terms_and_conditions[0]['CoTermsAndConditions']['status'])
                             ? $co_terms_and_conditions[0]['CoTermsAndConditions']['status']
                             : StatusEnum::Active);
          $attrs['empty'] = false;
          
          if($e) {
            print $this->Form->select('status',
                                      $cm_texts[ $cm_lang ]['en.status.susp'],
                                      $attrs);
            
            if($this->Form->isFieldError('status')) {
              print $this->Form->error('status');
            }
          } else {
            print _txt('en.status', null, $co_terms_and_conditions[0]['CoTermsAndConditions']['status']);
          }
        ?>
      </td>
    </tr>
    <tr class="line1">
      <td>
        <b><?php print _txt('fd.url'); ?></b></b><font class="required">*</font><br />
        <font class="desc"><?php print _txt('fd.tc.url.desc'); ?></font>
      </td>
      <td>
        <?php print ($e
                     ? $this->Form->input('url', array('size' => '60'))
                     : Sanitize::html($co_terms_and_conditions[0]['CoTermsAndConditions']['url'])); ?>
      </td>
    </tr>
    <tr class="line2">
      <td>
        <b><?php print _txt('fd.cou'); ?></b><br />
        <font class="desc"><?php print _txt('fd.tc.cou.desc'); ?></font>
      </td>
      <td>
        <?php
          if(!empty($cous)) {
            $attrs = array();
            $attrs['value'] = (isset($co_terms_and_conditions[0]['CoTermsAndConditions']['cou_id'])
                               ? $co_terms_and_conditions[0]['CoTermsAndConditions']['cou_id']
                               : null);
            $attrs['empty'] = true;
            
            if($e) {
              print $this->Form->select('cou_id',
                                        $cous,
                                        $attrs);
              
              if($this->Form->isFieldError('cou_id')) {
                print $this->Form->error('cou_id');
              }
            } else {
              print $cous[ $co_terms_and_conditions[0]['CoTermsAndConditions']['cou_id'] ];
            }
          }
        ?>
      </td>
    </tr>
  </tbody>
</table>
<?php
  if($e) {
    print $this->Form->submit($submit_label, array('onClick' => 'on_submit()'));
    print $this->Form->button(_txt('op.reset'), 
                              array('type'=>'reset'));
  }
?>