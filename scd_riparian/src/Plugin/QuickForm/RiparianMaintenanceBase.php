<?php

declare(strict_types=1);

namespace Drupal\scd_riparian\Plugin\QuickForm;

use Drupal\asset\Entity\AssetInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\Element\Checkboxes;
use Drupal\Core\Session\AccountInterface;
use Drupal\farm_quick\Plugin\QuickForm\ConfigurableQuickFormInterface;
use Drupal\farm_quick\Plugin\QuickForm\QuickFormBase;
use Drupal\farm_quick\Traits\ConfigurableQuickFormTrait;
use Drupal\farm_quick\Traits\QuickFormElementsTrait;
use Drupal\farm_quick\Traits\QuickLogTrait;
use Drupal\farm_quick\Traits\QuickTermTrait;
use Drupal\log\Entity\Log;
use Drupal\log\Entity\LogInterface;
use Drupal\scd_riparian\Traits\QuickQuantityFieldTrait;
use Drupal\user\UserInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Quick form base class.
 */
class RiparianMaintenanceBase extends QuickFormBase implements ConfigurableQuickFormInterface {

  use ConfigurableQuickFormTrait;
  use QuickFormElementsTrait;
  use QuickLogTrait;
  use QuickQuantityFieldTrait;
  use QuickTermTrait;

  /**
   * The log type.
   *
   * @var string
   */
  protected string $logType;

  /**
   * The maintenance label.
   *
   * @var string
   */
  protected string $maintenanceLabel;

  /**
   * Default values to use when initializing the form.
   *
   * @var array
   */
  protected $defaultValues = [];

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MessengerInterface $messenger,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $messenger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('messenger'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account) {

    // Check to ensure the user has permission to create the configured log type
    // and view the configured asset.
    $result = AccessResult::allowedIf($this->entityTypeManager->getAccessControlHandler('log')->createAccess($this->logType, $account));
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'log_category' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $id = NULL) {

    $this->buildDefaults(\Drupal::request());;
    $modify_existing_log = $this->defaultValues['log'] ?? NULL instanceof LogInterface;

    $form['parent'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Site name'),
      '#target_type' => 'asset',
      '#selection_handler' => 'views',
      '#selection_settings' => [
        'view' => [
          'view_name' => 'scd_riparian_sites',
          'display_name' => 'entity_reference',
        ],
        'match_operator' => 'CONTAINS',
        'match_limit' => 10,
      ],
      '#maxlength' => 1024,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => [$this, 'assetCallback'],
        'wrapper' => 'asset-wrapper',
        'event' => 'autocompleteclose change',
      ],
      '#default_value' => $this->defaultValues['parent'] ?? NULL,
    ];

    // Set parent entity ID.
    if ($modify_existing_log && isset($this->defaultValues['parent'])) {
      $form_state->setValue('parent', $this->defaultValues['parent']->id());
    }

