<?php
/**
 * @file
 * Single email interface.
 */

/**
 * An interface to send letters to editors of NH newspapers.
 *
 * @extends CRM_Petitionemail_Interface
 */
class CRM_Petitionemail_Interface_Statelegemail extends CRM_Petitionemail_Interface {

  /**
   * Fields needed to form address for lookup.
   *
   * @type array
   */
  private $addressFields = array(
    'Street_Address_Field',
    'City_Field',
    'State_Province_Field',
    'Postal_Code_Field',
  );

  /**
   * Sunlight API Key.
   *
   * @type string
   */
  private $apiKey = NULL;

  /**
   * Instantiate the delivery interface.
   *
   * @param int $surveyId
   *   The ID of the petition.
   */
  public function __construct($surveyId) {
    parent::__construct($surveyId);

    $this->neededFields[] = 'Subject';
    $this->neededFields = array_merge($this->neededFields, $this->addressFields);

    $fields = $this->findFields();
    $petitionemailval = $this->getFieldsData($surveyId);

    foreach ($this->neededFields as $neededField) {
      if (empty($fields[$neededField]) || empty($petitionemailval[$fields[$neededField]])) {
        // TODO: provide something more meaningful.
        return;
      }
    }
    // If all needed fields are found, the system is no longer incomplete.
    $this->isIncomplete = FALSE;
  }

  /**
   * Take the signature form and send an email to the recipient.
   *
   * @param CRM_Campaign_Form_Petition_Signature $form
   *   The petition form.
   */
  public function processSignature($form) {
    // Get the message.
    $messageField = $this->findMessageField();
    if ($messageField === FALSE) {
      return;
    }
    $message = empty($form->_submitValues[$messageField]) ? $this->petitionEmailVal[$this->fields['Default_Message']] : $form->_submitValues[$messageField];
    // If message is left empty and no default message, don't send anything.
    if (empty($message)) {
      return;
    }

    // Get the address information of the signer.
    $addressFields = $this->findAddressFields();
    $addressValues = array_fill_keys($this->addressFields, '');
    foreach ($this->addressFields as $fieldName) {
      if (empty($addressFields[$fieldName])) {
        continue;
      }
      $addressValues[$fieldName] = CRM_Utils_Array::value($addressFields[$fieldName], $form->_submitValues, '');
    }
    $recipients = self::findRecipients($addressValues);

    foreach ($recipients as $recipient) {
      // Setup email message:
      $mailParams = array(
        'groupName' => 'Activity Email Sender',
        'from' => $this->getSenderLine($form->_contactId),
        'toName' => empty($recipient['recipient']) ? $recipient['name'] : $recipient['toname'],
        'toEmail' => $recipient['email'],
        'subject' => $this->petitionEmailVal[$this->fields['Subject']],
        // 'cc' => $cc, TODO: offer option to CC.
        // 'bcc' => $bcc,
        'text' => $message,
        // 'html' => $html_message, TODO: offer HTML option.
      );

      if (!CRM_Utils_Mail::send($mailParams)) {
        CRM_Core_Session::setStatus(ts('Error sending message to %1', array('domain' => 'com.aghstrategies.petitionemail', 1 => $mailParams['toName'])));
      }
      else {
        CRM_Core_Session::setStatus(ts('Message sent successfully to %1', array('domain' => 'com.aghstrategies.petitionemail', 1 => $mailParams['toName'])));
      }
    }
    parent::processSignature($form);
  }

  /**
   * Prepare the signature form with the default message.
   *
   * @param CRM_Campaign_Form_Petition_Signature $form
   *   The petition form.
   */
  public function buildSigForm($form) {

    $defaults = $form->getVar('_defaults');

    $messageField = $this->findMessageField();
    if ($messageField === FALSE) {
      return;
    }

    if (!empty($this->petitionEmailVal[$this->fields['Default_Message']])) {
      $defaultMessage = $this->petitionEmailVal[$this->fields['Default_Message']];
      foreach ($form->_elements as $element) {
        if ($element->_attributes['name'] == $messageField && empty($element->_value)) {
          $element->_value = $defaultMessage;
        }
      }
      $defaults[$messageField] = $form->_defaultValues[$messageField] = $defaultMessage;
      $form->setVar('_defaults', $defaults);
    }

    $addressFields = $this->findAddressFields();
    $jsVars = array_merge(array_fill_keys($this->addressFields, NULL), $addressFields);
    $jsVars['message'] = $messageField;

    CRM_Core_Resources::singleton()->addScriptFile('com.aghstrategies.statelegemail', 'js/sigform.js')
      ->addVars('statelegemail', $jsVars);
  }

  /**
   * Find the field containing the postal code.
   *
   * @return string
   *   The field name (e.g. "custom_4") or FALSE if none found.
   */
  public function findAddressFields() {
    $return = array();
    foreach ($this->addressFields as $fieldName) {
      $ufField = CRM_Utils_Array::value($this->fields[$fieldName], $this->petitionEmailVal);
      try {
        $field = civicrm_api3('UFField', 'getsingle', array(
          'return' => array(
            'field_name',
            'location_type_id',
          ),
          'id' => $ufField,
        ));
        $return[$fieldName] = empty($field['location_type_id']) ? "{$field['field_name']}-Primary" : "{$field['field_name']}-{$field['location_type_id']}";
      }
      catch (CiviCRM_API3_Exception $e) {
        $error = $e->getMessage();
        CRM_Core_Error::debug_log_message(t('API Error: %1', array(1 => $error, 'domain' => 'com.aghstrategies.statelegemail')));
      }
    }
    return $return;
  }

