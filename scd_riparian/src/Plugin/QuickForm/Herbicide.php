<?php

declare(strict_types=1);

namespace Drupal\scd_riparian\Plugin\QuickForm;

use Drupal\Core\Form\FormStateInterface;

/**
 * @QuickForm(
 *   id = "riparian_herbicide",
 *   label = @Translation("Herbicide"),
 *   description = @Translation("Record riparian herbicide activities."),
 *   helpText = @Translation("Use this form to record riparian herbicide activities."),
 *   permissions = {},
 * )
 */
class Herbicide extends RiparianMaintenanceBase {

  /**
   * {@inheritdoc}
   */
  protected string $logType = 'input';

  /**
   * {@inheritdoc}
   */
  protected string $maintenanceLabel = 'herbicide';

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $id = NULL) {
    $form = parent::buildForm($form, $form_state, $id);

    $form['record_data']['weather'] = $this->buildInlineContainer();
    $form['record_data']['weather']['air_temp'] = [
      '#type' => 'number',
      '#title' => $this->t('Air temperature'),
      '#step' => 1,
    ];
    $form['record_data']['weather']['wind_speed'] = [
      '#type' => 'number',
      '#title' => $this->t('Wind speed (mph)'),
      '#step' => 1,
    ];
    $wind_directions = [
      $this->t('North'),
      $this->t('South'),
      $this->t('East'),
      $this->t('West'),
      $this->t('North East'),
      $this->t('North West'),
      $this->t('South East'),
      $this->t('South West'),
    ];
    $wind_direction_options = array_combine($wind_directions, $wind_directions);
    $form['record_data']['weather']['wind_direction'] = [
      '#type' => 'select',
      '#title' => $this->t('Wind direction'),
      '#options' => $wind_direction_options,
      '#required' => TRUE,
    ];

    $material_types = $this->entityTypeManager->getStorage('taxonomy_term')->loadTree('material_type');
    $material_type_options = [];
    foreach ($material_types as $term) {
      $material_type_options[$term->tid] = $term->name;
    }
    $form['record_data']['product'] = $this->buildInlineContainer();
    $form['record_data']['product']['material'] = [
      '#type' => 'select',
      '#title' => $this->t('Herbicide product'),
      '#options' => $material_type_options,
    ];
    $form['record_data']['product']['total_applied'] = [
      '#type' => 'number',
      '#title' => $this->t('Total product applied (qts)'),
      '#min' => 0,
      '#step' => 0.1,
    ];
    $form['record_data']['product']['concentration'] = [
      '#type' => 'number',
      '#title' => $this->t('Product concentration (oz/gal)'),
      '#min' => 0,
      '#step' => 0.1,
    ];

    $form['record_data']['acres'] = $this->buildInlineContainer();
    $form['record_data']['acres']['acres_treated'] = [
      '#type' => 'number',
      '#title' => $this->t('Acres treated'),
      '#step' => 0.1,
    ];
    $form['record_data']['acres']['rate_per_acre'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate per acre'),
      '#min' => 0,
      '#step' => 0.1,
    ];

    $form['record_data']['acres']['rate_per_acre_unit'] = [
      '#type' => 'select',
      '#title' => $this->t('Rate units'),
      '#options' => [
        'ml/acre' => 'ml/acre',
        'qts/acre' => 'qts/acre',
        'gal/acre' => 'gal/acre',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareLog(array $form, FormStateInterface $form_state): array {
    $log = parent::prepareLog($form, $form_state);

    // Add data to individual record logs.
    if ($form_state->getValue('schedule') == 'record') {
      $log['quantity'][] = [
        'type' => 'standard',
        'label' => 'Air temperature (F)',
        'value' => $form_state->getValue('air_temp'),
        'measure' => 'temperature',
        'units' => $this->createOrLoadTerm('fahrenheit', 'unit'),
      ];
      $log['quantity'][] = [
        'type' => 'standard',
        'label' => 'Wind speed',
        'value' => $form_state->getValue('wind_speed'),
        'measure' => 'speed',
        'units' => $this->createOrLoadTerm('mph', 'unit'),
      ];

      // Save wind direction to notes.
      $old_notes = $log['notes'] ?? '';
      $log['notes'] = "Wind direction: {$form_state->getValue('wind_direction')}\n\n$old_notes";

      // Save material term for material quantities.
      $material_id = $form_state->getValue('material');
      $material = $this->entityTypeManager->getStorage('taxonomy_term')->load($material_id);

      // Product quantities.
      $log['quantity'][] = [
        'type' => 'material',
        'label' => 'Total product applied',
        'value' => $form_state->getValue('total_product_applied'),
        'units' => $this->createOrLoadTerm('qts', 'unit'),
        'material_type' => $material,
      ];
      $log['quantity'][] = [
        'type' => 'material',
        'label' => 'Product concentration',
        'value' => $form_state->getValue('total_product_applied'),
        'measure' => 'ratio',
        'units' => $this->createOrLoadTerm('oz/gal', 'unit'),
        'material_type' => $material,
      ];

      // Acre quantities.
      $log['quantity'][] = [
        'type' => 'material',
        'label' => 'Acres treated',
        'value' => $form_state->getValue('acres_treated'),
        'measure' => 'area',
        'units' => $this->createOrLoadTerm('acres', 'unit'),
        'material_type' => $material,
      ];
      $rate_unit_label = $form_state->getValue('rate_per_acre_unit');
      $rate_unit = $this->createOrLoadTerm($rate_unit_label, 'unit');
      $log['quantity'][] = [
        'type' => 'material',
        'label' => 'Rate per acre',
        'value' => $form_state->getValue('acres_treated'),
        'measure' => 'rate',
        'units' => $rate_unit,
        'material_type' => $material,
      ];
    }

    return $log;
  }

}
