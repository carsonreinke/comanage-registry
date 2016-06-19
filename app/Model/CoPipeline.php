<?php
/**
 * COmanage Registry CO Pipeline Model
 *
 * Copyright (C) 2016 University Corporation for Advanced Internet Development, Inc.
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
 * @copyright     Copyright (C) 2016 University Corporation for Advanced Internet Development, Inc.
 * @link          http://www.internet2.edu/comanage COmanage Project
 * @package       registry
 * @since         COmanage Registry v1.1.0
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 * @version       $Id$
 */

class CoPipeline extends AppModel {
  // Define class name for cake
  public $name = "CoPipeline";
  
  // Current schema version for API
  public $version = "1.0";
  
  // Association rules from this model to other models
  public $belongsTo = array(
    "Co",
    "SyncCou" => array(
      'className' => 'Cou',
      'foreignKey'=>'sync_cou_id'
    ),
    "ReplaceCou" => array(
      'className' => 'Cou',
      'foreignKey'=>'sync_replace_cou_id'
    )
  );
  
  public $hasMany = array(
    'CoEnrollmentFlow',
    'CoSetting' => array(
      'foreignKey' => 'default_co_pipeline_id'
    ),
    'OrgIdentitySource'
  );
  
  // Default display field for cake generated views
  public $displayField = "name";
  
  public $actsAs = array('Containable',
                         'Changelog' => array('priority' => 5));
  