  /**
   * Find the recipients based upon postal code.
   *
   * @param array $addressValues
   *   Address parts in an array with the keys:
   *   - State_Province_Field,
   *   - City_Field,
   *   - Street_Address_Field, and
   *   - Postal_Code_Field.
   *
   * @return array
   *   The matching recipients in an array with the keys:
   *   - email,
   *   - photourl, and
   *   - name.
   */
  public static function findRecipients($addressValues) {
    // Get api key setting.
    $apiKey = self::getApiKey();
    if (empty($apiKey)) {
      // TODO: provide some better notice.
      return array();
    }

    $stateConfig = self::getStateConfig($addressValues['State_Province_Field']);
    if (empty($stateConfig)) {
      return array();
    }

    $config = CRM_Core_Config::singleton();
    $class = $config->geocodeMethod;
    if (empty($class)) {
      // No geocode method set.
      // TODO: give a notice that this is important.
      return array();
    }

    // Fix postal code to be precisely five digits, handling zeros.
    if (is_int($addressValues['Postal_Code_Field'])) {
      $postalCode = $addressValues['Postal_Code_Field'];
    }
    else {
      $postalCode = intval(array_shift(explode('-', $addressValues['Postal_Code_Field'])));
    }
    $postalCode = str_pad("{$addressValues['Postal_Code_Field']}", 5, "0", STR_PAD_LEFT);

    $params = array(
      // Country must be United States for the API to work.
      'country' => 'United States',
      'street_address' => $addressValues['Street_Address_Field'],
      'city' => $addressValues['City_Field'],
      'state_province_id' => $addressValues['State_Province_Field'],
      'postal_code' => $addressValues['Postal_Code_Field'],
    );
    $success = $class::format($params);
    if (!$success || empty($params['geo_code_1']) || empty($params['geo_code_2'])) {
      return array();
    }

    // Now that we have the lat/long, look up the params.
    $query = "http://openstates.org/api/v1/legislators/geo/?lat={$params['geo_code_1']}&long={$params['geo_code_2']}&apikey={$apiKey}";
    require_once 'HTTP/Request.php';
    $request = new HTTP_Request($query);
    $request->sendRequest();
    $string = $request->getResponseBody();
    $legislators = json_decode($string, TRUE);

    $return = array();
    foreach ($legislators as $result) {
      if (empty($result['email']) || empty($result['full_name'])) {
        continue;
      }
      if (!empty($result['state']) && !empty($result['chamber'])) {
        if (empty($stateConfig['titles'][$result['chamber']])) {
          $displayName = $result['full_name'];
        }
        else {
          $displayName = "{$stateConfig['titles'][$result['chamber']]} {$result['full_name']}";
        }
      }
      $return[] = array(
        'email' => $result['email'],
        'photourl' => CRM_Utils_Array::value('photo_url', $result),
        'name' => $displayName,
      );
    }

    return $return;
  }

  /**
   * Get the state configuration.
   *
   * @param int $stateProvinceId
   *   The state/province ID from CiviCRM.
   *
   * @return array
   *   The configuration from Sunlight.
   */
  private static function getStateConfig($stateProvinceId) {
    $stateProvinceId = intval($stateProvinceId);

    // Find the state abbreviation from ID.
    try {
      $states = civicrm_api3('Address', 'getoptions', array(
        'field' => "state_province_id",
        'country_id' => 1228,
        'context' => "abbreviate",
      ));
      if (empty($states['values'][$stateProvinceId])) {
        return FALSE;
      }
      $state = strtolower($states['values'][$stateProvinceId]);
    }
    catch (CiviCRM_API3_Exception $e) {
      print_r($e);
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(t('API Error: %1', array(1 => $error, 'domain' => 'com.aghstrategies.statelegemail')));
    }

    // Find the config.
    try {
      $stateConfig = civicrm_api3('Setting', 'getvalue', array(
        'name' => 'statelegemail_stateconfig',
        'group' => 'State Legislators Email Preferences',
      ));
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(t('API Error: %1', array(1 => $error, 'domain' => 'com.aghstrategies.statelegemail')));
    }

    if (empty($stateConfig[$state])) {
      // Need to go look it up. First, Get api key setting.
      $apiKey = self::getApiKey();
      if (empty($apiKey)) {
        return FALSE;
      }

      $query = "http://openstates.org/api/v1/metadata/{$state}/?apikey={$apiKey}";
      require_once 'HTTP/Request.php';
      $request = new HTTP_Request($query);
      $request->sendRequest();
      $string = $request->getResponseBody();
      $stateInfo = json_decode($string, TRUE);

      // Go through state info and set titles.
      if (empty($stateInfo['chambers'])) {
        return FALSE;
      }
      $stateConfig[$state] = array(
        'titles' => array(),
      );
      foreach ($stateInfo['chambers'] as $chamber => $chamberInfo) {
        if (empty($chamberInfo['title'])) {
          continue;
        }
        $stateConfig[$state]['titles'][$chamber] = $chamberInfo['title'];
      }

      try {
        $result = civicrm_api3('Setting', 'create', array('statelegemail_stateconfig' => $stateConfig));
      }
      catch (CiviCRM_API3_Exception $e) {
        $error = $e->getMessage();
        CRM_Core_Error::debug_log_message(t('API Error: %1', array(1 => $error, 'domain' => 'com.aghstrategies.statelegemail')));
      }
    }

    return $stateConfig[$state];
  }

  /**
   * Get the Sunlight API Key.
   *
   * @return string
   *   The key.
   */
  private function getApiKey() {
    try {
      return civicrm_api3('Setting', 'getvalue', array(
        'name' => 'statelegemail_key',
        'group' => 'State Legislators Email Preferences',
      ));
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(t('API Error: %1', array(1 => $error, 'domain' => 'com.aghstrategies.statelegemail')));
    }
  }

}
