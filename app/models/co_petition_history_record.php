<?php
  /*
   * COmanage Registry CO Petition History Record Model
   *
   * Version: $Revision$
   * Date: $Date$
   *
   * Copyright (C) 2011 University Corporation for Advanced Internet Development, Inc.
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
   */

  class CoPetitionHistoryRecord extends AppModel {
    // Define class name for cake
    var $name = "CoPetitionHistoryRecord";
    
    // Association rules from this model to other models
    var $belongsTo = array("CoPetition",        // A CO Petition History Record is attached to a CO Petition
                           "ActorCoPerson" =>
                           array('className' => 'CoPerson',
                                 'foreignKey' => 'actor_co_person_id'));
  
    // Default display field for cake generated views
    var $displayField = "comment";
    
    // Default ordering for find operations
    var $order = array("comment");
    
    // Validation rules for table elements
    var $validate = array(
    );
  }
?>