    $form['asset_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'asset-wrapper',
      ],
    ];

    // Add sub-site asset selection.
    if (($parent = $form_state->getValue('parent')) && is_numeric($parent)) {

      // Build options and default values.
      $options = $this->getSubSiteOptions((int) $parent);
      $default_values = array_keys($options);
      if ($modify_existing_log) {
        $default_values = array_map(function (AssetInterface $asset) {
          return $asset->id();
        }, $this->defaultValues['location']);
      }

      $form['asset_wrapper']['location'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Sub-sites'),
        '#description' => $this->t('Select sub-sites of the selected site.'),
        '#options' => $options,
        '#default_value' => $default_values,
        '#required' => TRUE,
      ];
    }

    $crew_lead_options = $this->getUserOptions(['farm_manager', 'farm_worker']);
    $form['owner'] = [
      '#type' => 'select',
      '#title' => $this->t('Crew lead'),
      '#options' => $crew_lead_options,
      '#required' => TRUE,
      '#default_value' => $modify_existing_log ? $this->defaultValues['owner'] : NULL,
    ];

    $form['schedule'] = [
      '#type' => 'hidden',
      '#title' => $this->t('Scheduling'),
      '#options' => [
        'schedule' => "Schedule $this->maintenanceLabel",
        'record' => "Record single $this->maintenanceLabel",
      ],
      '#default_value' => 'schedule',
      '#value' => 'record',
    ];

    // Expose scheduling information if not modifying an existing log.
    if (!$modify_existing_log) {

      $form['schedule']['#type'] = 'radios';
      unset($form['schedule']['#value']);

      $form['schedule_data'] = [
        '#type' => 'details',
        '#title' => $this->t('Scheduling'),
        '#tree' => TRUE,
        '#open' => TRUE,
        '#states' => [
          'visible' => [
            ':input[name="schedule"]' => ['value' => 'schedule'],
          ],
        ],
      ];

      $form['schedule_data']['date'] = $this->buildInlineContainer();

      $form['schedule_data']['date']['start_date'] = [
        '#type' => 'datetime',
        '#title' => $this->t('Start'),
        '#default_value' => new DrupalDateTime('today 12:00', $this->currentUser->getTimeZone()),
      ];

      $form['schedule_data']['date']['end_date'] = [
        '#type' => 'datetime',
        '#title' => $this->t('End'),
        '#default_value' => new DrupalDateTime('today 12:00', $this->currentUser->getTimeZone()),
      ];

      $form['schedule_data']['week_interval'] = [
        '#type' => 'number',
        '#title' => $this->t('Weeks'),
        '#description' => "Number of weeks to schedule between each $this->maintenanceLabel activity.",
        '#min' => 1,
        '#default_value' => 2,
      ];
    }

    $form['record_data'] = [
      '#type' => 'details',
      '#title' => $this->t('Details'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="schedule"]' => ['value' => 'record'],
        ],
      ],
    ];

    // Save existing log ID if modifying existing.
    if ($modify_existing_log) {
      $form['record_data']['log_id'] = [
        '#type' => 'hidden',
        '#value' => $this->defaultValues['log']->id(),
      ];
    }

    $form['record_data']['done'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Completed'),
      '#default_value' => TRUE,
    ];

    $form['record_data']['time'] = $this->buildInlineContainer();
    $default_time = $modify_existing_log ? $this->defaultValues['timestamp'] : new DrupalDateTime('now', $this->currentUser->getTimeZone());
    $form['record_data']['time']['timestamp'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Date'),
      '#default_value' => $default_time,
    ];

    $form['record_data']['time']['time_taken'] = $this->buildQuantityField([
      'title' => $this->t('Time taken'),
      'measure' => ['#value' => 'time'],
      'units' => ['#value' => 'hours'],
      'value' => ['#min' => 0, '#step' => 0.25],
    ]);

    $form['record_data']['site'] = $this->buildInlineContainer();

    $form['record_data']['site']['number_of_technicians'] = $this->buildQuantityField([
      'title' => $this->t('Number of technicians'),
      'measure' => ['#value' => 'count'],
      'units' => ['#type' => 'hidden'],
      'value' => ['#min' => 0, '#step' => 1, '#default_value' => 1],
    ]);

    $form['record_data']['site']['percent_of_site'] = $this->buildQuantityField([
      'title' => $this->t('Percent of site'),
      'measure' => ['#value' => 'ratio'],
      'units' => ['#value' => '%'],
      'value' => ['#min' => 0, '#step' => 5, '#default_value' => 100],
    ]);

    $form['record_data']['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes'),
      '#weight' => 50,
    ];

    return $form;
  }

  /**
   * Asset ajax callback.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The products render array.
   */
  public function assetCallback(array &$form, FormStateInterface $form_state) {
    return $form['asset_wrapper'];
  }

  /**
   * Helper function to load sub-site options for a given parent.
   *
   * @param int $parent_id
   *   The parent asset ID.
   *
   * @return array
   *   Return array of options.
   */
  protected function getSubSiteOptions(int $parent_id): array {
    $asset_ids = $this->entityTypeManager->getStorage('asset')->getQuery()
      ->accessCheck()
      ->condition('status', 'archived', '!=')
      ->condition('parent', $parent_id)
      ->condition('type', 'land')
      ->condition('land_type', 'site')
      ->sort('id')
      ->execute();
    $sites = $this->entityTypeManager->getStorage('asset')->loadMultiple($asset_ids);
    $options = [];
    foreach ($sites as $site) {
      $options[$site->id()] = $site->label();
    }
    return $options;
  }

  /**
   * Helper function to build a sorted option list of users in role(s).
   *
   * @param array $roles
   *   Limit to users of the specified roles.
   *
   * @return array
   *   An array of user labels indexed by user id and sorted alphabetically.
   */
  protected function getUserOptions(array $roles = []): array {

    // Query active, non-admin users.
    $query = $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->condition('uid', '1', '>');

    // Limit to specified roles.
    if (!empty($roles)) {
      $query->condition('roles', $roles, 'IN');
    }

    // Load users.
    $user_ids = $query->execute();
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple($user_ids);

    // Build user options.
    $user_options = array_map(function (UserInterface $user) {
      return $user->label();
    }, $users);
    natsort($user_options);

    return $user_options;
  }

  /**
   * Helper function to build form defaults.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   */
  protected function buildDefaults(Request $request) {

    // Build common defaults if a log is provided.
    if ($log_id = $request->get('log')) {

      $log = $this->entityTypeManager->getStorage('log')->load($log_id);
      if (!$log) {
        $this->messenger->addError($this->t('Invalid log provided.'));
        return;
      }
      if ($log->get('status')->value == 'done') {
        $this->messenger->addError($this->t('This log has already been completed.'));
        return;
      }

      // Save the log.
      $this->defaultValues['log'] = $log;

      // Timestamp.
      $timestamp = $log->get('timestamp')->value;
      $this->defaultValues['timestamp'] = new DrupalDateTime("@$timestamp", $this->currentUser->getTimeZone());

      // Owner.
      $this->defaultValues['owner'] = $log->get('owner')->target_id;

      // Locations.
      $this->defaultValues['location'] = $log->get('location')->referencedEntities();

      // Get the first site parent.
      $site_parent = NULL;
      foreach ($this->defaultValues['location'] as $location) {
        if ($location->bundle() == 'land' && $location->get('land_type')->value == 'site') {
          if (!$location->get('parent')->isEmpty()) {
            $parents = $location->get('parent')->referencedEntities();
            $site_parent = reset($parents);
          }
        }
      }
      $this->defaultValues['parent'] = $site_parent ?: NULL;

      // Notes.
      $this->defaultValues['notes'] = [];
      if (($notes = $log->get('notes')->value) && $lines = explode(PHP_EOL, $notes)) {
        foreach ($lines as $line) {
          if (($parts = explode(':', $line)) && count($parts) == 2) {
            $this->defaultValues['notes'][$parts[0]] = trim($parts[1]);
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $existing_log_id = $form_state->getValue('log_id');

    if ($form_state->getValue('schedule') == 'schedule') {

      // Extract scheduling parameters
      $start_date = $form_state->getValue('schedule_data')['date']['start_date'];
      $end_date = $form_state->getValue('schedule_data')['date']['end_date'];
      $week_interval = $form_state->getValue('schedule_data')['week_interval'];

      // Validate dates
      if (!$start_date instanceof DrupalDateTime || !$end_date instanceof DrupalDateTime) {
        $this->messenger->addError($this->t('Invalid date selection.'));
        return;
      }

      // Convert to timestamp for easier manipulation
      $current_date = $start_date->getTimestamp();
      $end_timestamp = $end_date->getTimestamp();

      // Create logs for each sub-site at specified intervals
      $logs_created = 0;
      while ($current_date <= $end_timestamp) {

        // Create the log.
        $log = $this->prepareLog(FALSE, $form, $form_state);
        $log['timestamp'] = $current_date;
        $this->createLog($log);

        // Move to next interval.
        $logs_created++;
        $current_date = strtotime("+{$week_interval} weeks", $current_date);
      }

      // Provide feedback
      $this->messenger->addStatus($this->t('Created @count scheduled maintenance logs.', ['@count' => $logs_created]));
    }

    if ($form_state->getValue('schedule') == 'record') {
      $log_data = $this->prepareLog((bool) $existing_log_id, $form, $form_state);

      // Update existing log.
      if ($existing_log_id) {
        $existing_log = Log::load($existing_log_id);
        foreach ($log_data as $field => $value) {
          switch ($field) {
            case 'quantity':
              foreach ($value as $quantity_data) {
                $quantity = $this->createQuantity($quantity_data);
                $existing_log->get('quantity')->appendItem($quantity);
              }
              break;
            default:
              $existing_log->set($field, $value);
              break;
          }
        }

        // Display a message with a link to the log.
        $existing_log->save();
        $message = $this->t('Log saved: <a href=":url">@name</a>', [':url' => $existing_log->toUrl()->toString(), '@name' => $existing_log->label()]);
        $this->messenger->addStatus($message);
      }
      else {

        // Else create a new log.
        $this->createLog($log_data);
      }

    }

  }

  /**
   * Helper function to prepare an array of data for creating a log.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   An array of log data.
   *
   * @see \Drupal\farm_quick\Traits\QuickLogTrait::createLog()
   */
  protected function prepareLog(bool $modify_existing, array $form, FormStateInterface $form_state): array {

    // Start an array of log data to pass to QuickLogTrait::createLog.
    $log = [
      'location' => Checkboxes::getCheckedCheckboxes($form_state->getValue('location')),
      'owner' => $form_state->getValue('owner'),
      'quantity' => [],
    ];

    // Add additional values if creating a new log.
    if (!$modify_existing) {
      $log += [
        'type' => $this->logType,
        'status' => 'pending',
        'name' => $form_state,
        'category' => $this->configuration['log_category'] ?? NULL,
        'revision_log_message' => 'Scheduled by ' . $this->currentUser->getAccountName(),
        'revision_user' => $this->currentUser->id(),
      ];

      $parent_id = $form_state->getValue('parent');
      if ($parent = $this->entityTypeManager->getStorage('asset')->load($parent_id)) {
        $log['name'] = "{$parent->label()} $this->maintenanceLabel";
      }
    }

    if ($form_state->getValue('schedule') == 'record') {
      $log['status'] = $form_state->getValue('done') ? 'done' : 'pending';
      $log['timestamp'] = $form_state->getValue('timestamp')->getTimestamp();
      $log['notes'] = $form_state->getValue('notes');

      // Prepare quantities.
      $log['quantity'][] = $form_state->getValue('time_taken');
      $log['quantity'][] = $form_state->getValue('number_of_technicians');
      $log['quantity'][] = $form_state->getValue('percent_of_site');;
    }

    return $log;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {

    $form['log_category'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Log category'),
      '#target_type' => 'taxonomy_term',
      '#selection_settings' => [
        'target_bundles' => ['log_category'],
      ],
    ];
    if (!empty($this->configuration['log_category']) && $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($this->configuration['log_category'])) {
      $form['log_category']['#default_value'] = $term;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['log_category'] = NULL;
    // Existing terms will be represented as a numeric term ID.
    if (!empty($form_state->getValue('log_category'))) {
      if (($term_id = $form_state->getValue('log_category')) && is_numeric($term_id)) {
        $this->configuration['log_category'] = (int) $term_id;
      }
    }
  }

}
