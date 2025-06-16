<?php

declare(strict_types=1);

namespace Drupal\scd_riparian\Plugin\QuickForm;

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
use Drupal\user\UserInterface;
use Psr\Container\ContainerInterface;

/**
 * Quick form base class.
 */
class RiparianMaintenanceBase extends QuickFormBase implements ConfigurableQuickFormInterface {

  use ConfigurableQuickFormTrait;
  use QuickLogTrait;
  use QuickFormElementsTrait;
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
    ];

    $form['asset_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'asset-wrapper',
      ],
    ];

    // Add sub-site asset selection.
    if (($parent = $form_state->getValue('parent')) && is_numeric($parent)) {
      $options = $this->getSubSiteOptions((int) $parent);
      $form['asset_wrapper']['asset'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Sub-sites'),
        '#description' => $this->t('Select sub-sites of the selected site.'),
        '#options' => $options,
        '#default_value' => array_keys($options),
        '#required' => TRUE,
      ];
    }

    $crew_lead_options = $this->getUserOptions(['farm_manager', 'farm_worker']);
    $form['owner'] = [
      '#type' => 'select',
      '#title' => $this->t('Crew lead'),
      '#options' => $crew_lead_options,
      '#required' => TRUE,
    ];

    $form['schedule'] = [
      '#type' => 'radios',
      '#title' => $this->t('Scheduling'),
      '#options' => [
        'schedule' => "Schedule $this->maintenanceLabel",
        'record' => "Record single $this->maintenanceLabel",
      ],
      '#default_value' => 'schedule',
    ];

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
      '#type' => 'date',
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

    $form['record_data']['done'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Completed'),
      '#default_value' => TRUE,
    ];

    $form['record_data']['time'] = $this->buildInlineContainer();
    $form['record_data']['time']['timestamp'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Date'),
      '#default_value' => new DrupalDateTime('midnight', $this->currentUser->getTimeZone()),
    ];

    $form['record_data']['time']['time_taken'] = [
      '#type' => 'number',
      '#title' => $this->t('Time taken (hours)'),
      '#default_value' => 0,
      '#min' => 0,
      '#step' => 0.5,
      '#lenth' => 5,
    ];

    $form['record_data']['site'] = $this->buildInlineContainer();

    $form['record_data']['site']['number_technicians'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of technicians'),
      '#default_value' => 0,
      '#min' => 0,
      '#step' => 1,
      '#length' => 5,
    ];

    $form['record_data']['site']['percent_of_site'] = [
      '#type' => 'number',
      '#title' => $this->t('Percent of site'),
      '#default_value' => 100,
      '#min' => 0,
      '#step' => 5,
      '#length' => 5,
    ];

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
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    if ($form_state->getValue('schedule') == 'schedule') {
      // not implemented
    }

    if ($form_state->getValue('schedule') == 'record') {
      $log = $this->prepareLog($form, $form_state);
      $this->createLog($log);
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
  protected function prepareLog(array $form, FormStateInterface $form_state): array {

    // Start an array of log data to pass to QuickLogTrait::createLog.
    $log = [
      'type' => $this->logType,
      'status' => 'pending',
      'name' => $form_state,
      'location' => Checkboxes::getCheckedCheckboxes($form_state->getValue('asset')),
      'owner' => $form_state->getValue('owner'),
      'category' => $this->configuration['log_category'] ?? NULL,
      'quantity' => [],
    ];

    $parent_id = $form_state->getValue('parent');
    if ($parent = $this->entityTypeManager->getStorage('asset')->load($parent_id)) {
      $log['name'] = "{$parent->label()} $this->maintenanceLabel";
    }

    if ($form_state->getValue('schedule') == 'record') {
      $log['status'] = $form_state->getValue('done') ? 'done' : 'pending';
      $log['timestamp'] = $form_state->getValue('timestamp')->getTimestamp();
      $log['notes'] = $form_state->getValue('notes');

      // Prepare quantities.
      $log['quantity'][] = [
        'type' => 'standard',
        'label' => 'Time taken',
        'value' => $form_state->getValue('time_taken'),
        'measure' => 'time',
        'units' => $this->createOrLoadTerm('hours', 'unit'),
      ];
      $log['quantity'][] = [
        'type' => 'standard',
        'label' => 'Percent of Site',
        'value' => $form_state->getValue('percent_of_site'),
        'measure' => 'ratio',
        'units' => $this->createOrLoadTerm('%', 'unit'),
      ];
      $log['quantity'][] = [
        'type' => 'standard',
        'label' => 'Number of Technicians',
        'value' => $form_state->getValue('number_technicians'),
        'measure' => 'count',
      ];
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
