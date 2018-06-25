<?php

require_once 'relatedpermissions.civix.php';

/**
 * Implementation of hook_civicrm_config
 */
function relatedpermissions_civicrm_config(&$config) {
  _relatedpermissions_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function relatedpermissions_civicrm_xmlMenu(&$files) {
  _relatedpermissions_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function relatedpermissions_civicrm_install() {
  return _relatedpermissions_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function relatedpermissions_civicrm_uninstall() {
  return _relatedpermissions_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function relatedpermissions_civicrm_enable() {
  return _relatedpermissions_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function relatedpermissions_civicrm_disable() {
  return _relatedpermissions_civix_civicrm_disable();
}

function relatedpermissions_civicrm_alterEntitySettingsFolders(&$folders) {
  static $configured = FALSE;
  if ($configured) return;
  $configured = TRUE;

  $extRoot = dirname( __FILE__ ) . DIRECTORY_SEPARATOR;
  $extDir = $extRoot . 'settings';
  if(!in_array($extDir, $folders)){
    $folders[] = $extDir;
  }
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function relatedpermissions_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _relatedpermissions_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function relatedpermissions_civicrm_managed(&$entities) {
  return _relatedpermissions_civix_civicrm_managed($entities);
}

/*** Create New Permissions ***/
function relatedpermissions_civicrm_permission( &$permissions )
{
    $prefix = ts('CiviCRM') . ': ';
    $permissions += array(
        'edit assigned contacts' => $prefix . ts('Cascading Edit Privilege (edit checkboxes only)'),
        'view assigned contacts' => $prefix . ts('Cascading View Privilege (view checkboxes only)')
    );
}

/*
 * Implement WHERE Clause - we find the contacts for whom this contact has permission and
 * specifically give permission to them
 */
function relatedpermissions_civicrm_aclWhereClause($type, &$tables, &$whereTables, &$contactID, &$where) {
    //die(print_r($type,1));
    if (!$contactID || CRM_Core_Permission::check('edit all contacts')) {
    return;
  }

  $viewTypes = array(2,5);
  $editTypes = array(1,2,3,4,5,6);
  
  if ( (CRM_Core_Permission::check('edit assigned contacts') && in_array($type,$editTypes)) 
      || (CRM_Core_Permission::check('view assigned contacts') && in_array($type,$viewTypes)) ) {
    $tmpTableName = _relatedpermissions_get_permissionedtable($contactID);

    $tables ['$tmpTableName'] = $whereTables ['$tmpTableName'] =
      " LEFT JOIN $tmpTableName permrelationships
     ON (contact_a.id = permrelationships.contact_id)";
    if (empty($where)) {
      $where = " permrelationships.contact_id IS NOT NULL ";
    }
    else {
      $where = '(' . $where . " OR permrelationships.contact_id IS NOT NULL " . ')';
    }
  }
}

/**
 * Get permission for a given entity id in a given direction
 * @param integer $entity_id
 * @param string $direction
 * @return Ambigous <null, array>
 */
function _relatedpermissions_is_permission($entity_id, $direction) {
  static $settings = array();
  if(!isset($settings[$entity_id])) {
    $entity_settings = civicrm_api3('entity_setting', 'get', array(
      'key' => 'uk.co.compucorp.civicrm.relatedpermissions',
      'entity_id' => $entity_id,
      'entity_type' => 'relationship_type')
    );
    $settings = $entity_settings['values'][$entity_id];
  }

  return CRM_Utils_Array::value('always_permission_' . $direction, $settings);
}

/**
 * @param $tmpTableSecondaryContacts
 * @param $tmpTableName
 * @param $now
 */
function calculateInheritedPermissions($tmpTableSecondaryContacts, $tmpTableName, $now) {
    $sql = "
      REPLACE INTO $tmpTableSecondaryContacts
      SELECT DISTINCT contact_id_b, contact_b.contact_type
      FROM $tmpTableName tmp
      
      LEFT JOIN civicrm_relationship r ON tmp.contact_id = r.contact_id_a
      INNER JOIN civicrm_contact c ON c.id = r.contact_id_a 
      INNER JOIN civicrm_contact contact_b ON contact_b.id = r.contact_id_b
      
      WHERE r.is_active = 1
      AND (start_date IS NULL OR start_date <= '{$now}' )
      AND (end_date IS NULL OR end_date >= '{$now}')
      AND (is_permission_a_b = 1 OR is_permission_a_b_v = 1)
      AND c.is_deleted = 0
    ";

    CRM_Core_DAO::executeQuery($sql);

    $sql = "
      REPLACE INTO $tmpTableSecondaryContacts
      SELECT contact_id_a, contact_b.contact_type
      FROM $tmpTableName tmp
      
      LEFT JOIN civicrm_relationship r ON tmp.contact_id = r.contact_id_b
      INNER JOIN civicrm_contact c ON c.id = r.contact_id_b 
      INNER JOIN civicrm_contact contact_b ON contact_b.id = r.contact_id_b
      
      WHERE r.is_active = 1
      AND (start_date IS NULL OR start_date <= '{$now}' )
      AND (end_date IS NULL OR end_date >= '{$now}')
      AND (is_permission_b_a = 1 OR is_permission_b_a_v = 1)
      AND c.is_deleted = 0
    ";

    CRM_Core_DAO::executeQuery($sql);
}

/**
 * Create temporary table of all permissioned contacts.
 * If the contacts are organisations then we want all contacts they have permission
 * over. Note that in order to avoid ORs & unindexed fields in the ON clause we use several queries
 */
function _relatedpermissions_get_permissionedtable($contactID)
{
  static $tempTables = array();

  $dateKey = date('dhis');

  if (!empty($tempTables[$contactID])) {
    return $tempTables[$contactID]['permissioned_contacts'];
  } else {
    $tmpTableName = 'my_relationships_' . $contactID . '_' . rand(10000, 100000);
    $sql = "
      CREATE TEMPORARY TABLE $tmpTableName (
        `contact_id` INT(10) NOT NULL,
        PRIMARY KEY (`contact_id`)
      )
    ";

    CRM_Core_DAO::executeQuery($sql);

    $tmpTableSecondaryContacts = 'my_secondary_relationships' . $dateKey . rand(10000, 100000);
    $sql = "
      CREATE TEMPORARY TABLE $tmpTableSecondaryContacts (
        `contact_id` INT(10) NOT NULL,
        PRIMARY KEY (`contact_id`),
        `contact_type` VARCHAR(50) NULL DEFAULT NULL
      )
    ";

    CRM_Core_DAO::executeQuery($sql);
  }

  $tempTables[$contactID]['permissioned_contacts'] = $tmpTableName;
  $tempTables[$contactID]['permissioned_secondary_contacts'] = $tmpTableSecondaryContacts;

  $now = date('Y-m-d');

  $sql = "
    INSERT INTO $tmpTableName
    SELECT DISTINCT contact_id_a FROM civicrm_relationship
    
    WHERE contact_id_b = $contactID
    AND is_active = 1
    AND (start_date IS NULL OR start_date <= '{$now}' )
    AND (end_date IS NULL OR end_date >= '{$now}')
    AND (is_permission_b_a = 1 OR is_permission_b_a_v = 1)
  ";

  CRM_Core_DAO::executeQuery($sql);

  $sql = "
    REPLACE INTO $tmpTableName
    SELECT contact_id_b FROM civicrm_relationship
    
    WHERE contact_id_a = $contactID
    AND is_active = 1
    AND (start_date IS NULL OR start_date <= '{$now}' )
    AND (end_date IS NULL OR end_date >= '{$now}')
    AND (is_permission_a_b = 1 OR is_permission_a_b_v = 1)
  ";

  CRM_Core_DAO::executeQuery($sql);

  /*
  * Next we generate a table of the permissioned contacts permissioned contacts for Orgs & Households
  */

  calculateInheritedPermissions($tmpTableSecondaryContacts, $tmpTableName, $now);

  $sql = "
    REPLACE INTO $tmpTableName
    SELECT contact_id FROM $tmpTableSecondaryContacts
  ";

  CRM_Core_DAO::executeQuery($sql);

  try {
    $secondDegreePerms = civicrm_api3('setting', 'getvalue', array('version' => 3, 'name' => 'secondDegRelPermissions', 'group' => 'core'));
  } catch (Exception $e) {
    $secondDegreePerms = 0;
  }

  if ($secondDegreePerms) {
    $continue = 1;
    while ($continue > 0) {
      calculateInheritedPermissions($tmpTableSecondaryContacts, $tmpTableName, $now);

      $newPotentialPermissionInheritingContacts = CRM_Core_DAO::singleValueQuery("
        SELECT count(*) FROM $tmpTableSecondaryContacts s
        LEFT JOIN $tmpTableName m 
        ON s.contact_id = m.contact_id
        
        WHERE m.contact_id IS NULL
      ");

      $sql = "
        REPLACE INTO $tmpTableName
        SELECT contact_id FROM $tmpTableSecondaryContacts
      ";

      CRM_Core_DAO::executeQuery($sql);
      //keep going as long as we are adding
      //new contacts to our table
      $continue = $newPotentialPermissionInheritingContacts;
    }
  }

  return $tmpTableName;
}

function relatedpermissions_civicrm_buildForm($formName, &$form)
{
  if ($formName == 'CRM_Contact_Form_Relationship') {
    $form->addField('is_permission_a_b_v');
    $form->addField('is_permission_b_a_v');

    $defaults['is_permission_a_b_v'] = CRM_Utils_Array::value('is_permission_' . $_GET['rtype'] . '_v', $form->_values);
    $defaults['is_permission_b_a_v'] = CRM_Utils_Array::value('is_permission_' . strrev($_GET['rtype']) . '_v', $form->_values);

    $form->setDefaults($defaults);
  }
}

/**
 * Set permissions if required
 */
function relatedpermissions_civicrm_pre($op, $entity, $objectID, &$entityArray)
{
  if ($entity != 'Relationship' || $op == 'delete' || empty($entityArray['relationship_type_id'])) {
    return;
  }

  $relationshipType = explode('_', $entityArray['relationship_type_id']);

  if (_relatedpermissions_is_permission($relationshipType[0], 'a_b')) {
    $entityArray['is_permission_a_b'] = true;
  }
  if (_relatedpermissions_is_permission($relationshipType[0], 'b_a')) {
    $entityArray['is_permission_b_a'] = true;
  }

  if ($op == 'edit' || $op == 'create') {
    $store = NULL;
    $entityArray['is_permission_a_b_v'] = CRM_Utils_Request::retrieve('is_permission_a_b_v', 'Boolean', $store, FALSE, 0, 'POST');
    $entityArray['is_permission_b_a_v'] = CRM_Utils_Request::retrieve('is_permission_b_a_v', 'Boolean', $store, FALSE, 0, 'POST');
    if(empty($entityArray['is_permission_a_b_v'])) {
      $entityArray['is_permission_a_b_v'] = 0;
    }
    if(empty($entityArray['is_permission_b_a_v'])) {
      $entityArray['is_permission_b_a_v'] = 0;
    }
  }
}