  // Validation rules for table elements
  public $validate = array(
    'co_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'allowEmpty' => false,
      'message' => 'A CO ID must be provided'
    ),
    'name' => array(
      'rule' => 'notBlank',
      'required' => true,
      'allowEmpty' => false
    ),
    'status' => array(
      'rule' => array('inList', array(SuspendableStatusEnum::Active,
                                      SuspendableStatusEnum::Suspended)),
      'required' => true,
      'message' => 'A valid status must be selected'
    ),
    'match_strategy' => array(
      'rule' => array('inList', array(// Not yet implemented (XXX JIRA)
                                      MatchStrategyEnum::EmailAddress,
                                      // MatchStrategyEnum::External, 
                                      MatchStrategyEnum::Identifier,
                                      MatchStrategyEnum::NoMatching)),
      'required'   => true,
      'allowEmpty' => false
    ),
    'match_type' => array(
      // We should really use validateExtendedType, but it's a bit tricky since
      // it's dependent on match_strategy. We'd need a new custom validation rule.
      'rule' => array('maxLength', 32),
      // Required only when match_strategy = Identifier (and, initially, EmailAddress)
      'required'   => false,
      'allowEmpty' => true
    ),
    'sync_on_add' => array(
      'rule'       => 'boolean',
      'required'   => false,
      'allowEmpty' => true
    ),
    'sync_on_update' => array(
      'rule'       => 'boolean',
      'required'   => false,
      'allowEmpty' => true
    ),
    'sync_on_delete' => array(
      'rule'       => 'boolean',
      'required'   => false,
      'allowEmpty' => true
    ),
    'sync_cou_id' => array(
      'rule' => 'numeric',
      'required' => false,
      'allowEmpty' => true
    ),
    'sync_replace_cou_id' => array(
      'rule' => 'numeric',
      'required' => false,
      'allowEmpty' => true
    ),
    'sync_status_on_delete' => array(
      'rule' => array('inList', array(StatusEnum::Deleted,
                                      StatusEnum::Expired,
                                      StatusEnum::GracePeriod,
                                      StatusEnum::Suspended)),
      'required'   => false,
      'allowEmpty' => true
    )
  );
  
  /**
   * Execute a CO Pipeline. Note: This function should be called from within
   * a transaction.
   *
   * @since  COmanage Registry v1.1.0
   * @param  Integer $id CO Pipeline to execute
   * @param  Integer $orgIdentityId Source Org Identity to run
   * @param  SyncActionEnum $syncAction Add, Update, or Delete
   * @param  Integer $actorCoPersonId CO Person ID of actor, if interactive
   * @return Boolean True on success
   * @throws InvalidArgumentException
   */
  
  public function execute($id, $orgIdentityId, $syncAction, $actorCoPersonId=null) {
    // Make sure we have a valid action
    
    if(!in_array($syncAction, array(SyncActionEnum::Add,
                                    SyncActionEnum::Delete,
                                    SyncActionEnum::Update))) {
      throw new InvalidArgumentException(_txt('er.unknown',
                                              array(Sanitize::paranoid($syncAction))));
    }
    
    // And that $orgIdentityId is in the CO. Pull the whole record since we'll
    // probably need it again.
    
    $args = array();
    $args['conditions']['OrgIdentity.id'] = $orgIdentityId;
    $args['contain'] = array(
      'Name',
      'PrimaryName',
      'Address',
      'EmailAddress',
      'Identifier',
      'TelephoneNumber',
      'OrgIdentitySourceRecord',
      // These will pull associated models that were created via the Pipeline
      'PipelineCoPersonRole'
    );
    
    $orgIdentity = $this->Co->OrgIdentity->find('first', $args);
    
    if(!$orgIdentity) {
      throw new InvalidArgumentException(_txt('er.notfound',
                                              array(_txt('ct.org_identities.1', $orgIdentityId))));
    }
    
    $args = array();
    $args['conditions']['CoPipeline.id'] = $id;
    $args['contain'] = false;
    
    $pipeline = $this->find('first', $args);
    
    if(empty($pipeline)) {
      throw new InvalidArgumentException(_txt('er.notfound', array(_txt('ct.co_pipelines.1'), $id)));
    }
    
    // See if we are configured for the requested action.
    
    if(($syncAction == SyncActionEnum::Add && !$pipeline['CoPipeline']['sync_on_add'])
       ||
       ($syncAction == SyncActionEnum::Update && !$pipeline['CoPipeline']['sync_on_update'])
       ||
       ($syncAction == SyncActionEnum::Delete && !$pipeline['CoPipeline']['sync_on_delete'])) {
      return true;
    }
    
    // We need to find a CO Person to operate on.
    $coPersonId = $this->findTargetCoPersonId($pipeline, $orgIdentityId, $actorCoPersonId);
    
    if(!$coPersonId) {
      // What we do here depends on the sync action. On add, we create a new CO Person.
      // On update, we do not and abort. This will be a bit confusing if something goes wrong
      // during an initial add, but short of a "force" (manual operation), there's
      // not much else to do. On delete we also abort.
      
      if($syncAction != SyncActionEnum::Add) {
        // If we don't have a CO Person record on an update or delete, there's
        // nothing to do.
        return true;
      }
    }
    
    if($syncAction == SyncActionEnum::Delete) {
      // XXX Update co person/role status as configured
    } else {
      $this->syncOrgIdentityToCoPerson($pipeline, $orgIdentity, $coPersonId, $actorCoPersonId);
    }
    
    if($syncAction == SyncActionEnum::Add) {
      // XXX if replace cou_id is set, expire any role in that COU for this CO Person
      // (this will only really be useful with matching enabled)
    }
  }
  
  /**
   * Find the target CO Person ID for the Pipeline.
   *
   * @since  COmanage Registry v1.1.0
   * @param  Array $pipeline Array of Pipeline configuration data
   * @param  Integer $orgIdentityId Source Org Identity to run
   * @param  Integer $actorCoPersonId CO Person ID of actor, if interactive
   * @return Integer CO Person ID, or null if none found
   * @throws InvalidArgumentException
   */
  
  protected function findTargetCoPersonId($pipeline, $orgIdentityId, $actorCoPersonId=null) {
    // We can assume a CO ID since Pipelines do not support pooled org identities
    $coId = $this->Co->OrgIdentity->field('co_id', array('OrgIdentity.id' => $orgIdentityId));
    
    if(!$coId) {
      throw new InvalidArgumentException('er.notprov.id', array(_txt('ct.cos.1')));
    }
    
    // First option is to see if the Org Identity is already linked to a CO Person ID.
    // Since org identities are not pooled, the org identity shouldn't have been linked
    // to a CO Person record outside of the CO, so we don't need to cross check the CO ID
    // of the CO Person.
    
    $coPersonId = $this->Co->CoPerson->CoOrgIdentityLink->field('co_person_id',
                                                                array('CoOrgIdentityLink.org_identity_id'
                                                                      => $orgIdentityId));
    
    if($coPersonId) {
      return $coPersonId;
    }
    
    // If not, then execute the appropriate Match Strategy.
    
    if($pipeline['CoPipeline']['match_strategy'] == MatchStrategyEnum::EmailAddress
       || $pipeline['CoPipeline']['match_strategy'] == MatchStrategyEnum::Identifier) {
      // Try to find a single CO Person in the current CO matching the specified
      // attribute. Note we're searching against CO People not Org Identities (but
      // using the new Org Identity's attribute).
      
      $model = $pipeline['CoPipeline']['match_strategy'] == MatchStrategyEnum::EmailAddress
               ? 'EmailAddress'
               : 'Identifier';
      
      // First, we need a record of the specified type, attached to the Org Identity.
      
      $args = array();
      $args['conditions'][$model.'.org_identity_id'] = $orgIdentityId;
      // Identifier requires type, but email address does not
      if($pipeline['CoPipeline']['match_strategy'] == MatchStrategyEnum::Identifier
         || !empty($pipeline['CoPipeline']['match_type'])) {
        $args['conditions'][$model.'.type'] = $pipeline['CoPipeline']['match_type'];
      }
      $args['contain'] = false;
      
      $orgRecords = $this->Co->CoPerson->$model->find('all', $args);
      
      // In the unlikely event we get more than one match, we'll try them all
      
      foreach($orgRecords as $o) {
        $args = array();
        // EmailAddress is case insensitive, but Identifer is not
        if($pipeline['CoPipeline']['match_strategy'] == MatchStrategyEnum::EmailAddress) {
          $args['conditions']['LOWER(EmailAddress.mail)'] = strtolower($o['EmailAddress']['mail']);
        } else {
          $args['conditions']['Identifier.identifier'] = $o['Identifier']['identifier'];
        }
        if($pipeline['CoPipeline']['match_strategy'] == MatchStrategyEnum::Identifier
           || !empty($pipeline['CoPipeline']['match_type'])) {
          $args['conditions'][$model.'.type'] = $pipeline['CoPipeline']['match_type'];
        }
        $args['conditions']['CoPerson.co_id'] = $pipeline['CoPipeline']['co_id'];
        $args['joins'][0]['table'] = 'co_people';
        $args['joins'][0]['alias'] = 'CoPerson';
        $args['joins'][0]['type'] = 'INNER';
        $args['joins'][0]['conditions'][0] = 'CoPerson.id=' . $model . '.co_person_id';
        $args['contain'] = false;
        
        $matchingRecords = $this->Co->CoPerson->$model->find('all', $args);
        
        if(count($matchingRecords) == 1) {
          $coPersonId = $matchingRecords[0][$model]['co_person_id'];
          break;
        } elseif(count($matchingRecords) > 1) {
          // Multiple matching records shouldn't happen, throw an error
          throw new InvalidArgumentException(_txt('er.pi.match.multi', array(_txt('en.match.strategy',
                                                                                  null,
                                                                                  $pipeline['CoPipeline']['match_strategy']))));
        }
        // else No Match
      }
    } elseif($pipeline['CoPipeline']['match_strategy'] == MatchStrategyEnum::External) {
      // This is where we'd call out to (eg) the CIFER/TIER ID Match API. We probably
      // want to do something like send a bunch of attributes (as configured) and use
      // the resulting Reference Identifier as a handle to pull the appropriate CO Person
      // record (via the identifiers table). Unclear how to handle potential/pending matches.
      
      throw new InvalidArgumentException('NOT IMPLEMENTED');
    }
    // else No Matching
    
    if($coPersonId) {
      // If we found a match, note history
      $this->Co->CoPerson->HistoryRecord->record($coPersonId,
                                                 null,
                                                 $orgIdentityId,
                                                 $actorCoPersonId,
                                                 ActionEnum::CoPersonMatchedPipelne,
                                                 _txt('rs.pi.match', array($pipeline['CoPipeline']['name'],
                                                                           $pipeline['CoPipeline']['id'],
                                                                           _txt('en.match.strategy',
                                                                                null,
                                                                                $pipeline['CoPipeline']['match_strategy']))));
      
      return $coPersonId;
    }
    
    // No existing record, return null.
    
    return null;
  }
  
  /**
   * Sync Org Identity attributes to a CO Person record. Suitable for add or update
   * sync actions.
   *
   * @since  COmanage Registry v1.1.0
   * @param  Array $coPipeline Array of CO Pipeline configuration
   * @param  Array $orgIdentity Array of Org Identity data and related models
   * @param  Integer $coPersonId Target CO Person ID, if known
   * @param  Integer $actorCoPersonId CO Person ID of actor
   * @return Boolean true, on success
   */
  
  protected function syncOrgIdentityToCoPerson($coPipeline, $orgIdentity, $targetCoPersonId=null, $actorCoPersonId=null) {
    $coPersonId = $targetCoPersonId;
    $coPersonRoleId = null;
    
    // If there is no CO Person ID provided, the first thing we need to do is
    // create a new CO Person record and link it to the Org Identity.
    
    if(!$coPersonId) {
      // First create the CO Person
      
      $coPerson = array(
        'CoPerson' => array(
          'co_id'  => $orgIdentity['OrgIdentity']['co_id'],
          'status' => StatusEnum::Active
        )
      );
      
      if(!$this->Co->CoPerson->save($coPerson, array("provision" => false))) {
        throw new RuntimeException(_txt('er.db.save-a', array('CoPerson')));
      }
      
      $coPersonId = $this->Co->CoPerson->id;
      
      // Now link it to the Org Identity
      
      $orgLink = array(
        'CoOrgIdentityLink' => array(
          'co_person_id'    => $coPersonId,
          'org_identity_id' => $orgIdentity['OrgIdentity']['id']
        )
      );
      
      if(!$this->Co->CoPerson->CoOrgIdentityLink->save($orgLink, array("provision" => false))) {
        throw new RuntimeException(_txt('er.db.save-a', array('CoOrgIdentityLink')));
      }
      
      // And create a Primary Name
      
      $name = array(
        'Name' => array(
          'co_person_id'   => $coPersonId,
          'honorific'      => $orgIdentity['PrimaryName']['honorific'],
          'given'          => $orgIdentity['PrimaryName']['given'],
          'middle'         => $orgIdentity['PrimaryName']['middle'],
          'family'         => $orgIdentity['PrimaryName']['family'],
          'suffix'         => $orgIdentity['PrimaryName']['suffix'],
          'type'           => $orgIdentity['PrimaryName']['type'],
          'primary_name'   => true,
          'source_name_id' => $orgIdentity['PrimaryName']['id'],
        )
      );
      
      if(!$this->Co->CoPerson->Name->save($name, array("provision" => false))) {
        throw new RuntimeException(_txt('er.db.save-a', array('Name')));
      }
      
      // Cut history
      $this->Co->CoPerson->HistoryRecord->record($coPersonId,
                                                 null,
                                                 $orgIdentity['OrgIdentity']['id'],
                                                 $actorCoPersonId,
                                                 ActionEnum::CoPersonAddedPipeline,
                                                 _txt('rs.pi.sync-a', array(_txt('ct.co_people.1'),
                                                                            $coPipeline['CoPipeline']['name'],
                                                                            $coPipeline['CoPipeline']['id'])));
      
      $this->Co->CoPerson->HistoryRecord->record($coPersonId,
                                                 null,
                                                 $orgIdentity['OrgIdentity']['id'],
                                                 $actorCoPersonId,
                                                 ActionEnum::CoPersonOrgIdLinked);
    }
    
    // Construct a CO Person Role and compare against existing.
    
    $newCoPersonRole = array(
      'CoPersonRole' => array(
        // Affiliation is required by CoPersonRole, so if not provided set default
        'affiliation' => (!empty($orgIdentity['OrgIdentity']['affiliation'])
                          ? $orgIdentity['OrgIdentity']['affiliation']
                          : AffiliationEnum::Member),
        // Set the cou_id even if null so the diff operates correctly
        'cou_id'      => $coPipeline['CoPipeline']['sync_cou_id'],
        'o'           => $orgIdentity['OrgIdentity']['o'],
        'ou'          => $orgIdentity['OrgIdentity']['ou'],
        'title'       => $orgIdentity['OrgIdentity']['title'],
        'status'      => StatusEnum::Active
      )
    );
    
    // Next see if there is a role associated with this OrgIdentity.
    
    if(!empty($orgIdentity['PipelineCoPersonRole']['id'])) {
      $newCoPersonRole['CoPersonRole']['id'] = $orgIdentity['PipelineCoPersonRole']['id'];
      
      $curCoPersonRole = array();
      
      // Note there are a bunch of unsupported attributes at the moment (valid_from, etc)
      foreach(array('id', 'affiliation', 'cou_id', 'o', 'ou', 'title', 'status') as $attr) {
        $curCoPersonRole['CoPersonRole'][$attr] = $orgIdentity['PipelineCoPersonRole'][$attr];
      }
      
      // Diff array to see if we should save
      $cstr = $this->Co->CoPerson->CoPersonRole->changesToString($newCoPersonRole,
                                                                 $curCoPersonRole);
      
      if(!empty($cstr)) {
        // Cut the history diff here, since we don't want this on an add
        // (If the save fails later the parent transaction will roll this back)
        
        $this->Co->OrgIdentity->HistoryRecord->record($coPersonId,
                                                      $orgIdentity['PipelineCoPersonRole']['id'],
                                                      null,
                                                      $actorCoPersonId,
                                                      ActionEnum::CoPersonRoleEditedPipeline,
                                                      _txt('rs.edited-a4', array(_txt('ct.co_person_roles.1'),
                                                                                 $cstr)));
      } else {
        // No change, unset $newCoPersonRole to indicate not to bother saving
        $newCoPersonRole = array();
      }
    } else {
      // No current person role, so just save as is
    }
    
    if(!empty($newCoPersonRole)) {
      // Save the updated record and cut history
      
      // Link the role before saving
      $newCoPersonRole['CoPersonRole']['co_person_id'] = $coPersonId;
      $newCoPersonRole['CoPersonRole']['source_org_identity_id'] = $orgIdentity['OrgIdentity']['id'];
      
      if(!$this->Co->CoPerson->CoPersonRole->save($newCoPersonRole, array("provision" => false))) {
        throw new RuntimeException(_txt('er.db.save-a', array('CoPersonRole')));
      }
      
      // Cut history
      $this->Co->CoPerson->HistoryRecord->record($coPersonId,
                                                 $this->Co->CoPerson->CoPersonRole->id,
                                                 $orgIdentity['OrgIdentity']['id'],
                                                 $actorCoPersonId,
                                                 ActionEnum::CoPersonRoleAddedPipeline,
                                                 _txt('rs.pi.sync-a', array(_txt('ct.co_person_roles.1'),
                                                                            $coPipeline['CoPipeline']['name'],
                                                                            $coPipeline['CoPipeline']['id'])));
    }
    
    // Next handle associated models
    
    $newEmailAddresses = array();
    $curEmailAddresses = array();
    
    // Map each org email address into a "new" CO Person email address,
    // keyed on the org email address' id
    
    foreach($orgIdentity['EmailAddress'] as $orgEmailAddress) {
      // Construct the new email address
      $newEmailAddress = $orgEmailAddress;
      
      // Get rid of metadata keys
      foreach(array('id',
                    'org_identity_id',
                    'created',
                    'modified',
                    'email_address_id',
                    'revision',
                    'deleted',
                    'actor_identifier') as $k) {
        unset($newEmailAddress[$k]);
      }
      
      // And link the record
      $newEmailAddress['co_person_id'] = $coPersonId;
      $newEmailAddress['source_email_address_id'] = $orgEmailAddress['id'];
      
      $newEmailAddresses[ $orgEmailAddress['id'] ] = $newEmailAddress;
    }
    
    // Get the set of current CO Person email addresses and prepare them for comparison
    
    $args = array();
    $args['conditions']['EmailAddress.co_person_id'] = $coPersonId;
    $args['contain'] = false;
    
    $addrs = $this->Co->CoPerson->EmailAddress->find('all', $args);
    
    foreach($addrs as $a) {
      $curEmailAddress = $a['EmailAddress'];
      
      // Get rid of metadata keys
      foreach(array('org_identity_id',
                    'created',
                    'modified',
                    'email_address_id',
                    'revision',
                    'deleted',
                    'actor_identifier') as $k) {
        unset($curEmailAddress[$k]);
      }
      
      $curEmailAddresses[ $curEmailAddress['source_email_address_id'] ] = $curEmailAddress;
    }
    
    // Now that the lists are ready, walk through them and process any changes
    
    foreach($newEmailAddresses as $id => $ea) {
      if(isset($curEmailAddresses[$id])) {
        // This is an update, not an add, so perform a comparison. Inject the record ID.
        
        $newEmailAddresses[$id]['id'] = $curEmailAddresses[$id]['id'];
        
        $cstr = $this->Co->CoPerson->EmailAddress->changesToString(array('EmailAddress' => $newEmailAddresses[$id]),
                                                                   array('EmailAddress' => $curEmailAddresses[$id]));
        
        if(!empty($cstr)) {
          // Cut the history diff here, since we don't want this on an add
          // (If the save fails later the parent transaction will roll this back)
          
          $this->Co->OrgIdentity->HistoryRecord->record($coPersonId,
                                                        null,
                                                        null,
                                                        $actorCoPersonId,
                                                        ActionEnum::CoPersonEditedPipeline,
                                                        _txt('rs.edited-a4', array(_txt('ct.email_addresses.1'),
                                                                                   $cstr)));
        } else {
          // No change, unset $newEmailAddress to indicate not to bother saving
          unset($newEmailAddresses[$id]);
          // And unset $curEmailAddress so we don't see it as a delete
          unset($curEmailAddresses[$id]);
        }
      }
      
      // If the record is still valid record history (do this here since it's easier, we'll rollback on save failure)
      if(isset($curEmailAddresses[$id])) {
        $this->Co->CoPerson->HistoryRecord->record($coPersonId,
                                                   null,
                                                   $orgIdentity['OrgIdentity']['id'],
                                                   $actorCoPersonId,
                                                   ActionEnum::CoPersonEditedPipeline,
                                                   _txt('rs.pi.sync-a', array(_txt('ct.email_addresses.1'),
                                                                              $coPipeline['CoPipeline']['name'],
                                                                              $coPipeline['CoPipeline']['id'])));
      }
    }
    
    // And finally process the save for any remaining addresses
    if(!empty($newEmailAddresses)) {
      // Save the email addresses
      
      if(!$this->Co->CoPerson->EmailAddress->saveMany($newEmailAddresses, array("provision" => false))) {
        throw new RuntimeException(_txt('er.db.save-a', array('EmailAddress')));
      }
    }
    
    foreach($curEmailAddresses as $id => $ea) {
      if(!isset($newEmailAddresses[$id])) {
        // This is a delete
        // XXX handle
      }
    }
    
    // If the OrgIdentity came from an OIS, see if there are mapped group memberships
    $memberGroups = array();
    
    if(!empty($orgIdentity['OrgIdentitySourceRecord']['org_identity_source_id'])
       && !empty($orgIdentity['OrgIdentitySourceRecord']['source_record'])) {
      $groupAttrs = $this->OrgIdentitySource
                         ->resultToGroups($orgIdentity['OrgIdentitySourceRecord']['org_identity_source_id'],
                                          $orgIdentity['OrgIdentitySourceRecord']['source_record']);
      $mappedGroups = $this->OrgIdentitySource
                           ->CoGroupOisMapping
                           ->mapGroups($orgIdentity['OrgIdentitySourceRecord']['org_identity_source_id'],
                                       $groupAttrs);
      
      if(!empty($mappedGroups)) {
        // Pull the Group Names
        $args = array();
        $args['conditions']['CoGroup.id'] = array_keys($mappedGroups);
        $args['contain'] = false;
        
        $memberGroups = $this->Co->CoGroup->find('all', $args);
      }
    }
    
    if(!empty($memberGroups)) {
      // Group memberships are a bit trickier than other MVPAs, since we can't have
      // multiple memberships in the same group. So we only add a membership if there
      // is no existing membership (not if there is no existing membership linked
      // to this pipeline), and we only delete memberships linked to this pipeline
      // if there is no longer eligibility. (There is currently no "update" concept,
      // eg member to owner.)
      
      // Start by pulling the list of current group memberships.
      
      $args = array();
      $args['conditions']['CoGroupMember.co_person_id'] = $coPersonId;
      $args['conditions']['CoGroupMember.member'] = true;
      $args['contain'] = false;
      
      $curGroupMemberships = $this->Co->CoGroup->CoGroupMember->find('all', $args);
      
      // For each mapped group membership, create the membership if it doesn't exist
      
      foreach($memberGroups as $gm) {
        if(!Hash::check($curGroupMemberships, '{n}.CoGroupMember[co_group_id='.$gm['CoGroup']['id'].'].id')) {
          $newGroupMember = array(
            'CoGroupMember' => array(
              'co_group_id'            => $gm['CoGroup']['id'],
              'co_person_id'           => $coPersonId,
              'member'                 => true,
              'owner'                  => false,
              'source_org_identity_id' => $orgIdentity['OrgIdentity']['id']
            )
          );
          
          if(!$this->Co->CoPerson->CoGroupMember->save($newGroupMember, array("provision" => false))) {
            throw new RuntimeException(_txt('er.db.save-a', array('CoGroupMember')));
          }
          
          // Cut history
          $this->Co->CoPerson->HistoryRecord->record($coPersonId,
                                                     null,
                                                     $orgIdentity['OrgIdentity']['id'],
                                                     $actorCoPersonId,
                                                     ActionEnum::CoGroupMemberAddedPipeline,
                                                     _txt('rs.grm.added', array($gm['CoGroup']['name'],
                                                                                $gm['CoGroup']['id'],
                                                                                _txt($newGroupMember['CoGroupMember']['member'] ? 'fd.yes' : 'fd.no'),
                                                                                _txt($newGroupMember['CoGroupMember']['owner'] ? 'fd.yes' : 'fd.no'))),
                                                     $gm['CoGroup']['id']);
          
          $this->Co->CoPerson->HistoryRecord->record($coPersonId,
                                                     null,
                                                     $orgIdentity['OrgIdentity']['id'],
                                                     $actorCoPersonId,
                                                     ActionEnum::CoGroupMemberAddedPipeline,
                                                     _txt('rs.pi.sync-a', array(_txt('ct.co_group_members.1'),
                                                                                $coPipeline['CoPipeline']['name'],
                                                                                $coPipeline['CoPipeline']['id'])),
                                                     $gm['CoGroup']['id']);
        }
      }
      
      // Walk through current list of Group Memberships and remove any associated
      // with this pipeline and not present in $memberGroups.
      
      foreach($curGroupMemberships as $cgm) {
        if($cgm['CoGroupMember']['source_org_identity_id']
           && $cgm['CoGroupMember']['source_org_identity_id'] == $orgIdentity['OrgIdentity']['id']) {
          // This group came from this source org identity, is it still valid?
          // (Cake's Hash syntax is a bit obscure...)
          $gid = $cgm['CoGroupMember']['co_group_id'];
          
          if(!Hash::check($memberGroups, '{n}.CoGroup[id='.$gid.'].id')) {
            // Not a valid group membership anymore, delete it. We need to pull the
            // group to get the name for history.
            
            $gname = $this->Co->CoGroup->field('name', array('CoGroup.id' => $gid));
            
            if(!$this->Co->CoPerson->CoGroupMember->delete($cgm['CoGroupMember']['id'], array("provision" => false))) {
              throw new RuntimeException(_txt('er.db.save-a', array('CoGroupMember')));
            }
            
            // Cut history
            $this->Co->CoPerson->HistoryRecord->record($coPersonId,
                                                       null,
                                                       $orgIdentity['OrgIdentity']['id'],
                                                       $actorCoPersonId,
                                                       ActionEnum::CoGroupMemberDeletedPipeline,
                                                       _txt('rs.grm.deleted', array($gname, $gid)),
                                                       $gid);
            
            $this->Co->CoPerson->HistoryRecord->record($coPersonId,
                                                       null,
                                                       $orgIdentity['OrgIdentity']['id'],
                                                       $actorCoPersonId,
                                                       ActionEnum::CoGroupMemberDeletedPipeline,
                                                       _txt('rs.pi.sync-a', array(_txt('ct.co_group_members.1'),
                                                                                  $coPipeline['CoPipeline']['name'],
                                                                                  $coPipeline['CoPipeline']['id'])),
                                                       $gid);
          }
        }
      }
    }
    
    return true;
  }
}